<?php
// admin/llm_review_action.php
// Phase 10 (SEM-08): saves a human review decision for a low-confidence LLM
// SDG classification. Whatever SDG the reviewer selects (including "none")
// becomes the final llm_sdg_primary at 100% confidence - a human decision
// is authoritative, so there is no lower confidence to express once
// reviewed. llm_reviewed_by/llm_reviewed_at form the audit trail
// (SEM-08's "who/when recorded" requirement).

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

if ($action === 'submit') {
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

    $reviewer = $_SESSION['admin_username'] ?? 'unknown-admin';

    $update = $pdo->prepare("
        UPDATE `publications` SET
            llm_sdg_primary = :sdg,
            llm_confidence_primary = :confidence,
            llm_reviewed_by = :reviewer,
            llm_reviewed_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':sdg' => $sdg,
        ':confidence' => $sdg !== null ? 100 : null,
        ':reviewer' => $reviewer,
        ':id' => $pub_id,
    ]);

    echo json_encode(['status' => 'saved', 'id' => $pub_id, 'sdg' => $sdg]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
