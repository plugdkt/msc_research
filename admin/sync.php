<?php
// admin/sync.php
// Synchronize researchers publications from APIs
//
// SECURITY NOTE (2026-07-06 review):
// - CRON_SYNC_TOKEN must be defined in config/db.php (or a separate
//   config/secrets.php that is NOT committed to git / version control).
//   Example to add in config/db.php:
//
//     define('CRON_SYNC_TOKEN', getenv('MSC_SYNC_TOKEN') ?: 'REPLACE_ME_WITH_LONG_RANDOM_STRING');
//
//   Generate a strong random token once, e.g. in PHP: bin2hex(random_bytes(32))
//   Do NOT reuse the old hardcoded value 'msc_sync_secure_token_1234' -- treat it as
//   already leaked and rotate it.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$is_cli = (php_sapi_name() === 'cli');

// Constant-time comparison via hash_equals() prevents timing-attack token guessing.
// defined() check first avoids a fatal error if the constant was never configured,
// and fails closed (access denied) rather than open in that case.
$is_cron_token = defined('CRON_SYNC_TOKEN')
    && CRON_SYNC_TOKEN !== ''
    && isset($_GET['token'])
    && hash_equals(CRON_SYNC_TOKEN, $_GET['token']);

if ($is_cli || $is_cron_token) {
    // Run automated synchronization
    run_synchronization($pdo, true, null, $is_cli ? 'cli' : 'cron-token');
    exit;
}

// --- Everything below this line requires an authenticated admin session ---
//
// SECURITY: this explicit check (matching admin_header.php's own check)
// runs BEFORE any sync is triggered and before any HTML output, for two
// reasons:
// 1. ADMIN-05: no privileged action may run without it - relying solely on
//    admin_header.php further down was exactly the bug found and fixed in
//    admin/researchers.php (an unauthenticated request executed every
//    action handler before that include was ever reached).
// 2. ADMIN-03: the CLI/cron-token whole-batch path above must reject an
//    already-running sync with HTTP 409 before any HTML is printed - PHP
//    can't change the status code once output has started. The web-driven
//    per-researcher path (admin/sync_ajax.php) makes that same check on its
//    own request/response, independent of this page's initial load.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// ADMIN-04: every sync trigger records who triggered it in sync_log.triggered_by.
// Web-triggered syncs now run through admin/sync_ajax.php's per-researcher
// AJAX loop (live progress, see JS below) instead of blocking this request
// for the whole batch - CLI/cron still call run_synchronization() directly
// (top of this file) for a single, non-interactive whole-batch run.
$current_admin = $_SESSION['admin_username'] ?? 'unknown-admin';

$current_page = 'admin_sync';
$page_title = 'ซิงค์ข้อมูลผ่าน API - Admin Panel';

require_once __DIR__ . '/admin_header.php';

// --- Sync history report (persistent summary, unlike the log panel on the
// right which only fills in right after a trigger on this same request) ---
$sync_stats = null;
$recent_sync_runs = [];
try {
    $sync_stats = $pdo->query("
        SELECT
            COUNT(*) AS total_runs,
            SUM(status = 'success') AS success_runs,
            SUM(status = 'partial') AS partial_runs,
            SUM(status = 'failed') AS failed_runs,
            SUM(publications_synced) AS total_pubs_synced,
            SUM(records_skipped) AS total_skipped,
            AVG(CASE WHEN completed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, completed_at) END) AS avg_duration_seconds,
            MAX(started_at) AS last_run_at
        FROM `sync_log`
    ")->fetch();

    $recent_sync_runs = $pdo->query("
        SELECT id, started_at, completed_at, status, triggered_by,
               researchers_processed, publications_synced, records_skipped
        FROM `sync_log`
        ORDER BY started_at DESC
        LIMIT 10
    ")->fetchAll();
} catch (PDOException $e) {
    error_log("[sync] failed to load sync_log summary report: " . $e->getMessage());
}

// Get list of all researchers for the dropdown menu
$all_researchers_list = [];
try {
    $stmt = $pdo->query("SELECT id, title_th, first_name_th, last_name_th FROM `researchers` ORDER BY first_name_th ASC");
    $all_researchers_list = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to fetch researchers list for dropdown: " . $e->getMessage());
}

// --- Orphan cleanup (admin-triggered only, see cleanup_orphaned_publications() in functions.php) ---
$orphan_preview_count = null;
$cleanup_result = null;

if (isset($_GET['preview_cleanup'])) {
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) as total FROM `publications`
            WHERE `id` NOT IN (SELECT DISTINCT `publication_id` FROM `researcher_publications`)
        ");
        $orphan_preview_count = (int)($stmt->fetch()['total'] ?? 0);
    } catch (PDOException $e) {
        error_log("Failed to count orphaned publications: " . $e->getMessage());
        $orphan_preview_count = null;
    }
}

if (isset($_POST['trigger_cleanup']) && isset($_POST['cleanup_confirm']) && $_POST['cleanup_confirm'] === 'yes') {
    $deleted = cleanup_orphaned_publications($pdo);
    $cleanup_result = $deleted;
}


/**
 * Runs the Scopus synchronization process for researchers (Scopus-only —
 * ORCID/PubMed/Google Scholar were dropped 2026-08-19; multi-source
 * matching/dedup was a recurring source of bugs, and scraping Google
 * Scholar violated its ToS anyway).
 *
 * Implements the SYNC-01..05 contract:
 * - SYNC-01/02: fetch_scopus_publications_with_retry() handles timeout
 *   backoff (5s/15s/45s) and 429 rate-limit waits.
 * - SYNC-03: incomplete records (no DOI/author) are skipped and counted.
 * - SYNC-04: researchers sharing a duplicate Scopus Author ID are excluded
 *   from the run and flagged, rather than auto-merged.
 * - SYNC-05: each publication write is its own short transaction (see
 *   add_or_update_publication()), so public reads are never blocked by an
 *   in-progress sync.
 *
 * Every run is recorded in `sync_log` (ADMIN-04: including who triggered it).
 *
 * @return array{logs: array, summary: array}
 */
function run_synchronization($pdo, $quiet = false, $target_researcher_id = null, $triggered_by = null) {
    // Increase execution time as API calls take time (retries can each wait
    // up to 45s, and a 429 can wait up to 60s). For large researcher counts,
    // consider moving this to a real background queue/worker instead of a
    // single long-running HTTP/CLI request.
    set_time_limit(0);
    if (!$quiet) {
        // Web-triggered runs still shouldn't hang the browser forever;
        // give them a generous but bounded limit.
        set_time_limit(300);
    }

    $logs = [];
    $researchers_processed = 0;
    $publications_synced = 0;
    $records_skipped = 0;
    $error_count = 0;

    // Delay (seconds) between researchers to stay well under Scopus rate limits.
    // Note: PHP does not allow `const` inside a function body, so a plain variable is used.
    $inter_researcher_delay_seconds = 1;

    // ADMIN-03: reject a sync trigger while another is already running,
    // rather than letting two syncs race against the same publications.
    // A 'running' row older than this is treated as an abandoned/crashed
    // sync (e.g. the PHP process was killed mid-run) rather than a real
    // lock, so a stuck row can't jam every future sync forever.
    $stale_after_minutes = 30;
    $lock = open_sync_log($pdo, $triggered_by, $stale_after_minutes);
    if ($lock['rejected']) {
        $msg = "มีการซิงค์กำลังทำงานอยู่ (เริ่มเมื่อ " . format_thai_datetime($lock['rejected']['started_at']) . ") กรุณารอให้เสร็จก่อน";
        if ($quiet) {
            echo $msg . "\n";
        }
        return ['logs' => [["researcher" => "-", "details" => [$msg], "has_error" => true]], 'summary' => [
            'researchers_processed' => 0, 'publications_synced' => 0,
            'records_skipped' => 0, 'duplicate_scopus_ids_found' => 0,
            'rejected_already_running' => true,
        ]];
    }
    $sync_log_id = $lock['sync_log_id'];

    // SYNC-04: find Scopus Author IDs shared by more than one researcher
    // *before* touching the API, so those researchers can be excluded from
    // this run rather than silently synced (which would attribute one
    // person's publications to both records) or auto-merged.
    $duplicate_scopus_ids = find_duplicate_scopus_author_ids($pdo);

    // Fetch researchers (all or targeted)
    try {
        if ($target_researcher_id) {
            $stmt = $pdo->prepare("SELECT * FROM `researchers` WHERE id = ?");
            $stmt->execute([$target_researcher_id]);
        } else {
            $stmt = $pdo->query("SELECT * FROM `researchers`");
        }
        $researchers = $stmt->fetchAll();
    } catch (PDOException $e) {
        $msg = "Error fetching researchers: " . $e->getMessage();
        error_log($msg);
        if ($sync_log_id) {
            $pdo->prepare("UPDATE `sync_log` SET status = 'failed', completed_at = NOW(), error_message = ? WHERE id = ?")
                ->execute([$msg, $sync_log_id]);
        }
        $logs[] = ["researcher" => "-", "details" => [$msg], "has_error" => true];
        if ($quiet) {
            echo $msg . "\n";
        }
        return ['logs' => $logs, 'summary' => [
            'researchers_processed' => 0, 'publications_synced' => 0,
            'records_skipped' => 0, 'duplicate_scopus_ids_found' => count($duplicate_scopus_ids),
        ]];
    }

    $total = count($researchers);
    $index = 0;

    foreach ($researchers as $r) {
        $index++;
        $name = trim($r['title_th'] . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']);
        $result = sync_one_researcher($pdo, $r, $duplicate_scopus_ids);
        $logs_entry = ["researcher" => $name, "details" => $result['details'], "has_error" => $result['has_error']];

        $publications_synced += $result['publications_synced'];
        $records_skipped += $result['records_skipped'];
        if ($result['has_error']) {
            $error_count++;
        }

        $researchers_processed++;
        $logs[] = $logs_entry;

        if ($quiet) {
            echo "Researcher: {$name}\n";
            foreach ($logs_entry["details"] as $detail) {
                echo " - {$detail}\n";
            }
            echo "\n";
        }

        // Rate limiting: pause briefly between researchers so a large batch
        // doesn't fire a burst of requests at Scopus back-to-back.
        // Skipped after the very last researcher.
        if ($index < $total) {
            sleep($inter_researcher_delay_seconds);
        }
    }

    if ($sync_log_id) {
        $status = 'success';
        if ($error_count > 0) {
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
                count($duplicate_scopus_ids),
                json_encode($logs, JSON_UNESCAPED_UNICODE),
                $sync_log_id,
            ]);
        } catch (PDOException $e) {
            error_log("[sync] failed to close sync_log row {$sync_log_id}: " . $e->getMessage());
        }
    }

    return ['logs' => $logs, 'summary' => [
        'researchers_processed' => $researchers_processed,
        'publications_synced' => $publications_synced,
        'records_skipped' => $records_skipped,
        'duplicate_scopus_ids_found' => count($duplicate_scopus_ids),
    ]];
}
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>ซิงค์ข้อมูลผ่าน API อัตโนมัติ</h2>
    <p>ระบบจะเรียกใช้งานบริการ API ภายนอกเพื่อดาวน์โหลดผลงานล่าสุดและดัชนีการอ้างอิงของนักวิจัยแต่ละท่านเข้ามายังระบบฐานข้อมูลหลังบ้านโดยอัตโนมัติ</p>
</div>

<!-- Sync history report: always visible, independent of whether a sync was just triggered on this request -->
<div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 30px;">
    <h3 style="margin-bottom: 20px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px;">
        <i class="fa-solid fa-chart-line" style="color: var(--color-accent);"></i> รายงานสถิติการซิงค์ทั้งหมด
    </h3>

    <?php if ($sync_stats && (int)$sync_stats['total_runs'] > 0): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">ซิงค์ทั้งหมด</div>
                <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo number_format($sync_stats['total_runs']); ?></div>
            </div>
            <div style="background: rgba(46, 160, 67, 0.08); border: 1px solid rgba(46, 160, 67, 0.3); padding: 14px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">สำเร็จ</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-success); font-family: var(--font-eng);"><?php echo number_format($sync_stats['success_runs']); ?></div>
            </div>
            <div style="background: rgba(234, 179, 8, 0.08); border: 1px solid rgba(234, 179, 8, 0.3); padding: 14px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">สำเร็จบางส่วน</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #eab308; font-family: var(--font-eng);"><?php echo number_format($sync_stats['partial_runs']); ?></div>
            </div>
            <div style="background: rgba(239, 68, 68, 0.06); border: 1px solid rgba(239, 68, 68, 0.2); padding: 14px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">ล้มเหลว</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #f87171; font-family: var(--font-eng);"><?php echo number_format($sync_stats['failed_runs']); ?></div>
            </div>
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">ผลงานที่ซิงค์สะสม</div>
                <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo number_format($sync_stats['total_pubs_synced']); ?></div>
            </div>
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">ข้ามสะสม (ไม่มี DOI/ผู้แต่ง)</div>
                <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo number_format($sync_stats['total_skipped']); ?></div>
            </div>
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">เวลาเฉลี่ยต่อครั้ง</div>
                <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo $sync_stats['avg_duration_seconds'] !== null ? number_format((float)$sync_stats['avg_duration_seconds']) . ' วิ' : '-'; ?></div>
            </div>
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.75rem; color: var(--color-text-muted);">ซิงค์ล่าสุดเมื่อ</div>
                <div style="font-size: 1rem; font-weight: 700; font-family: var(--font-eng);"><?php echo $sync_stats['last_run_at'] ? format_thai_datetime($sync_stats['last_run_at']) : '-'; ?></div>
            </div>
        </div>

        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted);">
                    <th style="padding: 10px 8px;">เริ่มเมื่อ</th>
                    <th style="padding: 10px 8px;">สถานะ</th>
                    <th style="padding: 10px 8px;">ผู้สั่ง</th>
                    <th style="padding: 10px 8px; text-align: right;">นักวิจัย</th>
                    <th style="padding: 10px 8px; text-align: right;">ผลงานที่ซิงค์</th>
                    <th style="padding: 10px 8px; text-align: right;">ข้าม</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_sync_runs as $run):
                    $status_color = ['success' => 'var(--color-success)', 'partial' => '#eab308', 'failed' => '#f87171', 'running' => '#60a5fa'][$run['status']] ?? 'var(--color-text-muted)';
                    $status_label = ['success' => 'สำเร็จ', 'partial' => 'สำเร็จบางส่วน', 'failed' => 'ล้มเหลว', 'running' => 'กำลังทำงาน'][$run['status']] ?? htmlspecialchars($run['status']);
                ?>
                    <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05);">
                        <td style="padding: 10px 8px;"><?php echo format_thai_datetime($run['started_at']); ?></td>
                        <td style="padding: 10px 8px; color: <?php echo $status_color; ?>; font-weight: 600;"><?php echo $status_label; ?></td>
                        <td style="padding: 10px 8px; color: var(--color-text-muted);"><?php echo htmlspecialchars($run['triggered_by'] ?? '-'); ?></td>
                        <td style="padding: 10px 8px; text-align: right;"><?php echo number_format($run['researchers_processed']); ?></td>
                        <td style="padding: 10px 8px; text-align: right;"><?php echo number_format($run['publications_synced']); ?></td>
                        <td style="padding: 10px 8px; text-align: right;"><?php echo number_format($run['records_skipped']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color: var(--color-text-muted); text-align: center; padding: 20px;">ยังไม่มีประวัติการซิงค์ในระบบ</p>
    <?php endif; ?>
</div>

<div class="main-grid" style="grid-template-columns: 320px 1fr;">
    <!-- Left panel: Trigger Sync & Instructions -->
    <div class="sidebar glass-panel">
        <div class="sidebar-title">
            <span>ควบคุมการซิงค์</span>
            <i class="fa-solid fa-rotate-right" style="color: var(--color-primary);"></i>
        </div>

        <div style="margin-bottom: 25px;">
            <button type="button" id="sync-all-btn" class="btn-premium" style="width: 100%; justify-content: center; padding: 14px; font-size: 1rem;">
                <i class="fa-solid fa-cloud-arrow-down"></i> ซิงค์ของทุกคนพร้อมกัน
            </button>
        </div>

        <div style="margin-bottom: 25px; border-top: 1px solid var(--border-glass); padding-top: 20px;">
            <h4 style="color: var(--color-text-main); margin-bottom: 12px; font-size: 0.9rem;">
                <i class="fa-solid fa-user-gear"></i> ซิงค์เฉพาะบางรายชื่อ
            </h4>
            <div style="margin-bottom: 12px;">
                <label style="font-size: 0.75rem; color: var(--color-text-muted); display: block; margin-bottom: 6px;">เลือกนักวิจัย</label>
                <select id="target_researcher_id" class="search-input" style="padding: 8px 12px; height: auto;" required>
                    <option value="">-- เลือกนักวิจัย --</option>
                    <?php foreach ($all_researchers_list as $res): ?>
                        <option value="<?php echo $res['id']; ?>">
                            <?php echo htmlspecialchars($res['title_th'] . ' ' . $res['first_name_th'] . ' ' . $res['last_name_th']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" id="sync-individual-btn" class="btn-premium" style="width: 100%; justify-content: center; padding: 12px;">
                <i class="fa-solid fa-rotate"></i> เริ่มซิงค์เฉพาะรายบุคคล
            </button>
        </div>

        <button type="button" id="sync-stop-btn" class="btn-premium" style="width: 100%; justify-content: center; padding: 10px; margin-bottom: 25px; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #f87171; display: none;">
            <i class="fa-solid fa-stop"></i> หยุดการซิงค์
        </button>


        <div style="border-top: 1px solid var(--border-glass); padding-top: 20px; margin-bottom: 25px;">
            <h4 style="color: var(--color-text-main); margin-bottom: 12px; font-size: 0.9rem;">
                <i class="fa-solid fa-broom"></i> ล้างข้อมูลผลงานที่ไม่มีนักวิจัยเชื่อมโยง (Orphan Cleanup)
            </h4>
            <p style="font-size: 0.75rem; color: var(--color-text-muted); line-height: 1.5; margin-bottom: 12px;">
                ลบเฉพาะรายการผลงานที่ไม่ได้ผูกกับนักวิจัยคนใดเลยในระบบ
                <strong>ไม่กระทบผลงานที่มีนักวิจัยเชื่อมโยงอยู่</strong>
                — ควรรันตอนไม่มีการซิงค์อื่นทำงานพร้อมกัน
            </p>

            <?php if ($orphan_preview_count !== null): ?>
                <div style="background: rgba(255,255,255,0.03); border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: 0.85rem;">
                    พบรายการที่ไม่มีการเชื่อมโยง:
                    <strong style="color: <?php echo $orphan_preview_count > 0 ? 'var(--color-accent)' : 'var(--color-success)'; ?>;">
                        <?php echo $orphan_preview_count; ?> รายการ
                    </strong>
                </div>
            <?php endif; ?>

            <?php if ($cleanup_result !== null): ?>
                <div style="background: rgba(46, 160, 67, 0.12); border: 1px solid var(--color-success); border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: 0.85rem; color: var(--color-success);">
                    <i class="fa-solid fa-check"></i> ลบสำเร็จ <?php echo (int)$cleanup_result; ?> รายการ
                </div>
            <?php endif; ?>

            <a href="?preview_cleanup=1" class="btn-premium" style="width: 100%; justify-content: center; padding: 10px; margin-bottom: 10px; text-decoration: none; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-magnifying-glass"></i> ดูจำนวนก่อนลบ
            </a>

            <?php if ($orphan_preview_count !== null && $orphan_preview_count > 0): ?>
                <form method="POST" onsubmit="return confirm('ยืนยันลบผลงานที่ไม่มีนักวิจัยเชื่อมโยงจำนวน <?php echo (int)$orphan_preview_count; ?> รายการ? การกระทำนี้ย้อนกลับไม่ได้');">
                    <input type="hidden" name="cleanup_confirm" value="yes">
                    <button type="submit" name="trigger_cleanup" class="btn-premium" style="width: 100%; justify-content: center; padding: 10px; background: var(--color-danger, #a33);">
                        <i class="fa-solid fa-trash"></i> ยืนยันลบ <?php echo (int)$orphan_preview_count; ?> รายการ
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div style="border-top: 1px solid var(--border-glass); padding-top: 20px; font-size: 0.85rem; color: var(--color-text-muted); line-height: 1.5;">
            <h4 style="color: var(--color-text-main); margin-bottom: 10px;">การตั้งค่าคิวงานอัตโนมัติ (Scheduler)</h4>
            <p>คุณสามารถตั้งเวลาให้ระบบอัปเดตข้อมูลทุกคืนโดยใช้ Windows Task Scheduler บน IIS Server ให้เรียกเส้นทางดังนี้ (แนะนำ — ไม่ต้องใช้ token เลยเพราะรันจากเครื่อง server โดยตรงผ่าน CLI):</p>
            <div style="background: rgba(0,0,0,0.2); padding: 8px; border-radius: 6px; font-family: monospace; font-size: 0.75rem; margin: 8px 0; color: var(--color-accent); word-break: break-all;">
                C:\php\php.exe -f C:\inetpub\wwwroot\msc_research\admin\sync.php
            </div>
            <p>
                หากจำเป็นต้องเรียกผ่าน HTTP แทน (เช่นใช้บริการ cron ภายนอกที่ยิง URL) ให้กำหนดค่า
                <code>CRON_SYNC_TOKEN</code> ของคุณเองในไฟล์ <code>config/db.php</code>
                (สุ่มค่าใหม่ อย่าใช้ค่าตัวอย่างเดิม และห้าม commit ค่านี้ขึ้น git)
                แล้วเรียก:
            </p>
            <div style="background: rgba(0,0,0,0.2); padding: 8px; border-radius: 6px; font-family: monospace; font-size: 0.75rem; margin: 8px 0; color: var(--color-accent); word-break: break-all;">
                https://www.medsci.up.ac.th/msc_research/admin/sync.php?token=&lt;YOUR_CRON_SYNC_TOKEN&gt;
            </div>
            <p style="color: #d99; font-size: 0.8rem;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                ⚠️ อย่าใส่ token จริงลงในโค้ด, เอกสาร, หรือหน้าเว็บนี้ — token คือรหัสผ่านของ endpoint นี้
            </p>
        </div>
    </div>

    <!-- Right panel: Live Progress -->
    <div class="glass-panel" style="padding: 24px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px;">
            <i class="fa-solid fa-terminal" style="color: var(--color-accent);"></i> สถานะการซิงค์ (session นี้)
        </h3>

        <div id="sync-idle" style="text-align: center; color: var(--color-text-muted); padding: 40px;">
            <i class="fa-solid fa-arrow-pointer" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
            เลือกการควบคุมซิงค์ทางซ้ายเพื่อเริ่มต้นดึงข้อมูลจาก API
        </div>

        <div id="sync-progress" style="display: none;">
            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 6px;">
                <span id="sync-status-text">กำลังเริ่มต้น...</span>
                <span id="sync-counter">0 / 0</span>
            </div>
            <div style="background: rgba(255,255,255,0.05); border-radius: 8px; height: 10px; overflow: hidden;">
                <div id="sync-bar" style="background: linear-gradient(90deg, var(--color-primary), var(--color-accent)); height: 100%; width: 0%; transition: width 0.2s ease;"></div>
            </div>

            <div style="background: rgba(255,255,255,0.03); border-radius: 8px; padding: 12px 15px; margin: 15px 0; font-size: 0.85rem; display: flex; gap: 20px; flex-wrap: wrap;">
                <span>นักวิจัยที่ประมวลผล: <strong id="sync-processed-count">0</strong></span>
                <span>ผลงานที่บันทึก/อัปเดต: <strong id="sync-pubs-count">0</strong></span>
                <span>ข้าม (ไม่มี DOI/ผู้แต่ง): <strong id="sync-skipped-count">0</strong></span>
                <span style="color: #f87171;">ผิดพลาด/ปฏิเสธ: <strong id="sync-error-count">0</strong></span>
            </div>

            <div id="sync-log" style="display: flex; flex-direction: column-reverse; gap: 15px; max-height: 480px; overflow-y: auto;"></div>
        </div>
    </div>
</div>

<script>
const SYNC_CSRF_TOKEN = "<?php echo htmlspecialchars(get_csrf_token()); ?>";
document.addEventListener('DOMContentLoaded', () => {
    const allBtn = document.getElementById('sync-all-btn');
    const individualBtn = document.getElementById('sync-individual-btn');
    const stopBtn = document.getElementById('sync-stop-btn');
    const targetSelect = document.getElementById('target_researcher_id');

    const idleBox = document.getElementById('sync-idle');
    const progressBox = document.getElementById('sync-progress');
    const statusText = document.getElementById('sync-status-text');
    const counterText = document.getElementById('sync-counter');
    const progressBar = document.getElementById('sync-bar');
    const processedCountEl = document.getElementById('sync-processed-count');
    const pubsCountEl = document.getElementById('sync-pubs-count');
    const skippedCountEl = document.getElementById('sync-skipped-count');
    const errorCountEl = document.getElementById('sync-error-count');
    const logBox = document.getElementById('sync-log');

    let isRunning = false;
    let shouldStop = false;

    function addLogCard(entry) {
        const card = document.createElement('div');
        card.style.background = 'rgba(255,255,255,0.02)';
        card.style.border = '1px solid ' + (entry.has_error ? 'var(--color-danger, #a33)' : 'var(--border-glass)');
        card.style.borderRadius = '8px';
        card.style.padding = '15px';

        const title = document.createElement('h4');
        title.style.color = 'var(--color-primary)';
        title.style.marginBottom = '8px';
        title.style.display = 'flex';
        title.style.alignItems = 'center';
        title.style.gap = '8px';
        title.innerHTML = '<i class="fa-solid fa-user-circle"></i> ' + entry.researcher.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]));
        card.appendChild(title);

        const list = document.createElement('ul');
        list.style.listStyle = 'none';
        list.style.paddingLeft = '20px';
        list.style.fontSize = '0.85rem';
        list.style.color = 'var(--color-text-muted)';
        list.style.lineHeight = '1.6';
        (entry.details || []).forEach(detail => {
            const isFailure = detail.indexOf('ล้มเหลว') !== -1 || detail.indexOf('ข้าม:') === 0;
            const li = document.createElement('li');
            const icon = document.createElement('i');
            icon.className = 'fa-solid ' + (isFailure ? 'fa-xmark' : 'fa-check');
            icon.style.color = isFailure ? 'var(--color-danger, #c55)' : 'var(--color-success)';
            icon.style.marginRight = '6px';
            li.appendChild(icon);
            li.appendChild(document.createTextNode(detail));
            list.appendChild(li);
        });
        card.appendChild(list);
        logBox.appendChild(card);
    }

    async function runSync(targetId) {
        if (isRunning) return;
        isRunning = true;
        shouldStop = false;

        allBtn.disabled = true;
        individualBtn.disabled = true;
        stopBtn.style.display = 'inline-flex';
        idleBox.style.display = 'none';
        progressBox.style.display = 'block';
        logBox.innerHTML = '';

        let processed = 0, pubsSynced = 0, skipped = 0, errors = 0;
        processedCountEl.textContent = '0';
        pubsCountEl.textContent = '0';
        skippedCountEl.textContent = '0';
        errorCountEl.textContent = '0';
        const collectedLogs = [];
        let syncLogId = null;
        let stoppedEarly = false;

        statusText.textContent = 'กำลังเตรียมรายชื่อนักวิจัย...';

        try {
            const startResp = await fetch('sync_ajax.php?action=start' + (targetId ? '&id=' + encodeURIComponent(targetId) : ''), {
                method: 'POST',
                headers: { 'X-CSRF-Token': SYNC_CSRF_TOKEN }
            });
            const startData = await startResp.json();
            if (!startResp.ok) {
                throw new Error(startData.error || 'ไม่สามารถเริ่มการซิงค์ได้');
            }
            syncLogId = startData.sync_log_id;
            const items = startData.items || [];
            const total = items.length;

            if (total === 0) {
                statusText.textContent = 'ไม่พบนักวิจัยที่จะซิงค์';
            }

            for (let i = 0; i < total; i++) {
                if (shouldStop) {
                    stoppedEarly = true;
                    statusText.textContent = 'ผู้ใช้สั่งหยุด';
                    break;
                }
                const item = items[i];
                const current = i + 1;
                const pct = Math.round((current / total) * 100);
                counterText.textContent = `${current} / ${total} (${pct}%)`;
                progressBar.style.width = pct + '%';
                statusText.textContent = `กำลังซิงค์ (${current}/${total}): ${item.name}`;

                try {
                    const resp = await fetch('sync_ajax.php?action=process&id=' + encodeURIComponent(item.id), {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': SYNC_CSRF_TOKEN }
                    });
                    const res = await resp.json();
                    if (!resp.ok) {
                        throw new Error(res.error || 'เกิดข้อผิดพลาด');
                    }

                    processed++;
                    pubsSynced += res.publications_synced || 0;
                    skipped += res.records_skipped || 0;
                    if (res.has_error) errors++;

                    processedCountEl.textContent = processed;
                    pubsCountEl.textContent = pubsSynced;
                    skippedCountEl.textContent = skipped;
                    errorCountEl.textContent = errors;

                    collectedLogs.push({ researcher: res.researcher, details: res.details, has_error: res.has_error });
                    addLogCard({ researcher: res.researcher, details: res.details, has_error: res.has_error });
                } catch (err) {
                    processed++;
                    errors++;
                    errorCountEl.textContent = errors;
                    processedCountEl.textContent = processed;
                    const entry = { researcher: item.name, details: ['เกิดข้อผิดพลาดเครือข่าย: ' + err.message], has_error: true };
                    collectedLogs.push(entry);
                    addLogCard(entry);
                }
            }

            if (!stoppedEarly) {
                statusText.textContent = 'เสร็จสิ้นเรียบร้อย';
            }
        } catch (e) {
            statusText.textContent = 'เกิดข้อผิดพลาด: ' + e.message;
        } finally {
            if (syncLogId) {
                try {
                    const body = new URLSearchParams();
                    body.set('sync_log_id', syncLogId);
                    body.set('researchers_processed', processed);
                    body.set('publications_synced', pubsSynced);
                    body.set('records_skipped', skipped);
                    body.set('error_count', errors);
                    body.set('duplicate_count', collectedLogs.filter(l => (l.details || []).some(d => d.indexOf('ข้าม: Scopus Author ID') === 0)).length);
                    body.set('stopped_early', stoppedEarly ? '1' : '');
                    body.set('logs', JSON.stringify(collectedLogs));
                    await fetch('sync_ajax.php?action=finish', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': SYNC_CSRF_TOKEN, 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    });
                } catch (e) {
                    // Best-effort close-out; the 30-minute stale-lock check in
                    // open_sync_log() recovers even if this call itself fails.
                }
            }
            isRunning = false;
            stopBtn.style.display = 'none';
            allBtn.disabled = false;
            individualBtn.disabled = false;
        }
    }

    allBtn.addEventListener('click', () => runSync(null));
    individualBtn.addEventListener('click', () => {
        const id = targetSelect.value;
        if (!id) {
            alert('กรุณาเลือกนักวิจัยก่อน');
            return;
        }
        runSync(id);
    });
    stopBtn.addEventListener('click', () => { shouldStop = true; });

    // admin/researchers.php links its per-row "Sync" button to
    // sync.php?id=<researcher id> expecting an immediate sync - preserve
    // that entry point by auto-selecting and auto-starting on load.
    const preselectId = new URLSearchParams(window.location.search).get('id');
    if (preselectId) {
        targetSelect.value = preselectId;
        runSync(preselectId);
    }
});
</script>

<?php
require_once __DIR__ . '/admin_footer.php';
?>