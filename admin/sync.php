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
    run_synchronization($pdo, true);
    exit;
}

// --- Everything below this line requires an authenticated admin session ---
// (admin_header.php is expected to enforce login; sync.php performs no
// privileged action before that include is reached.)

$current_page = 'admin_sync';
$page_title = 'ซิงค์ข้อมูลผ่าน API - Admin Panel';

require_once __DIR__ . '/admin_header.php';

$sync_logs = [];
$sync_triggered = false;
$sync_type = 'individual';

// Get list of all researchers for the dropdown menu
$all_researchers_list = [];
try {
    $stmt = $pdo->query("SELECT id, title_th, first_name_th, last_name_th FROM `researchers` ORDER BY first_name_th ASC");
    $all_researchers_list = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to fetch researchers list for dropdown: " . $e->getMessage());
}

if (isset($_GET['id'])) {
    $sync_triggered = true;
    $sync_type = 'individual';
    $sync_logs = run_synchronization($pdo, false, (int)$_GET['id']);
}

if (isset($_POST['trigger_sync'])) {
    $sync_triggered = true;
    $sync_type = 'all';
    $sync_logs = run_synchronization($pdo, false);
}

if (isset($_POST['trigger_individual_sync'])) {
    $sync_triggered = true;
    $sync_type = 'individual';
    $target_id = (int)$_POST['target_researcher_id'];
    $sync_logs = run_synchronization($pdo, false, $target_id);
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
 * Runs the API synchronization process for researchers.
 *
 * Each data source (ORCID / PubMed / Scopus / Google Scholar) is wrapped in
 * its own try/catch so that one source failing (expired API key, network
 * timeout, rate limit, etc.) never blocks the remaining sources for the
 * same researcher, and never aborts the whole batch.
 *
 * A short delay is inserted between researchers (and could be extended
 * per-API-call if a specific provider proves rate-limit sensitive) to avoid
 * hammering external APIs when syncing many researchers in one run.
 */
function run_synchronization($pdo, $quiet = false, $target_researcher_id = null) {
    // Increase execution time as API calls take time.
    // For large researcher counts, consider moving this to a real background
    // queue/worker instead of a single long-running HTTP/CLI request.
    set_time_limit(0);
    if (!$quiet) {
        // Web-triggered runs still shouldn't hang the browser forever;
        // give them a generous but bounded limit.
        set_time_limit(300);
    }

    $logs = [];

    // Delay (seconds) between researchers to stay well under provider rate limits
    // (e.g. NCBI recommends max ~3 req/sec without an API key across ORCID+PubMed calls).
    // Note: PHP does not allow `const` inside a function body, so a plain variable is used.
    $inter_researcher_delay_seconds = 1;

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
        if ($quiet) {
            echo $msg . "\n";
        } else {
            $logs[] = ["researcher" => "-", "details" => [$msg], "has_error" => true];
        }
        return $logs;
    }

    $total = count($researchers);
    $index = 0;

    foreach ($researchers as $r) {
        $index++;
        $name = trim($r['title_th'] . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']);
        $logs_entry = ["researcher" => $name, "details" => [], "has_error" => false];

        // 1. Sync ORCID
        if (!empty($r['orcid_id'])) {
            try {
                $pubs = fetch_orcid_publications($r['orcid_id']);
                $success = 0;
                foreach ($pubs as $pub) {
                    if (add_or_update_publication($pdo, $pub, $r['id'])) {
                        $success++;
                    }
                }
                $logs_entry["details"][] = "ORCID: ดึงสำเร็จ " . count($pubs) . " รายการ (บันทึก/อัปเดตแล้ว {$success} รายการ)";
            } catch (Throwable $e) {
                $logs_entry["has_error"] = true;
                $logs_entry["details"][] = "ORCID: ล้มเหลว - " . $e->getMessage();
                error_log("[sync][ORCID][researcher {$r['id']}] " . $e->getMessage());
            }
        }


        // 3. Sync Scopus
        if (!empty($r['scopus_author_id'])) {
            try {
                $scopus_key = defined('SCOPUS_API_KEY') ? SCOPUS_API_KEY : null;
                if (!$scopus_key) {
                    throw new RuntimeException('ไม่พบ SCOPUS_API_KEY ในการตั้งค่าระบบ');
                }
                $pubs = fetch_scopus_publications($r['scopus_author_id'], $scopus_key);
                $success = 0;
                foreach ($pubs as $pub) {
                    if (add_or_update_publication($pdo, $pub, $r['id'])) {
                        $success++;
                    }
                }
                $logs_entry["details"][] = "Scopus: ดึงสำเร็จ " . count($pubs) . " รายการ (บันทึก/อัปเดตแล้ว {$success} รายการ)";
            } catch (Throwable $e) {
                $logs_entry["has_error"] = true;
                $logs_entry["details"][] = "Scopus: ล้มเหลว - " . $e->getMessage();
                error_log("[sync][Scopus][researcher {$r['id']}] " . $e->getMessage());
            }
        }

        // 4. Sync Google Scholar
        if (!empty($r['google_scholar_id'])) {
            try {
                $pubs = fetch_scholar_publications($r['google_scholar_id']);
                $success = 0;
                foreach ($pubs as $pub) {
                    if (add_or_update_publication($pdo, $pub, $r['id'])) {
                        $success++;
                    }
                }
                $logs_entry["details"][] = "Google Scholar: ดึงสำเร็จ " . count($pubs) . " รายการ (บันทึก/อัปเดตแล้ว {$success} รายการ)";
            } catch (Throwable $e) {
                $logs_entry["has_error"] = true;
                $logs_entry["details"][] = "Google Scholar: ล้มเหลว - " . $e->getMessage();
                error_log("[sync][GoogleScholar][researcher {$r['id']}] " . $e->getMessage());
            }
        }

        if (empty($logs_entry["details"])) {
            $logs_entry["details"][] = "ไม่มีรหัสสำหรับเชื่อมต่อ API";
        }

        if ($quiet) {
            echo "Researcher: {$name}\n";
            foreach ($logs_entry["details"] as $detail) {
                echo " - {$detail}\n";
            }
            echo "\n";
        } else {
            $logs[] = $logs_entry;
        }

        // Rate limiting: pause briefly between researchers so a large batch
        // doesn't fire a burst of requests at ORCID/PubMed/Scopus back-to-back.
        // Skipped after the very last researcher.
        if ($index < $total) {
            sleep($inter_researcher_delay_seconds);
        }
    }

    return $logs;
}
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>ซิงค์ข้อมูลผ่าน API อัตโนมัติ</h2>
    <p>ระบบจะเรียกใช้งานบริการ API ภายนอกเพื่อดาวน์โหลดผลงานล่าสุดและดัชนีการอ้างอิงของนักวิจัยแต่ละท่านเข้ามายังระบบฐานข้อมูลหลังบ้านโดยอัตโนมัติ</p>
</div>

<div class="main-grid" style="grid-template-columns: 320px 1fr;">
    <!-- Left panel: Trigger Sync & Instructions -->
    <div class="sidebar glass-panel">
        <div class="sidebar-title">
            <span>ควบคุมการซิงค์</span>
            <i class="fa-solid fa-rotate-right" style="color: var(--color-primary);"></i>
        </div>

        <form method="POST" style="margin-bottom: 25px;">
            <button type="submit" name="trigger_sync" class="btn-premium" style="width: 100%; justify-content: center; padding: 14px; font-size: 1rem;">
                <i class="fa-solid fa-cloud-arrow-down"></i> ซิงค์ของทุกคนพร้อมกัน
            </button>
        </form>

        <form method="POST" style="margin-bottom: 25px; border-top: 1px solid var(--border-glass); padding-top: 20px;">
            <h4 style="color: var(--color-text-main); margin-bottom: 12px; font-size: 0.9rem;">
                <i class="fa-solid fa-user-gear"></i> ซิงค์เฉพาะบางรายชื่อ
            </h4>
            <div style="margin-bottom: 12px;">
                <label style="font-size: 0.75rem; color: var(--color-text-muted); display: block; margin-bottom: 6px;">เลือกนักวิจัย</label>
                <select name="target_researcher_id" class="search-input" style="padding: 8px 12px; height: auto;" required>
                    <option value="">-- เลือกนักวิจัย --</option>
                    <?php foreach ($all_researchers_list as $res): ?>
                        <option value="<?php echo $res['id']; ?>">
                            <?php echo htmlspecialchars($res['title_th'] . ' ' . $res['first_name_th'] . ' ' . $res['last_name_th']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="trigger_individual_sync" class="btn-premium" style="width: 100%; justify-content: center; padding: 12px;">
                <i class="fa-solid fa-rotate"></i> เริ่มซิงค์เฉพาะรายบุคคล
            </button>
        </form>


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

    <!-- Right panel: Live Logs -->
    <div class="glass-panel" style="padding: 24px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px;">
            <i class="fa-solid fa-terminal" style="color: var(--color-accent);"></i> ประวัติการซิงค์ข้อมูล
        </h3>

        <?php if (!$sync_triggered): ?>
            <div style="text-align: center; color: var(--color-text-muted); padding: 40px;">
                <i class="fa-solid fa-spinner" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
                เลือกการควบคุมซิงค์ทางซ้ายเพื่อเริ่มต้นดึงข้อมูลจาก API
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <?php foreach ($sync_logs as $log): ?>
                    <div style="background: rgba(255,255,255,0.02); border: 1px solid <?php echo !empty($log['has_error']) ? 'var(--color-danger, #a33)' : 'var(--border-glass)'; ?>; border-radius: 8px; padding: 15px;">
                        <h4 style="color: var(--color-primary); margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-user-circle"></i>
                            <?php echo htmlspecialchars($log['researcher']); ?>
                        </h4>
                        <ul style="list-style: none; padding-left: 20px; font-size: 0.85rem; color: var(--color-text-muted); line-height: 1.6;">
                            <?php foreach ($log['details'] as $detail):
                                $is_failure = (strpos($detail, 'ล้มเหลว') !== false); ?>
                                <li>
                                    <i class="fa-solid <?php echo $is_failure ? 'fa-xmark' : 'fa-check'; ?>" style="color: <?php echo $is_failure ? 'var(--color-danger, #c55)' : 'var(--color-success)'; ?>; margin-right: 6px;"></i>
                                    <?php echo htmlspecialchars($detail); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/admin_footer.php';
?>