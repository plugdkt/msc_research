<?php
// admin/classify_topics.php
// Phase 8 (Topic Prominence & Trends): batch-resolve OpenAlex topic
// classification for publications via DOI. Same one-publication-per-request
// shape as admin/auto_classify_sdgs.php (driven by JS in
// admin/topics_import.php), for the same reason: looping hundreds of
// publications' worth of external API calls inside one HTTP request risks
// the IIS FastCGI timeout. Unlike the SDG tool, OpenAlex's per-record
// lookup used here is free (single-entity view, not a search/filter call -
// see OpenAlex's usage-based pricing docs), so cost isn't a batching
// concern, only the timeout risk is.
//
// Unlike SDG-06c, there is no "never overwrite" guard: OpenAlex topics are
// never admin-curated (nothing lets a human hand-enter a topic), so a
// refresh always safely replaces the previously stored topics with
// whatever OpenAlex currently returns.

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

if ($action === 'list') {
    // mode=refresh re-targets every publication with a DOI (re-fetch, topic
    // model may have changed); default targets only publications never
    // attempted yet (openalex_checked_at IS NULL).
    $refresh = isset($_GET['mode']) && $_GET['mode'] === 'refresh';
    $sql = "SELECT id, title FROM `publications` WHERE doi IS NOT NULL AND doi != ''";
    if (!$refresh) {
        $sql .= " AND openalex_checked_at IS NULL";
    }
    $sql .= " ORDER BY id ASC";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'items' => array_map(function ($r) {
            return ['id' => (int)$r['id'], 'title' => $r['title']];
        }, $rows),
        'mode' => $refresh ? 'refresh' : 'initial',
    ]);
    exit;
}

if ($action === 'process') {
    if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token ไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
        exit;
    }

    $pub_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($pub_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ไม่พบรหัสผลงานวิจัย']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, doi FROM `publications` WHERE id = ?");
    $stmt->execute([$pub_id]);
    $pub = $stmt->fetch();
    if (!$pub) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบผลงานวิจัยนี้ในระบบ']);
        exit;
    }

    if (empty($pub['doi'])) {
        // Still mark as checked so it doesn't get re-selected by the
        // default (non-refresh) list every run.
        $pdo->prepare("UPDATE `publications` SET openalex_checked_at = NOW() WHERE id = ?")->execute([$pub_id]);
        echo json_encode(['id' => $pub_id, 'status' => 'skipped', 'reason' => 'ไม่มี DOI']);
        exit;
    }

    try {
        $result = fetch_openalex_work_topics_with_retry($pub['doi']);
    } catch (Exception $e) {
        echo json_encode(['id' => $pub_id, 'status' => 'error', 'reason' => 'OpenAlex: ' . $e->getMessage()]);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM `publication_topics` WHERE publication_id = ?")->execute([$pub_id]);

        if (!empty($result['topics'])) {
            $insert = $pdo->prepare("
                INSERT INTO `publication_topics`
                    (publication_id, topic_id, display_name, subfield, field, domain, score, is_primary)
                VALUES (:publication_id, :topic_id, :display_name, :subfield, :field, :domain, :score, :is_primary)
            ");
            foreach ($result['topics'] as $t) {
                $insert->execute([
                    ':publication_id' => $pub_id,
                    ':topic_id' => $t['topic_id'],
                    ':display_name' => $t['display_name'],
                    ':subfield' => $t['subfield'],
                    ':field' => $t['field'],
                    ':domain' => $t['domain'],
                    ':score' => $t['score'],
                    ':is_primary' => $t['is_primary'],
                ]);
            }
        }

        $update = $pdo->prepare("UPDATE `publications` SET openalex_id = :openalex_id, openalex_checked_at = NOW() WHERE id = :id");
        $update->execute([
            ':openalex_id' => $result['openalex_id'],
            ':id' => $pub_id,
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['id' => $pub_id, 'status' => 'error', 'reason' => 'DB: ' . $e->getMessage()]);
        exit;
    }

    if (empty($result['topics'])) {
        echo json_encode(['id' => $pub_id, 'status' => 'no_match', 'reason' => 'ไม่พบข้อมูลใน OpenAlex สำหรับ DOI นี้']);
        exit;
    }

    $primary = null;
    foreach ($result['topics'] as $t) {
        if ($t['is_primary']) {
            $primary = $t;
            break;
        }
    }

    echo json_encode([
        'id' => $pub_id,
        'status' => 'applied',
        'primary_topic' => $primary['display_name'] ?? null,
        'field' => $primary['field'] ?? null,
        'topic_count' => count($result['topics']),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
