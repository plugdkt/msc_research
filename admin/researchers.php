<?php
// admin/researchers.php
// Researcher CRUD Management (Popup Modal Form & Full-width Directory View)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SECURITY: this check MUST run before every action handler below
// (download, delete, toggle_active, save, import) - all of them mutate or
// expose data and none should be reachable without an admin session. This
// file historically required admin_header.php only at the very bottom (for
// the CSV download's raw Content-Type headers), which meant every action
// above that point ran completely unauthenticated. Verified live: an
// anonymous GET to researchers.php?toggle_active=<id> changed the row with
// no session at all. Fixed by gating here instead of relying on
// admin_header.php's later check.
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Must handle file download BEFORE any HTML output
require_once __DIR__ . '/../config/db.php';

if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="researchers_template.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, ['title_th','first_name_th','last_name_th','title_en','first_name_en','last_name_en','department','email','orcid_id','scopus_author_id','google_scholar_id']);
    fputcsv($out, ['ดร.','สมจิตร์','ดีใจ','Dr.','Somchit','Deejai','ภาควิชาสรีรวิทยา','somchit.de@up.ac.th','0000-0002-1825-0097','57204928100','_jxhdJgAAAAJ']);
    fputcsv($out, ['รศ.ดร.','วิมล','แสงเพชร','Assoc.Prof.Dr.','Wimon','Sangphet','ภาควิชากายวิภาคศาสตร์','wimon.sa@up.ac.th','','','']);
    fputcsv($out, ['ผศ.','ปรีชา','มีสุข','Asst.Prof.','Preecha','Meesuk','ภาควิชาชีวเคมี','preecha.me@up.ac.th','','','']);
    fclose($out);
    exit;
}

$current_page = 'admin_researchers';
$page_title = 'จัดการรายชื่อนักวิจัย - Admin Panel';

$message = '';
$message_type = 'success';

// RESEARCHER-05: toggle is_active instead of deleting, for researchers who
// left the faculty - their historical publications must keep counting in
// faculty-wide stats, which a hard delete would break (via the cascading
// researcher_publications cleanup below).
if (isset($_GET['toggle_active'])) {
    $toggle_id = (int)$_GET['toggle_active'];
    $tog_search = trim($_GET['search'] ?? '');
    $tog_dept   = trim($_GET['dept']   ?? '');
    $tog_type   = trim($_GET['type']   ?? '');
    $tog_qs     = http_build_query(array_filter(['search' => $tog_search, 'dept' => $tog_dept, 'type' => $tog_type]));
    $tog_suffix = $tog_qs ? '?' . $tog_qs : '';

    $stmt = $pdo->prepare("UPDATE `researchers` SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$toggle_id]);
    $_SESSION['admin_message'] = "อัปเดตสถานะนักวิจัยเรียบร้อยแล้ว";
    $_SESSION['admin_message_type'] = "success";
    header("Location: researchers.php" . $tog_suffix);
    exit;
}

// Handle delete action
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    // Read filter params for redirect (delete comes via GET)
    $del_search = trim($_GET['search'] ?? '');
    $del_dept   = trim($_GET['dept']   ?? '');
    $del_type   = trim($_GET['type']   ?? '');
    $del_qs     = http_build_query(array_filter(['search'=>$del_search,'dept'=>$del_dept,'type'=>$del_type]));
    $del_suffix = $del_qs ? '?' . $del_qs : '';

    try {
        $pdo->beginTransaction();
        
        // Remove researcher-publication links
        $stmtRel = $pdo->prepare("DELETE FROM `researcher_publications` WHERE researcher_id = ?");
        $stmtRel->execute([$delete_id]);
        
        // Delete researcher
        $stmt = $pdo->prepare("DELETE FROM `researchers` WHERE id = ?");
        $stmt->execute([$delete_id]);
        
        // Cleanup orphaned publications
        $pdo->exec("
            DELETE FROM `publications` 
            WHERE `id` NOT IN (SELECT DISTINCT `publication_id` FROM `researcher_publications`)
        ");
        
        $pdo->commit();
        $_SESSION['admin_message'] = "ลบข้อมูลนักวิจัยเรียบร้อยแล้ว";
        $_SESSION['admin_message_type'] = "success";
        header("Location: researchers.php" . $del_suffix);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "เกิดข้อผิดพลาดในการลบข้อมูล: " . $e->getMessage();
        $message_type = "error";
    }
}

// Read filter params early so they survive the save redirect
$filter_search = trim($_GET['search'] ?? $_POST['_filter_search'] ?? '');
$filter_dept   = trim($_GET['dept']   ?? $_POST['_filter_dept']   ?? '');
$filter_type   = trim($_GET['type']   ?? $_POST['_filter_type']   ?? '');

// Build query-string to carry filters back after save/delete
$filter_qs = http_build_query(array_filter([
    'search' => $filter_search,
    'dept'   => $filter_dept,
    'type'   => $filter_type,
]));
$filter_suffix = $filter_qs ? '?' . $filter_qs : '';

// Handle add / edit action
$edit_mode = false;
$edit_r = [];

if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM `researchers` WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_r = $stmt->fetch();
    if ($edit_r) {
        $edit_mode = true;
    }
}

if (isset($_POST['save_researcher'])) {
    $researcher_id = !empty($_POST['researcher_id']) ? (int)$_POST['researcher_id'] : null;
    $title_th = trim($_POST['title_th']);
    $first_name_th = trim($_POST['first_name_th']);
    $last_name_th = trim($_POST['last_name_th']);
    $title_en = trim($_POST['title_en']);
    $first_name_en = trim($_POST['first_name_en']);
    $last_name_en = trim($_POST['last_name_en']);
    $orcid_id = !empty($_POST['orcid_id']) ? trim($_POST['orcid_id']) : null;
    $scopus_author_id = !empty($_POST['scopus_author_id']) ? trim($_POST['scopus_author_id']) : null;
    $google_scholar_id = !empty($_POST['google_scholar_id']) ? trim($_POST['google_scholar_id']) : null;
    $department = trim($_POST['department']);
    $email = !empty($_POST['email']) ? trim($_POST['email']) : null;
    $avatar_url = !empty($_POST['avatar_url']) ? trim($_POST['avatar_url']) : null;
    $remove_avatar = isset($_POST['remove_avatar']) && $_POST['remove_avatar'] == '1';

    // Handle avatar photo upload
    if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['avatar_file']['tmp_name'];
        $file_size = $_FILES['avatar_file']['size'];
        
        if ($file_size > 5 * 1024 * 1024) {
            $message = "ไฟล์รูปภาพมีขนาดใหญ่เกินไป (จำกัดไม่เกิน 5 MB)";
            $message_type = "error";
        } else {
            $image_info = @getimagesize($file_tmp);
            if ($image_info === false) {
                $message = "ไฟล์ที่อัปโหลดไม่ใช่รูปภาพที่ถูกต้อง";
                $message_type = "error";
            } else {
                $allowed_types = [
                    IMAGETYPE_JPEG => 'jpg',
                    IMAGETYPE_PNG => 'png',
                    IMAGETYPE_WEBP => 'webp',
                    IMAGETYPE_GIF => 'gif'
                ];
                $img_type = $image_info[2];
                if (!isset($allowed_types[$img_type])) {
                    $message = "รองรับเฉพาะไฟล์รูปภาพประเภท JPG, PNG, WEBP หรือ GIF เท่านั้น";
                    $message_type = "error";
                } else {
                    $ext = $allowed_types[$img_type];
                    $target_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
                    if (!is_dir($target_dir)) {
                        @mkdir($target_dir, 0777, true);
                    }
                    $unique_name = 'avatar_' . ($researcher_id ?: 'new') . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $target_path = $target_dir . $unique_name;
                    
                    $saved = @move_uploaded_file($file_tmp, $target_path);
                    if (!$saved && file_exists($file_tmp)) {
                        $saved = @copy($file_tmp, $target_path);
                    }

                    if ($saved && file_exists($target_path)) {
                        $avatar_url = 'uploads/avatars/' . $unique_name;
                    } else {
                        $last_err = error_get_last();
                        $err_detail = !empty($last_err['message']) ? " (" . $last_err['message'] . ")" : "";
                        $message = "ไม่สามารถบันทึกไฟล์รูปภาพลงเซิร์ฟเวอร์ได้" . $err_detail;
                        $message_type = "error";
                    }
                }
            }
        }
    } elseif ($remove_avatar) {
        $avatar_url = null;
    }
    $researcher_type = !empty($_POST['researcher_type']) ? trim($_POST['researcher_type']) : 'สายวิชาการ';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($first_name_th) || empty($last_name_th) || empty($first_name_en) || empty($last_name_en) || empty($department)) {
        $message = "กรุณากรอกฟิลด์บังคับให้ครบถ้วน (ชื่อ, นามสกุล และ ภาควิชา)";
        $message_type = "error";
    } elseif (empty($message_type) || $message_type !== "error") {
        try {
            // Clean/Sanitize IDs before saving
            if ($orcid_id) {
                $orcid_id = preg_replace('/[^0-9\-X]/i', '', $orcid_id);
            }
            if ($google_scholar_id) {
                if (preg_match('/user=([^&]+)/', $google_scholar_id, $matches)) {
                    $google_scholar_id = $matches[1];
                } else {
                    $parts = explode('&', $google_scholar_id);
                    $google_scholar_id = trim($parts[0]);
                }
            }

            if ($researcher_id) {
                // Update
                $stmt = $pdo->prepare("
                    UPDATE `researchers`
                    SET title_th = ?, first_name_th = ?, last_name_th = ?,
                        title_en = ?, first_name_en = ?, last_name_en = ?,
                        orcid_id = ?, scopus_author_id = ?, pubmed_query = NULL,
                        google_scholar_id = ?, department = ?, email = ?, avatar_url = ?,
                        researcher_type = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title_th, $first_name_th, $last_name_th,
                    $title_en, $first_name_en, $last_name_en,
                    $orcid_id, $scopus_author_id,
                    $google_scholar_id, $department, $email, $avatar_url,
                    $researcher_type, $is_active,
                    $researcher_id
                ]);
                $_SESSION['admin_message'] = "อัปเดตข้อมูลนักวิจัยสำเร็จ";
            } else {
                // Insert
                $stmt = $pdo->prepare("
                    INSERT INTO `researchers`
                    (title_th, first_name_th, last_name_th, title_en, first_name_en, last_name_en, orcid_id, scopus_author_id, pubmed_query, google_scholar_id, department, email, avatar_url, researcher_type, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $title_th, $first_name_th, $last_name_th,
                    $title_en, $first_name_en, $last_name_en,
                    $orcid_id, $scopus_author_id,
                    $google_scholar_id, $department, $email, $avatar_url,
                    $researcher_type, $is_active
                ]);
                $_SESSION['admin_message'] = "เพิ่มข้อมูลนักวิจัยเรียบร้อยแล้ว";
            }
            $_SESSION['admin_message_type'] = "success";
            header("Location: researchers.php" . $filter_suffix);
            exit;
        } catch (PDOException $e) {
            $message = "เกิดข้อผิดพลาดกับฐานข้อมูล: " . $e->getMessage();
            $message_type = "error";
        }
    }
}


// Handle CSV import
$import_result = null;
if (isset($_POST['import_csv']) && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');

    // Strip UTF-8 BOM if present
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);

    $header = fgetcsv($handle);
    $cols = array_map('trim', $header ?? []);

    $required = ['first_name_th', 'last_name_th', 'first_name_en', 'last_name_en', 'department'];
    $missing = array_diff($required, $cols);

    if ($missing) {
        $message = "ไฟล์ CSV ขาดคอลัมน์จำเป็น: " . implode(', ', $missing);
        $message_type = 'error';
    } else {
        $inserted = $updated = $skipped = $errors = 0;

        $idx = array_flip($cols);
        $get = function($row, $key) use ($idx) {
            return isset($idx[$key]) ? (trim($row[$idx[$key]]) ?: null) : null;
        };

        // Prepared statements
        $stmtFind = $pdo->prepare("
            SELECT * FROM `researchers`
            WHERE (first_name_en = ? AND last_name_en = ?)
               OR (first_name_th = ? AND last_name_th = ?)
            LIMIT 1
        ");
        $stmtInsert = $pdo->prepare("
            INSERT INTO `researchers`
            (title_th, first_name_th, last_name_th, title_en, first_name_en, last_name_en,
             department, email, orcid_id, scopus_author_id, pubmed_query, google_scholar_id, researcher_type)
            VALUES (?,?,?,?,?,?,?,?,?,?,NULL,?,?)
        ");

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row)) === 0) continue;

            $fn_th = $get($row, 'first_name_th');
            $ln_th = $get($row, 'last_name_th');
            $fn_en = $get($row, 'first_name_en');
            $ln_en = $get($row, 'last_name_en');
            $dept  = $get($row, 'department');

            if (!$fn_th || !$ln_th || !$fn_en || !$ln_en || !$dept) { $skipped++; continue; }

            try {
                // Try to find existing researcher by EN or TH name
                $stmtFind->execute([$fn_en, $ln_en, $fn_th, $ln_th]);
                $existing = $stmtFind->fetch();

                if ($existing) {
                    // Update only fields that are currently NULL in the database
                    $updates = [];
                    $params  = [];
                    $fillable = [
                        'title_th', 'title_en', 'department', 'email',
                        'orcid_id', 'scopus_author_id', 'google_scholar_id', 'researcher_type'
                    ];
                    foreach ($fillable as $col) {
                        $val = $get($row, $col);
                        // Only fill if DB value is empty AND CSV has a value
                        if (empty($existing[$col]) && !empty($val)) {
                            $updates[] = "`{$col}` = ?";
                            $params[]  = $val;
                        }
                    }
                    if ($updates) {
                        $params[] = $existing['id'];
                        $pdo->prepare("UPDATE `researchers` SET " . implode(', ', $updates) . " WHERE id = ?")
                            ->execute($params);
                        $updated++;
                    } else {
                        $skipped++; // nothing to update
                    }
                } else {
                    // New researcher → insert
                    $stmtInsert->execute([
                        $get($row,'title_th'), $fn_th, $ln_th,
                        $get($row,'title_en'), $fn_en, $ln_en,
                        $dept, $get($row,'email'),
                        $get($row,'orcid_id'), $get($row,'scopus_author_id'),
                        $get($row,'google_scholar_id'),
                        $get($row,'researcher_type') ?: 'สายวิชาการ'
                    ]);
                    $inserted++;
                }
            } catch (PDOException $e) { $errors++; }
        }
        fclose($handle);
        $_SESSION['admin_message'] = "นำเข้าเสร็จสิ้น: เพิ่มใหม่ {$inserted} ราย, อัปเดต {$updated} ราย, ข้าม {$skipped} แถว, ผิดพลาด {$errors} ราย";
        $_SESSION['admin_message_type'] = $errors > 0 ? 'error' : 'success';
        header("Location: researchers.php");
        exit;
    }
}

// Load session messages
if (isset($_SESSION['admin_message'])) {
    $message = $_SESSION['admin_message'];
    $message_type = $_SESSION['admin_message_type'] ?? 'success';
    unset($_SESSION['admin_message'], $_SESSION['admin_message_type']);
}

// Fetch distinct departments for filter dropdown
$departments = $pdo->query("SELECT DISTINCT department FROM `researchers` ORDER BY department ASC")->fetchAll(PDO::FETCH_COLUMN);

// Build filtered query

$where  = [];
$params = [];
if ($filter_search !== '') {
    $like = '%' . $filter_search . '%';
    $where[]  = "(first_name_th LIKE ? OR last_name_th LIKE ? OR first_name_en LIKE ? OR last_name_en LIKE ? OR CONCAT(title_th,' ',first_name_th,' ',last_name_th) LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($filter_dept !== '') {
    $where[]  = "department = ?";
    $params[] = $filter_dept;
}
if ($filter_type !== '') {
    $where[]  = "researcher_type = ?";
    $params[] = $filter_type;
}
$sql = "SELECT * FROM `researchers`" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY FIELD(researcher_type, 'สายวิชาการ', 'สายสนับสนุน'), first_name_th ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$researchers = $stmt->fetchAll();

$show_modal = $edit_mode || isset($_GET['add_new']);
$show_import = isset($_GET['import']);

require_once __DIR__ . '/admin_header.php';
?>

<style>
    /* Premium Popup Modal CSS */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.75);
        backdrop-filter: blur(8px);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        padding: 20px;
        animation: fadeIn 0.25s ease;
    }
    .modal-content {
        background: #0f172a;
        border: 1px solid var(--border-glass);
        border-radius: 20px;
        width: 100%;
        max-width: 550px;
        padding: 30px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
        position: relative;
        max-height: 90vh;
        overflow-y: auto;
        animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .modal-close-btn {
        position: absolute;
        top: 15px;
        right: 20px;
        font-size: 2rem;
        color: var(--color-text-muted);
        text-decoration: none;
        transition: var(--transition-smooth);
        line-height: 1;
        background: none;
        border: none;
        cursor: pointer;
    }
    .modal-close-btn:hover {
        color: var(--color-error);
    }
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @keyframes slideUp {
        from { transform: translateY(40px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
</style>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>จัดการรายชื่อนักวิจัยของคณะ (Researcher Management)</h2>
    <p>คุณสามารถลงทะเบียนนักวิจัยใหม่ แก้ไขประวัติส่วนตัว หรือเชื่อมต่อบัญชี APIs ต่างๆ เพื่อใช้ในการสืบค้นผลงานวิจัยของคณะได้โดยตรงที่นี่</p>
</div>

<!-- Info Alerts -->
<?php if ($message): ?>
    <div class="glass-panel animate-fade-in" style="padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; 
         background: <?php echo $message_type === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)'; ?>;
         border-color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;
         color: <?php echo $message_type === 'success' ? '#34d399' : '#f87171'; ?>;">
        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Main Directory List (Full Width) -->
<div class="glass-panel animate-fade-in" style="padding: 30px;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <h3 style="margin: 0; font-weight: 600;">
            <i class="fa-solid fa-users" style="color: var(--color-accent); margin-right: 8px;"></i>
            ทำเนียบรายชื่อบุคลากรวิจัย
            <span style="font-size: 0.8rem; font-weight: 400; color: var(--color-text-muted); margin-left: 10px;"><?php echo count($researchers); ?> ราย</span>
        </h3>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="researchers.php?import=1" class="btn-premium" style="padding: 10px 20px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; background: linear-gradient(135deg, #10b981, #059669); border-color: rgba(16,185,129,0.4);">
                <i class="fa-solid fa-file-csv"></i> Import CSV
            </a>
            <a href="researchers.php?add_new=1" class="btn-premium" style="padding: 10px 20px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 600;">
                <i class="fa-solid fa-user-plus"></i> ลงทะเบียนนักวิจัยใหม่
            </a>
        </div>
    </div>

    <!-- ── Filter Bar ─────────────────────────────────────────────────────── -->
    <form method="GET" action="researchers.php" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; align-items: flex-end;">
        <!-- Search input -->
        <div style="flex: 1; min-width: 200px;">
            <label style="font-size: 0.75rem; color: var(--color-text-muted); display: block; margin-bottom: 4px;">ค้นหาชื่อ</label>
            <div style="position: relative;">
                <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.85rem;"></i>
                <input type="text" name="search" class="search-input" style="padding: 8px 12px 8px 32px; width: 100%;"
                       placeholder="ชื่อ / นามสกุล (ไทย หรือ อังกฤษ)..."
                       value="<?php echo htmlspecialchars($filter_search); ?>">
            </div>
        </div>
        <!-- Department filter -->
        <div style="min-width: 180px;">
            <label style="font-size: 0.75rem; color: var(--color-text-muted); display: block; margin-bottom: 4px;">ภาควิชา</label>
            <select name="dept" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25); width: 100%;">
                <option value="">— ทั้งหมด —</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $filter_dept === $d ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($d); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Type filter -->
        <div style="min-width: 150px;">
            <label style="font-size: 0.75rem; color: var(--color-text-muted); display: block; margin-bottom: 4px;">ประเภท</label>
            <select name="type" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25); width: 100%;">
                <option value="">— ทั้งหมด —</option>
                <option value="สายวิชาการ"  <?php echo $filter_type === 'สายวิชาการ'  ? 'selected' : ''; ?>>สายวิชาการ</option>
                <option value="สายสนับสนุน" <?php echo $filter_type === 'สายสนับสนุน' ? 'selected' : ''; ?>>สายสนับสนุน</option>
            </select>
        </div>
        <!-- Buttons -->
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn-premium" style="padding: 8px 18px; font-size: 0.88rem; font-weight: 600; box-shadow: none;">
                <i class="fa-solid fa-filter"></i> กรอง
            </button>
            <?php if ($filter_search || $filter_dept || $filter_type): ?>
                <a href="researchers.php" class="btn-premium" style="padding: 8px 14px; font-size: 0.88rem; background: rgba(255,255,255,0.06); border-color: var(--border-glass); color: var(--color-text-muted); box-shadow: none;" title="ล้างตัวกรอง">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            <?php endif; ?>
        </div>
    </form>
    
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted);">
                    <th style="padding: 12px 8px;">ชื่อ-นามสกุล</th>
                    <th style="padding: 12px 8px; width: 120px;">ประเภท</th>
                    <th style="padding: 12px 8px; width: 160px;">ภาควิชา</th>
                    <th style="padding: 12px 8px; width: 140px;">ORCID ID</th>
                    <th style="padding: 12px 8px; width: 120px;">Scopus ID</th>
                    <th style="padding: 12px 8px; width: 120px;">Google Scholar ID</th>
                    <th style="padding: 12px 8px; text-align: center; width: 180px;">การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($researchers)): ?>
                    <tr>
                        <td colspan="7" style="padding: 40px; text-align: center; color: var(--color-text-muted);">
                            <i class="fa-regular fa-folder-open" style="font-size: 3rem; display: block; margin-bottom: 15px;"></i>
                            ยังไม่มีข้อมูลรายชื่อนักวิจัยในระบบฐานข้อมูล
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($researchers as $r): ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.01)'" onmouseout="this.style.background='none'">
                            <td style="padding: 12px 8px; font-weight: 500;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0;">
                                        <?php if (!empty($r['avatar_url'])): ?>
                                            <?php $avatar_src = preg_match('~^https?://~i', $r['avatar_url']) ? $r['avatar_url'] : '../' . ltrim($r['avatar_url'], '/'); ?>
                                            <img src="<?php echo htmlspecialchars($avatar_src); ?>" alt="avatar" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                            <i class="fa-solid fa-user-tie" style="display: none; color: var(--color-text-muted); font-size: 1rem;"></i>
                                        <?php else: ?>
                                            <i class="fa-solid fa-user-tie" style="color: var(--color-text-muted); font-size: 1rem;"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="color: var(--color-text-main); display: flex; align-items: center; gap: 6px;">
                                            <?php echo htmlspecialchars(($r['title_th'] ?? '') . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']); ?>
                                            <?php if (empty($r['is_active'])): ?>
                                                <span class="badge" style="background: rgba(148,163,184,0.15); color: #94a3b8; border: 1px solid rgba(148,163,184,0.3); font-size: 0.65rem; font-weight: 600;">พ้นสภาพ</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--color-text-muted); font-family: var(--font-eng);"><?php echo htmlspecialchars(($r['title_en'] ?? '') . ' ' . $r['first_name_en'] . ' ' . $r['last_name_en']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding: 12px 8px;">
                                <?php if ($r['researcher_type'] === 'สายสนับสนุน'): ?>
                                    <span class="badge" style="background: rgba(236, 72, 153, 0.15); color: #ec4899; border: 1px solid rgba(236,72,153,0.3); font-weight: 600;">สายสนับสนุน</span>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(139, 92, 246, 0.15); color: #a78bfa; border: 1px solid rgba(139,92,246,0.3); font-weight: 600;">สายวิชาการ</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 8px; color: var(--color-text-muted);">
                                <?php echo htmlspecialchars($r['department']); ?>
                            </td>
                            <td style="padding: 12px 8px; font-family: var(--font-eng); font-size: 0.8rem;">
                                <?php if (!empty($r['orcid_id'])): ?>
                                    <a href="https://orcid.org/<?php echo htmlspecialchars($r['orcid_id']); ?>" target="_blank" style="color: var(--color-primary); text-decoration: none;">
                                        <i class="fa-brands fa-orcid"></i> <?php echo htmlspecialchars($r['orcid_id']); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--color-text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 8px; font-family: var(--font-eng); font-size: 0.8rem;">
                                <?php if (!empty($r['scopus_author_id'])): ?>
                                    <a href="https://www.scopus.com/authid/detail.uri?authorId=<?php echo htmlspecialchars($r['scopus_author_id']); ?>" target="_blank" style="color: var(--color-accent); text-decoration: none;">
                                        <i class="fa-solid fa-graduation-cap"></i> <?php echo htmlspecialchars($r['scopus_author_id']); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--color-text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 8px; font-family: var(--font-eng); font-size: 0.8rem;">
                                <?php if (!empty($r['google_scholar_id'])): ?>
                                    <a href="https://scholar.google.com/citations?user=<?php echo htmlspecialchars($r['google_scholar_id']); ?>" target="_blank" style="color: #60a5fa; text-decoration: none;">
                                        <i class="fa-solid fa-graduation-cap"></i> <?php echo htmlspecialchars($r['google_scholar_id']); ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--color-text-muted);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 8px; text-align: center; white-space: nowrap;">
                                <div style="display: flex; gap: 6px; justify-content: center;">
                                    <a href="sync.php?id=<?php echo $r['id']; ?>" class="btn-premium" style="padding: 6px 12px; font-size: 0.75rem; font-weight: 500; background: linear-gradient(135deg, #10b981, #059669); border-color: rgba(16, 185, 129, 0.4); box-shadow: none;" title="ซิงค์ข้อมูลจาก API เฉพาะคนนี้">
                                        <i class="fa-solid fa-rotate animate-hover-spin"></i> Sync
                                    </a>
                                    <a href="researchers.php?edit=<?php echo $r['id']; ?><?php echo $filter_qs ? '&' . $filter_qs : ''; ?>" class="btn-premium" style="padding: 6px 10px; font-size: 0.75rem; font-weight: 500; background: linear-gradient(135deg, #3b82f6, #2563eb); box-shadow: none;" title="แก้ไขข้อมูล">
                                        <i class="fa-solid fa-edit"></i>
                                    </a>
                                    <a href="researchers.php?toggle_active=<?php echo $r['id']; ?><?php echo $filter_qs ? '&' . $filter_qs : ''; ?>" class="btn-premium" style="padding: 6px 10px; font-size: 0.75rem; font-weight: 500; background: <?php echo empty($r['is_active']) ? 'linear-gradient(135deg, #10b981, #059669)' : 'rgba(148,163,184,0.15)'; ?>; box-shadow: none;" title="<?php echo empty($r['is_active']) ? 'คืนสถานะปฏิบัติงาน' : 'ทำเครื่องหมายพ้นสภาพ (ไม่ลบข้อมูล)'; ?>">
                                        <i class="fa-solid <?php echo empty($r['is_active']) ? 'fa-user-check' : 'fa-user-slash'; ?>"></i>
                                    </a>
                                    <a href="researchers.php?delete=<?php echo $r['id']; ?><?php echo $filter_qs ? '&' . $filter_qs : ''; ?>" onclick="return confirm('ยืนยันลบนักวิจัยคนนี้? งานวิจัยที่ไม่มีผู้แต่งเชื่อมโยงเหลืออยู่จะถูกเคลียร์อัตโนมัติ')" class="btn-logout" style="padding: 6px 10px; font-size: 0.75rem; font-weight: 500; border-radius: 8px;" title="ลบรายชื่อ">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Popup Modal Window -->
<div id="researcherModal" class="modal-overlay" style="display: <?php echo $show_modal ? 'flex' : 'none'; ?>;">
    <div class="modal-content glass-panel" style="max-width: 720px; padding: 24px;">
        <a href="researchers.php<?php echo $filter_suffix; ?>" class="modal-close-btn">&times;</a>

        <h3 style="margin-bottom: 14px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid <?php echo $edit_mode ? 'fa-user-pen' : 'fa-user-plus'; ?>" style="color: var(--color-primary);"></i>
            <?php echo $edit_mode ? 'แก้ไขข้อมูลนักวิจัย' : 'ลงทะเบียนนักวิจัยใหม่'; ?>
        </h3>

        <form method="POST" action="researchers.php<?php echo $filter_suffix; ?>" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 9px;">
            <input type="hidden" name="researcher_id" value="<?php echo htmlspecialchars($edit_r['id'] ?? ''); ?>">
            <input type="hidden" name="_filter_search" value="<?php echo htmlspecialchars($filter_search); ?>">
            <input type="hidden" name="_filter_dept"   value="<?php echo htmlspecialchars($filter_dept); ?>">
            <input type="hidden" name="_filter_type"   value="<?php echo htmlspecialchars($filter_type); ?>">
            <input type="hidden" name="avatar_url" value="<?php echo htmlspecialchars($edit_r['avatar_url'] ?? ''); ?>">

            <!-- รูปถ่ายนักวิจัย (Photo / Avatar) -->
            <div style="display: flex; align-items: center; gap: 14px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 10px; padding: 10px 14px;">
                <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(0,0,0,0.3); border: 2px solid var(--color-primary); overflow: hidden; display: flex; align-items: center; justify-content: center; flex-shrink: 0; position: relative;">
                    <?php 
                    $current_avatar = '';
                    if (!empty($edit_r['avatar_url'])) {
                        $current_avatar = preg_match('~^https?://~i', $edit_r['avatar_url']) ? $edit_r['avatar_url'] : '../' . ltrim($edit_r['avatar_url'], '/');
                    }
                    ?>
                    <img id="avatar-preview" src="<?php echo htmlspecialchars($current_avatar); ?>" alt="avatar" style="width: 100%; height: 100%; object-fit: cover; <?php echo empty($current_avatar) ? 'display: none;' : ''; ?>">
                    <i id="avatar-placeholder" class="fa-solid fa-user-tie" style="font-size: 1.8rem; color: var(--color-text-muted); <?php echo !empty($current_avatar) ? 'display: none;' : ''; ?>"></i>
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 0.76rem; font-weight: 600; color: var(--color-text-main); display: block; margin-bottom: 4px;">
                        <i class="fa-solid fa-camera" style="color: var(--color-primary); margin-right: 4px;"></i> รูปถ่ายนักวิจัย (Photo / Avatar)
                    </label>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="file" name="avatar_file" id="avatar_file" accept="image/png, image/jpeg, image/webp, image/gif" style="font-size: 0.78rem; color: var(--color-text-muted); cursor: pointer;">
                        <?php if (!empty($edit_r['avatar_url'])): ?>
                            <label style="font-size: 0.72rem; color: #f87171; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.25); border-radius: 6px; padding: 2px 7px;">
                                <input type="checkbox" name="remove_avatar" value="1" id="remove_avatar_cb" style="accent-color: #ef4444; cursor: pointer;"> ลบรูปภาพปัจจุบัน
                            </label>
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 0.68rem; color: var(--color-text-muted); margin-top: 3px;">
                        รองรับไฟล์ JPG, PNG, WEBP, GIF (ขนาดไฟล์ไม่เกิน 5 MB)
                    </div>
                </div>
            </div>

            <!-- ชื่อ TH: คำนำหน้า + ชื่อ + นามสกุล -->
            <div style="display: grid; grid-template-columns: 80px 1fr 1fr; gap: 8px;">
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;">คำนำหน้า (TH)</label>
                    <input type="text" name="title_th" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['title_th'] ?? ''); ?>" placeholder="ดร.">
                </div>
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;">ชื่อ (TH) *</label>
                    <input type="text" name="first_name_th" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['first_name_th'] ?? ''); ?>" required placeholder="สมจิตร์">
                </div>
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;">นามสกุล (TH) *</label>
                    <input type="text" name="last_name_th" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['last_name_th'] ?? ''); ?>" required placeholder="ดีใจ">
                </div>
            </div>

            <!-- ชื่อ EN: คำนำหน้า + ชื่อ + นามสกุล -->
            <div style="display: grid; grid-template-columns: 80px 1fr 1fr; gap: 8px;">
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;">คำนำหน้า (EN)</label>
                    <input type="text" name="title_en" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['title_en'] ?? ''); ?>" placeholder="Dr.">
                </div>
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;">First Name (EN) *</label>
                    <input type="text" id="first_name_en" name="first_name_en" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['first_name_en'] ?? ''); ?>" required placeholder="Somchit">
                </div>
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;">Last Name (EN) *</label>
                    <input type="text" id="last_name_en" name="last_name_en" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['last_name_en'] ?? ''); ?>" required placeholder="Deejai">
                </div>
            </div>

            <!-- ภาควิชา + อีเมล + ประเภท -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 140px; gap: 8px;">
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;">ภาควิชา *</label>
                    <input type="text" name="department" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['department'] ?? ''); ?>" list="departments_list" required placeholder="ภาควิชา...">
                    <datalist id="departments_list">
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo htmlspecialchars($d); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;">อีเมล</label>
                    <input type="email" name="email" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['email'] ?? ''); ?>" placeholder="email@up.ac.th">
                </div>
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;">ประเภท *</label>
                    <select name="researcher_type" class="search-input" style="padding: 7px 10px; height: auto; background: rgba(0,0,0,0.25);" required>
                        <option value="สายวิชาการ"  <?php echo (($edit_r['researcher_type'] ?? '') !== 'สายสนับสนุน') ? 'selected' : ''; ?>>สายวิชาการ</option>
                        <option value="สายสนับสนุน" <?php echo (($edit_r['researcher_type'] ?? '') === 'สายสนับสนุน') ? 'selected' : ''; ?>>สายสนับสนุน</option>
                    </select>
                </div>
            </div>

            <!-- API IDs -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px;">
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;"><i class="fa-brands fa-orcid"></i> ORCID ID</label>
                    <input type="text" name="orcid_id" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['orcid_id'] ?? ''); ?>" placeholder="0000-0002-...">
                </div>
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;"><i class="fa-solid fa-graduation-cap"></i> Scopus Author ID</label>
                    <input type="text" name="scopus_author_id" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['scopus_author_id'] ?? ''); ?>" placeholder="57204928100">
                </div>
                <div>
                    <label style="font-size: 0.72rem; color: var(--color-text-muted); display: block; margin-bottom: 3px;"><i class="fa-brands fa-google"></i> Google Scholar ID</label>
                    <input type="text" name="google_scholar_id" class="search-input" style="padding: 7px 10px;" value="<?php echo htmlspecialchars($edit_r['google_scholar_id'] ?? ''); ?>" placeholder="_jxhdJgAAAAJ">
                </div>
            </div>

            <!-- RESEARCHER-05: active/inactive - inactive researchers are hidden
                 from the public directory, but their old publications still
                 count in faculty-wide statistics (not a delete). -->
            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: var(--color-text-muted); margin-top: 4px; cursor: pointer;">
                <input type="checkbox" name="is_active" value="1" <?php echo (!$edit_mode || !empty($edit_r['is_active'])) ? 'checked' : ''; ?> style="width: 16px; height: 16px; accent-color: var(--color-primary); cursor: pointer;">
                ยังปฏิบัติงานอยู่ (แสดงในทำเนียบนักวิจัยสาธารณะ)
            </label>

            <div style="display: flex; gap: 10px; margin-top: 6px;">
                <button type="submit" name="save_researcher" class="btn-premium" style="flex: 1; padding: 11px; justify-content: center; font-size: 0.92rem; font-weight: 600;">
                    <i class="fa-solid fa-save"></i> บันทึกข้อมูล
                </button>
                <a href="researchers.php<?php echo $filter_suffix; ?>" class="btn-premium" style="flex: 1; padding: 11px; text-align: center; text-decoration: none; background: rgba(255,255,255,0.05); border-color: var(--border-glass); color: var(--color-text-main); font-size: 0.92rem; font-weight: 600;">
                    ยกเลิก
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Import CSV Modal -->
<div id="importModal" class="modal-overlay" style="display: <?php echo $show_import ? 'flex' : 'none'; ?>;">
    <div class="modal-content glass-panel" style="max-width: 480px;">
        <a href="researchers.php" class="modal-close-btn">&times;</a>

        <h3 style="margin-bottom: 8px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-file-csv" style="color: #10b981;"></i> Import รายชื่อนักวิจัยจาก CSV
        </h3>
        <p style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 20px; line-height: 1.6;">
            ไฟล์ CSV ต้องมีหัวคอลัมน์ภาษาอังกฤษตามรูปแบบที่กำหนด ระบบรองรับทั้ง UTF-8 (BOM) และ UTF-8 ปกติ
            หากมีแถวซ้ำ (ORCID/Scopus ID เดิม) ระบบจะทำการอัปเดตข้อมูลให้อัตโนมัติ
        </p>

        <div style="background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2); border-radius: 10px; padding: 14px; margin-bottom: 20px; font-size: 0.8rem; color: #6ee7b7; font-family: var(--font-eng);">
            <strong style="color: #34d399; display: block; margin-bottom: 6px;">คอลัมน์ที่รองรับ:</strong>
            title_th, <strong>first_name_th*</strong>, <strong>last_name_th*</strong>, title_en,<br>
            <strong>first_name_en*</strong>, <strong>last_name_en*</strong>, <strong>department*</strong>,<br>
            email, orcid_id, scopus_author_id, google_scholar_id
        </div>

        <a href="researchers.php?download_sample=1" style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.85rem; color: var(--color-accent); text-decoration: none; margin-bottom: 20px; padding: 8px 14px; background: rgba(251,191,36,0.08); border: 1px solid rgba(251,191,36,0.2); border-radius: 8px; transition: all 0.2s;" onmouseover="this.style.background='rgba(251,191,36,0.15)'" onmouseout="this.style.background='rgba(251,191,36,0.08)'">
            <i class="fa-solid fa-download"></i> ดาวน์โหลดไฟล์ CSV ตัวอย่าง (Template)
        </a>

        <form method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 15px;">
            <div>
                <label style="font-size: 0.85rem; color: var(--color-text-muted); display: block; margin-bottom: 8px;">เลือกไฟล์ CSV</label>
                <input type="file" name="csv_file" accept=".csv,text/csv" required
                    style="width: 100%; padding: 10px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-glass); border-radius: 8px; color: var(--color-text-main); font-family: inherit; font-size: 0.9rem; cursor: pointer;">
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" name="import_csv" class="btn-premium" style="flex: 1; padding: 12px; justify-content: center; font-size: 0.95rem; font-weight: 600; background: linear-gradient(135deg,#10b981,#059669); border-color: rgba(16,185,129,0.4);">
                    <i class="fa-solid fa-upload"></i> เริ่มนำเข้าข้อมูล
                </button>
                <a href="researchers.php" class="btn-premium" style="flex: 1; padding: 12px; text-align: center; text-decoration: none; background: rgba(255,255,255,0.05); border-color: var(--border-glass); color: var(--color-text-main); font-size: 0.95rem; font-weight: 600;">
                    ยกเลิก
                </a>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') window.location.href = 'researchers.php';
    });

    const avatarInput = document.getElementById('avatar_file');
    const avatarPreview = document.getElementById('avatar-preview');
    const avatarPlaceholder = document.getElementById('avatar-placeholder');
    const removeAvatarCb = document.getElementById('remove_avatar_cb');

    if (avatarInput) {
        avatarInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const objectUrl = URL.createObjectURL(file);
                if (avatarPreview) {
                    avatarPreview.src = objectUrl;
                    avatarPreview.style.display = 'block';
                }
                if (avatarPlaceholder) {
                    avatarPlaceholder.style.display = 'none';
                }
                if (removeAvatarCb) {
                    removeAvatarCb.checked = false;
                }
            }
        });
    }

    if (removeAvatarCb) {
        removeAvatarCb.addEventListener('change', function() {
            if (this.checked) {
                if (avatarPreview) avatarPreview.style.display = 'none';
                if (avatarPlaceholder) avatarPlaceholder.style.display = 'block';
                if (avatarInput) avatarInput.value = '';
            } else {
                <?php if (!empty($current_avatar)): ?>
                    if (avatarPreview) {
                        avatarPreview.src = '<?php echo htmlspecialchars($current_avatar); ?>';
                        avatarPreview.style.display = 'block';
                    }
                    if (avatarPlaceholder) {
                        avatarPlaceholder.style.display = 'none';
                    }
                <?php endif; ?>
            }
        });
    }
</script>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
