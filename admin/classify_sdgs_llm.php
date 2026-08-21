<?php
// admin/classify_sdgs_llm.php
// Phase 10 (Zero-Shot LLM AI Layer, SEM-02): batch-classify publications'
// SDG alignment via UP AI Connect, one publication per request - same
// IIS-FastCGI-timeout-avoidance shape as admin/auto_classify_sdgs.php and
// every other Phase 6-9 batch tool.
//
// Writes ONLY to the llm_* columns (never sdg_primary/secondary/tertiary):
// this runs alongside the existing Phase 6 keyword-dictionary classifier,
// not in place of it, so both can be compared (SEM-07) and a live
// classification during the presentation isn't riding on an unvalidated
// model alone. mode=refresh re-targets every publication with an abstract
// (re-classify); default targets only publications never attempted yet
// (llm_checked_at IS NULL).

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
    $refresh = isset($_GET['mode']) && $_GET['mode'] === 'refresh';
    $sql = "SELECT id, title FROM `publications` WHERE abstract IS NOT NULL AND abstract != ''";
    if (!$refresh) {
        $sql .= " AND llm_checked_at IS NULL";
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

    $stmt = $pdo->prepare("SELECT id, title, abstract FROM `publications` WHERE id = ?");
    $stmt->execute([$pub_id]);
    $pub = $stmt->fetch();
    if (!$pub) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบผลงานวิจัยนี้ในระบบ']);
        exit;
    }

    if (!is_valid_abstract($pub['abstract'])) {
        // Still mark as checked so it doesn't get re-selected by the
        // default (non-refresh) list every run.
        $pdo->prepare("UPDATE `publications` SET llm_checked_at = NOW() WHERE id = ?")->execute([$pub_id]);
        echo json_encode(['id' => $pub_id, 'status' => 'skipped', 'reason' => 'ไม่มีบทคัดย่อที่ใช้ได้']);
        exit;
    }

    try {
        $result = fetch_llm_sdg_classification_with_retry($pub['title'], $pub['abstract']);
    } catch (Exception $e) {
        echo json_encode(['id' => $pub_id, 'status' => 'error', 'reason' => 'UP AI Connect: ' . $e->getMessage()]);
        exit;
    }

    $update = $pdo->prepare("
        UPDATE `publications` SET
            llm_sdg_primary = :sdg_primary,
            llm_confidence_primary = :confidence_primary,
            llm_sdg_secondary = :sdg_secondary,
            llm_confidence_secondary = :confidence_secondary,
            llm_rationale = :rationale,
            llm_semantic_tags = :semantic_tags,
            llm_model = :model,
            llm_checked_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':sdg_primary' => $result['sdg_primary'],
        ':confidence_primary' => $result['confidence_primary'],
        ':sdg_secondary' => $result['sdg_secondary'],
        ':confidence_secondary' => $result['confidence_secondary'],
        ':rationale' => $result['rationale'],
        ':semantic_tags' => json_encode($result['semantic_tags'], JSON_UNESCAPED_UNICODE),
        ':model' => $result['model'],
        ':id' => $pub_id,
    ]);

    if ($result['sdg_primary'] === null) {
        echo json_encode(['id' => $pub_id, 'status' => 'no_match', 'reason' => 'โมเดลไม่พบ SDG ที่เกี่ยวข้อง']);
        exit;
    }

    echo json_encode([
        'id' => $pub_id,
        'status' => 'applied',
        'sdg_primary' => 'SDG ' . $result['sdg_primary'],
        'confidence_primary' => $result['confidence_primary'],
        'sdg_secondary' => $result['sdg_secondary'] !== null ? ('SDG ' . $result['sdg_secondary']) : null,
        'confidence_secondary' => $result['confidence_secondary'],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
