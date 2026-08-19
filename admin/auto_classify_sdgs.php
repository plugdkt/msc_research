<?php
// admin/auto_classify_sdgs.php
// Batch auto-classify ALL unclassified publications against the SDG
// keyphrase dictionary in one click. Unlike admin/suggest_sdgs.php (admin
// reviews a suggestion, then saves it manually), this endpoint WRITES
// sdg_primary/sdg_secondary/sdg_rationale directly and unattended - the
// user explicitly asked for one-click, whole-faculty batch classification,
// which isn't practical to review publication-by-publication.
//
// Runs as a one-publication-per-request loop, driven by JS in
// admin/sdg_import.php, rather than one big server-side loop over
// hundreds of publications: each unclassified publication needs its own
// Scopus Abstract Retrieval API call (no batch-lookup endpoint exists), so
// looping all of them inside a single HTTP request would very likely
// exceed IIS's FastCGI request timeout - the exact risk this project's own
// stack notes already flag for the main sync job. Short, cheap per-item
// requests avoid that entirely, and each publication commits independently,
// so the whole batch is naturally resumable if interrupted partway.
//
// Safety: a publication only gets written to when its top match score
// clears MIN_AUTO_APPLY_SCORE, and only if it has no existing SDG tag
// (never overwrites a human or prior auto-classify decision). This
// protects the project's Core Value (data accuracy) from being silently
// populated with low-confidence keyword coincidences - below the
// threshold, the publication is left Unclassified for manual review via
// admin/suggest_sdgs.php instead of forcing a guess onto it.

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

// Raised from an initial 0.4 to 1.0 per server-side review (2026-08-19):
// this data is published on the faculty's public reports, so a strict bar
// (effectively "at least one full-phrase keyphrase match", since a single
// full match scores its full rel weight and most rel values run close to
// 1.0) matters more than covering every publication automatically. Below
// this, a publication is left Unclassified for manual review instead.
const MIN_AUTO_APPLY_SCORE = 1.0;

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT id, title FROM `publications`
        WHERE (sdg_primary IS NULL OR sdg_primary = '')
          AND (sdg_secondary IS NULL OR sdg_secondary = '')
        ORDER BY id ASC
    ");
    $rows = $stmt->fetchAll();
    echo json_encode([
        'items' => array_map(function ($r) {
            return ['id' => (int)$r['id'], 'title' => $r['title']];
        }, $rows),
        'min_score' => MIN_AUTO_APPLY_SCORE,
    ]);
    exit;
}

if ($action === 'process') {
    $pub_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($pub_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ไม่พบรหัสผลงานวิจัย']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, title, doi, abstract, keywords, sdg_primary, sdg_secondary FROM `publications` WHERE id = ?");
    $stmt->execute([$pub_id]);
    $pub = $stmt->fetch();
    if (!$pub) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบผลงานวิจัยนี้ในระบบ']);
        exit;
    }

    // Never overwrite an existing SDG tag - a human (or an earlier
    // auto-classify pass) already made this call.
    if (!empty($pub['sdg_primary']) || !empty($pub['sdg_secondary'])) {
        echo json_encode(['id' => $pub_id, 'status' => 'skipped', 'reason' => 'มีการจำแนก SDG อยู่แล้ว']);
        exit;
    }

    if (empty($pub['abstract']) && empty($pub['keywords']) && !empty($pub['doi'])) {
        $api_key = defined('SCOPUS_API_KEY') ? SCOPUS_API_KEY : null;
        if ($api_key) {
            try {
                $details = fetch_scopus_abstract_details_with_retry($pub['doi'], $api_key);
                if (!empty($details['abstract']) || !empty($details['keywords'])) {
                    $update = $pdo->prepare("UPDATE `publications` SET abstract = COALESCE(NULLIF(:abstract, ''), abstract), keywords = COALESCE(NULLIF(:keywords, ''), keywords) WHERE id = :id");
                    $update->execute([
                        ':abstract' => $details['abstract'] ?? '',
                        ':keywords' => $details['keywords'] ?? '',
                        ':id' => $pub_id,
                    ]);
                    $pub['abstract'] = $details['abstract'] ?? $pub['abstract'];
                    $pub['keywords'] = $details['keywords'] ?? $pub['keywords'];
                }
            } catch (Exception $e) {
                // Non-fatal for the batch: leave this one Unclassified and
                // let the JS driver move on to the next publication.
                echo json_encode(['id' => $pub_id, 'status' => 'error', 'reason' => 'Scopus: ' . $e->getMessage()]);
                exit;
            }
        }
    }

    $results = score_publication_sdgs($pub['title'], $pub['keywords'], $pub['abstract']);

    if (empty($results) || $results[0]['score'] < MIN_AUTO_APPLY_SCORE) {
        echo json_encode([
            'id' => $pub_id,
            'status' => 'low_confidence',
            'reason' => empty($results) ? 'ไม่พบคำสำคัญที่ตรงกับ SDG ใดเลย' : ('คะแนนต่ำกว่าเกณฑ์ (' . round($results[0]['score'], 2) . ' < ' . MIN_AUTO_APPLY_SCORE . ')'),
            'top_score' => empty($results) ? 0 : round($results[0]['score'], 2),
        ]);
        exit;
    }

    $primary_code = 'SDG ' . $results[0]['num'];
    $secondary_code = null;
    if (isset($results[1]) && $results[1]['score'] >= MIN_AUTO_APPLY_SCORE) {
        $secondary_code = 'SDG ' . $results[1]['num'];
    }

    $rationale_for = function ($r) {
        $parts = array_map(function ($m) {
            return $m['k'] . ' (' . ($m['full'] ? 'ตรงทั้งวลี' : 'ตรงบางส่วน') . ')';
        }, array_slice($r['matched'], 0, 5));
        return 'SDG ' . $r['num'] . ': ' . implode(', ', $parts);
    };
    $rationale_parts = [$rationale_for($results[0])];
    if ($secondary_code) {
        $rationale_parts[] = $rationale_for($results[1]);
    }
    $rationale = '[Auto-Classify ' . date('Y-m-d') . '] ' . implode(' | ', $rationale_parts);

    $update = $pdo->prepare("UPDATE `publications` SET sdg_primary = :primary, sdg_secondary = :secondary, sdg_rationale = :rationale WHERE id = :id");
    $update->execute([
        ':primary' => $primary_code,
        ':secondary' => $secondary_code,
        ':rationale' => $rationale,
        ':id' => $pub_id,
    ]);

    echo json_encode([
        'id' => $pub_id,
        'status' => 'applied',
        'sdg_primary' => $primary_code,
        'sdg_secondary' => $secondary_code,
        'top_score' => round($results[0]['score'], 2),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
