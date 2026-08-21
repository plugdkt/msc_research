<?php
// admin/expert_finder_llm.php
// Phase 10 (SEM-05/06): LLM-ranked expert finder. Accepts a free-text
// question (Thai or English), sends it plus every classified publication's
// semantic_tags (from admin/classify_sdgs_llm.php, SEM-02) to UP AI Connect
// in ONE chat-completion call, then looks up the actual researchers linked
// to whichever publication IDs the model returned - never trusting a
// researcher name the model might mention directly. This is what makes it
// safe to demo live: the model only ranks/points at real records, it never
// generates a free-text answer that could be wrong.
//
// action=search results are cached by exact query text (expert_finder_cache
// table) - an exact repeat of a rehearsed demo query is served instantly
// with no UP AI Connect call, so the live presentation never depends on an
// uncached request succeeding on stage (SEM-06).

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'ไม่ได้เข้าสู่ระบบ']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'search') {
    // CSRF-protected even though this is read-only (no DB write): each call
    // spends real UP AI Connect quota, and a cross-site-triggered flood of
    // searches could drain a provider's daily quota just as effectively as
    // an unwanted write would - same threat model as the state-changing
    // endpoints in Phases 6-9, just a different kind of "damage."
    if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token ไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
        exit;
    }

    $query = trim($_GET['q'] ?? '');
    if ($query === '') {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณาระบุคำค้นหา']);
        exit;
    }

    $query_hash = md5(mb_strtolower($query));

    // Cache check first (SEM-06) - exact text match only, deliberately not
    // fuzzy: a demo-day rehearsed query must be typed identically to hit
    // the guaranteed-instant path.
    $cacheStmt = $pdo->prepare("SELECT results_json FROM `expert_finder_cache` WHERE query_hash = ?");
    $cacheStmt->execute([$query_hash]);
    $cached = $cacheStmt->fetchColumn();
    if ($cached !== false) {
        echo $cached; // already valid JSON from a prior run of this exact function
        exit;
    }

    $stmt = $pdo->query("
        SELECT id, title, llm_semantic_tags
        FROM `publications`
        WHERE llm_semantic_tags IS NOT NULL AND llm_semantic_tags != '' AND llm_semantic_tags != '[]'
    ");
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        http_response_code(400);
        echo json_encode(['error' => 'ยังไม่มีผลงานที่ผ่านการวิเคราะห์ด้วย LLM กรุณา Auto-Classify (Zero-Shot LLM) ก่อนใช้ฟีเจอร์นี้']);
        exit;
    }

    $candidates = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'title' => $r['title'],
            'tags' => json_decode($r['llm_semantic_tags'], true) ?: [],
        ];
    }, $rows);

    try {
        $ranked = fetch_llm_expert_ranking_with_retry($query, $candidates);
    } catch (Exception $e) {
        http_response_code(502);
        echo json_encode(['error' => 'UP AI Connect: ' . $e->getMessage()]);
        exit;
    }

    if (empty($ranked)) {
        $response = json_encode(['query' => $query, 'researchers' => []], JSON_UNESCAPED_UNICODE);
        $pdo->prepare("INSERT IGNORE INTO `expert_finder_cache` (query_hash, query_text, results_json) VALUES (?, ?, ?)")
            ->execute([$query_hash, $query, $response]);
        echo $response;
        exit;
    }

    $pub_ids = array_map(function ($r) { return $r['publication_id']; }, $ranked);
    $placeholders = implode(',', array_fill(0, count($pub_ids), '?'));
    $linkStmt = $pdo->prepare("
        SELECT rp.publication_id, r.id AS researcher_id, r.title_th, r.first_name_th, r.last_name_th, r.first_name_en, r.last_name_en, r.department
        FROM `researcher_publications` rp
        JOIN `researchers` r ON rp.researcher_id = r.id
        WHERE rp.publication_id IN ($placeholders)
    ");
    $linkStmt->execute($pub_ids);
    $links = $linkStmt->fetchAll();

    $pub_to_researchers = [];
    foreach ($links as $l) {
        $pub_to_researchers[(int)$l['publication_id']][] = $l;
    }
    $pub_meta = [];
    foreach ($candidates as $c) {
        $pub_meta[$c['id']] = $c['title'];
    }

    // Group by researcher, keeping each researcher's highest-scoring
    // matching publication first - a researcher can legitimately show up
    // via multiple matched publications.
    $researchers = [];
    foreach ($ranked as $r) {
        $pub_id = $r['publication_id'];
        foreach (($pub_to_researchers[$pub_id] ?? []) as $link) {
            $rid = (int)$link['researcher_id'];
            if (!isset($researchers[$rid])) {
                $name_th = trim(($link['title_th'] ?? '') . ' ' . $link['first_name_th'] . ' ' . $link['last_name_th']);
                $name_en = trim($link['first_name_en'] . ' ' . $link['last_name_en']);
                $researchers[$rid] = [
                    'researcher_id' => $rid,
                    'name' => $name_th !== '' ? $name_th : $name_en,
                    'department' => $link['department'] ?? null,
                    'best_score' => $r['relevance_score'],
                    'matching_publications' => [],
                ];
            }
            $researchers[$rid]['matching_publications'][] = [
                'publication_id' => $pub_id,
                'title' => $pub_meta[$pub_id] ?? '',
                'relevance_score' => $r['relevance_score'],
                'reason' => $r['reason'],
            ];
        }
    }
    $researchers = array_values($researchers);
    usort($researchers, function ($a, $b) { return $b['best_score'] <=> $a['best_score']; });

    $response = json_encode(['query' => $query, 'researchers' => $researchers], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT IGNORE INTO `expert_finder_cache` (query_hash, query_text, results_json) VALUES (?, ?, ?)")
        ->execute([$query_hash, $query, $response]);
    echo $response;
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
