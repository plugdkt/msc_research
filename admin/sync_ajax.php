<?php
// admin/sync_ajax.php
// Per-researcher AJAX endpoint backing the live-progress "sync all" /
// "sync individual" UI on admin/sync.php (mirrors the action=list /
// action=process pattern already used by admin/classify_sdgs_llm.php).
//
// Kept as a separate file from sync.php on purpose - sync.php still owns
// run_synchronization() for the CLI/cron whole-batch path (admin/sync.php's
// top section), so a web request never blocks for the full batch duration
// with no feedback. Both paths call the same sync_one_researcher() /
// open_sync_log() / find_duplicate_scopus_author_ids() helpers in
// includes/functions.php, so the actual fetch/write/mutex rules can't drift
// between the two entry points.

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

$current_admin = $_SESSION['admin_username'] ?? 'unknown-admin';
$action = $_GET['action'] ?? '';

// action=process can wait through Scopus retry/backoff (5s+15s+45s on
// timeout, or up to 3x60s on a 429) before it either succeeds or throws -
// well past PHP's typical 30s default max_execution_time for a web request.
// Each AJAX call only ever covers ONE researcher, so 300s is generous headroom.
set_time_limit(300);

if ($action === 'start') {
    if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token ไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
        exit;
    }

    $target_researcher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // ADMIN-03: same mutex rule as the CLI/cron whole-batch path.
    $lock = open_sync_log($pdo, $current_admin);
    if ($lock['rejected']) {
        http_response_code(409);
        echo json_encode(['error' => "มีการซิงค์กำลังทำงานอยู่ (เริ่มเมื่อ " . format_thai_datetime($lock['rejected']['started_at']) . ") กรุณารอให้เสร็จก่อน"]);
        exit;
    }

    try {
        if ($target_researcher_id > 0) {
            $stmt = $pdo->prepare("SELECT id, title_th, first_name_th, last_name_th, scopus_author_id FROM `researchers` WHERE id = ?");
            $stmt->execute([$target_researcher_id]);
        } else {
            $stmt = $pdo->query("SELECT id, title_th, first_name_th, last_name_th, scopus_author_id FROM `researchers` ORDER BY id ASC");
        }
        $researchers = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("[sync_ajax] failed to fetch researchers: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'ไม่สามารถดึงรายชื่อนักวิจัยได้']);
        exit;
    }

    echo json_encode([
        'sync_log_id' => $lock['sync_log_id'],
        'items' => array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'name' => trim($r['title_th'] . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']),
            ];
        }, $researchers),
    ]);
    exit;
}

if ($action === 'process') {
    if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token ไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
        exit;
    }

    $researcher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($researcher_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ไม่พบรหัสนักวิจัย']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM `researchers` WHERE id = ?");
    $stmt->execute([$researcher_id]);
    $r = $stmt->fetch();
    if (!$r) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบนักวิจัยรายนี้ในระบบ']);
        exit;
    }

    // Recomputed per-call (cheap single GROUP BY query) rather than trusting
    // a value cached from action=start, so a researcher's Scopus Author ID
    // edited mid-run is still checked against current data.
    $duplicate_scopus_ids = find_duplicate_scopus_author_ids($pdo);
    $name = trim($r['title_th'] . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']);
    $result = sync_one_researcher($pdo, $r, $duplicate_scopus_ids);

    echo json_encode([
        'researcher' => $name,
        'details' => $result['details'],
        'has_error' => $result['has_error'],
        'publications_synced' => $result['publications_synced'],
        'records_skipped' => $result['records_skipped'],
    ]);
    exit;
}

if ($action === 'finish') {
    if (!verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token ไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่']);
        exit;
    }

    $sync_log_id = isset($_POST['sync_log_id']) ? (int)$_POST['sync_log_id'] : 0;
    if ($sync_log_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ไม่พบรหัส sync_log']);
        exit;
    }

    $researchers_processed = (int)($_POST['researchers_processed'] ?? 0);
    $publications_synced = (int)($_POST['publications_synced'] ?? 0);
    $records_skipped = (int)($_POST['records_skipped'] ?? 0);
    $error_count = (int)($_POST['error_count'] ?? 0);
    $duplicate_count = (int)($_POST['duplicate_count'] ?? 0);
    $stopped_early = !empty($_POST['stopped_early']);
    // Raw JSON string built client-side from the same per-researcher results
    // already rendered live - stored as-is (validated as JSON, not re-derived)
    // so sync_log.details matches exactly what the admin watched happen.
    $logs_json = $_POST['logs'] ?? '[]';
    if (json_decode($logs_json) === null && $logs_json !== 'null') {
        $logs_json = '[]';
    }

    $status = 'success';
    if ($stopped_early) {
        $status = $researchers_processed > 0 ? 'partial' : 'failed';
    } elseif ($error_count > 0) {
        $status = ($error_count >= $researchers_processed) ? 'failed' : 'partial';
    }

    try {
        $pdo->prepare("
            UPDATE `sync_log`
            SET status = ?, completed_at = NOW(), researchers_processed = ?,
                publications_synced = ?, records_skipped = ?,
                duplicate_scopus_ids_found = ?, details = ?
            WHERE id = ?
        ")->execute([
            $status,
            $researchers_processed,
            $publications_synced,
            $records_skipped,
            $duplicate_count,
            $logs_json,
            $sync_log_id,
        ]);
    } catch (PDOException $e) {
        error_log("[sync_ajax] failed to close sync_log row {$sync_log_id}: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'บันทึกผลลัพธ์ลง sync_log ไม่สำเร็จ']);
        exit;
    }

    echo json_encode(['ok' => true, 'status' => $status]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'ไม่รู้จัก action นี้']);
