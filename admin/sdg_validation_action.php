<?php
// admin/sdg_validation_action.php
// Phase 10 (SEM-07): saves one blind gold-standard label
// (admin/sdg_validation.php). INSERT ... ON DUPLICATE KEY UPDATE so
// re-submitting the same publication (e.g. a double-click) corrects the
// existing label rather than erroring or duplicating it.

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

if ($action === 'label') {
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

    $sdg_raw = $_GET['sdg'] ?? '';
    $sdg = null;
    if ($sdg_raw !== '') {
        $n = (int)$sdg_raw;
        if ($n < 1 || $n > 17) {
            http_response_code(400);
            echo json_encode(['error' => 'ค่า SDG ไม่ถูกต้อง']);
            exit;
        }
        $sdg = $n;
    }

    $labeler = $_SESSION['admin_username'] ?? 'unknown-admin';

    $stmt = $pdo->prepare("
        INSERT INTO `sdg_gold_standard` (publication_id, correct_sdg, labeled_by, labeled_at)
        VALUES (:pub_id, :sdg, :labeler, NOW())
        ON DUPLICATE KEY UPDATE correct_sdg = VALUES(correct_sdg), labeled_by = VALUES(labeled_by), labeled_at = NOW()
    ");
    $stmt->execute([
        ':pub_id' => $pub_id,
        ':sdg' => $sdg,
        ':labeler' => $labeler,
    ]);

    echo json_encode(['status' => 'saved', 'id' => $pub_id]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
