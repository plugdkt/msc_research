<?php
// admin/corresponding_publications.php
// Manage Corresponding Author publications (Add, Edit, Delete)

$current_page = 'admin_corresponding';
$page_title = 'จัดการผู้แต่งประสานงาน - Admin Panel';

require_once __DIR__ . '/admin_header.php';
require_once __DIR__ . '/../config/db.php';

$message = '';
$message_type = 'success';

$edit_mode = false;
$edit_entry = null;

// Handle Delete Action
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM `corresponding_publications` WHERE id = ?");
        $stmt->execute([$delete_id]);
        $message = "ลบรายการผู้แต่งประสานงานเรียบร้อยแล้ว!";
        $message_type = 'success';
    } catch (PDOException $e) {
        $message = "เกิดข้อผิดพลาดในการลบ: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Handle Edit Mode Request
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM `corresponding_publications` WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_entry = $stmt->fetch();
        if ($edit_entry) {
            $edit_mode = true;
        }
    } catch (PDOException $e) {
        $message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Handle Form Submission (Save/Update or CSV Import)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['import_csv'])) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['csv_file']['tmp_name'];
            if (($handle = fopen($file, "r")) !== FALSE) {
                // Read header row
                $header = fgetcsv($handle, 1000, ",");
                if ($header !== FALSE) {
                    // Normalize header columns
                    $header = array_map(function($h) {
                        return strtolower(trim(preg_replace('/[\x{FEFF}\x{200B}]/u', '', $h))); // remove BOM
                    }, $header);
                    
                    // Helper to get index
                    $valOf = function($row, $colName) use ($header) {
                        $idx = array_search(strtolower($colName), $header);
                        return ($idx !== FALSE && isset($row[$idx])) ? trim($row[$idx]) : null;
                    };
                    
                    $success_count = 0;
                    $fail_count = 0;
                    
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO `corresponding_publications` (researcher_id, title, authors, url) 
                        VALUES (:researcher_id, :title, :authors, :url)
                    ");
                    
                    // Fetch all researchers mapping scopus_author_id to database id and names
                    $stmtResCheck = $pdo->query("SELECT id, scopus_author_id, title_th, first_name_th, last_name_th FROM `researchers` WHERE scopus_author_id IS NOT NULL AND scopus_author_id != ''");
                    $scopus_map = [];
                    $scopus_names = [];
                    while ($r = $stmtResCheck->fetch()) {
                        $s_id = trim($r['scopus_author_id']);
                        $scopus_map[$s_id] = (int)$r['id'];
                        $scopus_names[$s_id] = trim(($r['title_th'] ? $r['title_th'] . ' ' : '') . $r['first_name_th'] . ' ' . $r['last_name_th']);
                    }
                    
                    while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if (empty($row) || count($row) < 2) continue;
                        
                        $scopus_id = trim($valOf($row, 'scopus_id') ?? $valOf($row, 'scopus_author_id') ?? '');
                        $title = $valOf($row, 'title');
                        $url = $valOf($row, 'url');
                        $authors = $scopus_names[$scopus_id] ?? 'นักวิจัยในคณะ';
                        
                        if (isset($scopus_map[$scopus_id]) && !empty($title)) {
                            $stmtInsert->execute([
                                ':researcher_id' => $scopus_map[$scopus_id],
                                ':title' => $title,
                                ':authors' => $authors,
                                ':url' => $url
                            ]);
                            $success_count++;
                        } else {
                            $fail_count++;
                        }
                    }
                    
                    $message = "นำเข้าข้อมูลผู้แต่งประสานงานสำเร็จ: นำเข้าสำเร็จ {$success_count} รายการ, ล้มเหลว {$fail_count} รายการ";
                    $message_type = ($fail_count > 0) ? "warning" : "success";
                } else {
                    $message = "ไฟล์ CSV ไม่มีแถวหัวข้อ";
                    $message_type = "error";
                }
                fclose($handle);
            } else {
                $message = "ไม่สามารถเปิดอ่านไฟล์อัปโหลดได้";
                $message_type = "error";
            }
        } else {
            $message = "มีข้อผิดพลาดในการอัปโหลดไฟล์ CSV";
            $message_type = "error";
        }
    } else {
        // Manual Save/Update
        $entry_id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $researcher_id = isset($_POST['researcher_id']) ? (int)$_POST['researcher_id'] : 0;
        $title = trim($_POST['title'] ?? '');
        $authors = trim($_POST['authors'] ?? '');
        $url = trim($_POST['url'] ?? '');
        
        if ($researcher_id <= 0) {
            $message = "กรุณาเลือกนักวิจัย";
            $message_type = 'error';
        } elseif (empty($title)) {
            $message = "กรุณากรอกชื่อเรื่องผลงานวิจัย";
            $message_type = 'error';
        } elseif (empty($authors)) {
            $message = "กรุณากรอกชื่อผู้แต่ง";
            $message_type = 'error';
        } else {
            try {
                if ($entry_id) {
                    // Update Existing
                    $stmt = $pdo->prepare("
                        UPDATE `corresponding_publications` 
                        SET researcher_id = :researcher_id, 
                            title = :title, 
                            authors = :authors, 
                            url = :url
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':researcher_id' => $researcher_id,
                        ':title' => $title,
                        ':authors' => $authors,
                        ':url' => $url,
                        ':id' => $entry_id
                    ]);
                    $message = "อัปเดตข้อมูลผู้แต่งประสานงานเรียบร้อยแล้ว!";
                    $message_type = 'success';
                    $edit_mode = false;
                } else {
                    // Insert New
                    $stmt = $pdo->prepare("
                        INSERT INTO `corresponding_publications` (researcher_id, title, authors, url) 
                        VALUES (:researcher_id, :title, :authors, :url)
                    ");
                    $stmt->execute([
                        ':researcher_id' => $researcher_id,
                        ':title' => $title,
                        ':authors' => $authors,
                        ':url' => $url
                    ]);
                    $message = "เพิ่มข้อมูลผู้แต่งประสานงานเรียบร้อยแล้ว!";
                    $message_type = 'success';
                }
            } catch (PDOException $e) {
                $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

// Fetch all researchers for dropdown
$researchers = [];
try {
    $stmt = $pdo->query("SELECT id, title_th, first_name_th, last_name_th, scopus_author_id FROM `researchers` ORDER BY first_name_th ASC");
    $researchers = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to fetch researchers list: " . $e->getMessage());
}

// Fetch all corresponding publications
$entries = [];
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
try {
    if (!empty($search_query)) {
        $stmt = $pdo->prepare("
            SELECT cp.*, r.title_th, r.first_name_th, r.last_name_th 
            FROM `corresponding_publications` cp
            JOIN `researchers` r ON cp.researcher_id = r.id
            WHERE cp.title LIKE :search OR cp.authors LIKE :search OR r.first_name_th LIKE :search OR r.last_name_th LIKE :search
            ORDER BY cp.id DESC
        ");
        $stmt->execute([':search' => "%{$search_query}%"]);
        $entries = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query("
            SELECT cp.*, r.title_th, r.first_name_th, r.last_name_th 
            FROM `corresponding_publications` cp
            JOIN `researchers` r ON cp.researcher_id = r.id
            ORDER BY cp.id DESC
        ");
        $entries = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $message = "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $message_type = 'error';
}
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>จัดการข้อมูลผู้ประพันธ์บรรณกิจ (Corresponding Author)</h2>
    <p>ระบบบันทึกแยกข้อมูลเพื่อระบุนักวิจัยที่เป็นผู้แต่งประสานงาน (Corresponding Author) ในผลงานชิ้นต่างๆ</p>
</div>

<!-- Info Alerts -->
<?php if ($message): ?>
    <div class="glass-panel" style="padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; 
         background: <?php echo $message_type === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)'; ?>;
         border-left: 4px solid <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; color: var(--color-text-main);">
        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>" 
           style="color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>; margin-right: 8px;"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<style>
    .admin-flex-grid {
        display: grid; 
        grid-template-columns: 1fr 2.2fr; 
        gap: 30px; 
        align-items: start;
    }
    @media (max-width: 992px) {
        .admin-flex-grid {
            grid-template-columns: 1fr;
        }
        .admin-flex-grid > div {
            position: static !important; /* disable sticky positioning on smaller screens */
        }
    }
</style>

<div class="admin-flex-grid">
    <!-- Left column: Add/Edit Form -->
    <div class="glass-panel animate-fade-in" style="padding: 25px; position: sticky; top: 10px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; color: var(--color-primary); display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid <?php echo $edit_mode ? 'fa-edit' : 'fa-plus-circle'; ?>"></i>
            <?php echo $edit_mode ? 'แก้ไขข้อมูลผู้แต่งประสานงาน' : 'เพิ่มผลงานผู้แต่งประสานงาน'; ?>
        </h3>
        
        <form method="POST" action="corresponding_publications.php">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_entry['id']); ?>">
            <?php endif; ?>
            
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 0.9rem; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px;">นักวิจัยของคณะ:</label>
                <select name="researcher_id" style="width: 100%; padding: 10px; background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-glass); border-radius: 8px; color: var(--color-text-main); font-size: 0.9rem; outline: none; cursor: pointer;">
                    <option value="0">-- เลือกนักวิจัย --</option>
                    <?php foreach ($researchers as $r): ?>
                        <option value="<?php echo $r['id']; ?>" <?php echo ($edit_mode && $edit_entry['researcher_id'] == $r['id']) ? 'selected' : ''; ?>>
                            [Scopus: <?php echo htmlspecialchars($r['scopus_author_id'] ?: 'N/A'); ?>] <?php echo htmlspecialchars(($r['title_th'] ? $r['title_th'] . ' ' : '') . $r['first_name_th'] . ' ' . $r['last_name_th']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 0.9rem; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px;">ชื่อเรื่องผลงานวิจัย (Title):</label>
                <textarea name="title" rows="4" placeholder="พิมพ์ชื่อเรื่องงานวิจัย..." style="width: 100%; padding: 10px; background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-glass); border-radius: 8px; color: var(--color-text-main); font-size: 0.9rem; outline: none; resize: vertical;" required><?php echo $edit_mode ? htmlspecialchars($edit_entry['title']) : ''; ?></textarea>
            </div>
            
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 0.9rem; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px;">รายชื่อผู้แต่งทั้งหมด (Authors):</label>
                <input type="text" name="authors" placeholder="เช่น Somchit D, Wittaya M" value="<?php echo $edit_mode ? htmlspecialchars($edit_entry['authors']) : ''; ?>" style="width: 100%; padding: 10px; background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-glass); border-radius: 8px; color: var(--color-text-main); font-size: 0.9rem; outline: none;" required>
            </div>
            
            <div style="margin-bottom: 22px;">
                <label style="display: block; font-size: 0.9rem; font-weight: 500; color: var(--color-text-muted); margin-bottom: 8px;">ลิงก์ปลายทางผลงานวิจัย (URL):</label>
                <input type="url" name="url" placeholder="https://doi.org/..." value="<?php echo $edit_mode ? htmlspecialchars($edit_entry['url']) : ''; ?>" style="width: 100%; padding: 10px; background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-glass); border-radius: 8px; color: var(--color-text-main); font-size: 0.9rem; outline: none;">
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn" style="flex: 2; padding: 10px; font-size: 0.9rem; background: linear-gradient(135deg, var(--color-primary), #6b21a8); box-shadow: 0 4px 12px rgba(139, 92, 246, 0.2);">
                    <i class="fa-solid fa-save"></i> บันทึกข้อมูล
                </button>
                <?php if ($edit_mode): ?>
                    <a href="corresponding_publications.php" class="btn btn-secondary" style="flex: 1; padding: 10px; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; text-decoration: none; margin: 0; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--color-text-main);">
                        ยกเลิก
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <hr style="border: 0; border-top: 1px solid var(--border-glass); margin: 25px 0;">

        <h3 style="margin-bottom: 15px; font-weight: 600; color: var(--color-accent); display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-file-csv"></i>
            <span>นำเข้าข้อมูลผ่านไฟล์ CSV</span>
        </h3>
        
        <form method="POST" action="corresponding_publications.php" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 15px;">
            <div>
                <label style="display: block; font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 8px;">เลือกไฟล์ CSV (.csv):</label>
                <input type="file" name="csv_file" accept=".csv" required style="width: 100%; padding: 8px; background: rgba(0,0,0,0.25); border: 1px dashed var(--border-glass); border-radius: 8px; color: var(--color-text-main); font-size: 0.85rem; outline: none; cursor: pointer;">
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 5px;">
                <a href="corresponding_author_sample.csv" download class="btn" style="padding: 8px 12px; font-size: 0.8rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--color-text-muted); display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                    <i class="fa-solid fa-download"></i> ดาวน์โหลดตัวอย่าง CSV
                </a>
                <button type="submit" name="import_csv" class="btn" style="padding: 8px 16px; font-size: 0.8rem; background: linear-gradient(135deg, var(--color-accent), #db2777); box-shadow: 0 4px 12px rgba(219, 39, 119, 0.2);">
                    <i class="fa-solid fa-upload"></i> นำเข้าข้อมูล
                </button>
            </div>
        </form>
    </div>
    
    <!-- Right column: List table -->
    <div class="glass-panel animate-fade-in" style="padding: 25px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
            <h3 style="margin: 0; font-weight: 600;">
                <i class="fa-solid fa-list" style="color: var(--color-secondary);"></i> รายการผู้แต่งประสานงานสะสม (<?php echo count($entries); ?> รายการ)
            </h3>
            
            <!-- Search bar -->
            <form method="GET" action="corresponding_publications.php" style="position: relative; width: 240px;">
                <input type="text" name="search" class="search-input" placeholder="ค้นหาชื่อนักวิจัย, ผลงาน..." value="<?php echo htmlspecialchars($search_query); ?>" style="padding-left: 35px; width: 100%; font-size: 0.85rem;">
                <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.8rem;"></i>
            </form>
        </div>
        
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted); font-weight: 600;">
                        <th style="padding: 12px 10px; width: 180px;">นักวิจัย</th>
                        <th style="padding: 12px 10px;">ผลงานวิจัย</th>
                        <th style="padding: 12px 10px; width: 100px; text-align: center;">ลิงก์</th>
                        <th style="padding: 12px 10px; width: 120px; text-align: center;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="4" style="padding: 30px; text-align: center; color: var(--color-text-muted);">
                                <i class="fa-solid fa-folder-open" style="font-size: 2rem; display: block; margin-bottom: 10px; opacity: 0.5;"></i>
                                ไม่พบข้อมูลรายการผู้แต่งประสานงาน
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($entries as $item): ?>
                            <tr style="border-bottom: 1px solid var(--border-glass); transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.01)'" onmouseout="this.style.background='transparent'">
                                <td style="padding: 14px 10px; font-weight: 600; color: var(--color-primary);">
                                    <?php echo htmlspecialchars(($item['title_th'] ? $item['title_th'] . ' ' : '') . $item['first_name_th'] . ' ' . $item['last_name_th']); ?>
                                </td>
                                <td style="padding: 14px 10px;">
                                    <strong style="color: var(--color-text-main); font-size: 0.92rem;"><?php echo htmlspecialchars($item['title']); ?></strong>
                                    <div style="font-size: 0.78rem; color: var(--color-text-muted); margin-top: 5px;">
                                        <i class="fa-solid fa-users"></i> ผู้แต่ง: <?php echo htmlspecialchars($item['authors']); ?>
                                    </div>
                                </td>
                                <td style="padding: 14px 10px; text-align: center;">
                                    <?php if (!empty($item['url'])): ?>
                                        <a href="<?php echo htmlspecialchars($item['url']); ?>" target="_blank" class="badge" style="background: rgba(139,92,246,0.15); color: var(--color-primary); text-decoration: none;" onmouseover="this.style.background='var(--color-primary)'; this.style.color='white'" onmouseout="this.style.background='rgba(139,92,246,0.15)'; this.style.color='var(--color-primary)'">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Link
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--color-text-muted); font-size: 0.8rem;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 14px 10px; text-align: center;">
                                    <div style="display: flex; gap: 8px; justify-content: center;">
                                        <a href="corresponding_publications.php?edit=<?php echo $item['id']; ?>" class="badge" style="background: rgba(245,158,11,0.15); color: #f59e0b; text-decoration: none;" onmouseover="this.style.background='#f59e0b'; this.style.color='white'" onmouseout="this.style.background='rgba(245,158,11,0.15)'; this.style.color='#f59e0b'">
                                            <i class="fa-solid fa-edit"></i>
                                        </a>
                                        <a href="corresponding_publications.php?delete=<?php echo $item['id']; ?>" class="badge" style="background: rgba(239,68,68,0.15); color: #f87171; text-decoration: none;" onclick="return confirm('ยืนยันที่จะลบรายการผู้แต่งประสานงานชิ้นนี้ใช่หรือไม่?');" onmouseover="this.style.background='#ef4444'; this.style.color='white'" onmouseout="this.style.background='rgba(239,68,68,0.15)'; this.style.color='#f87171'">
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
</div>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
