<?php
// admin/sdg_import.php
// Import and map SDGs to existing publications via CSV upload or server seed

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'admin_sdgs';
$page_title = 'จัดการ SDGs (ผลงานวิจัย)';

require_once __DIR__ . '/admin_header.php';

$message = '';
$message_type = 'success';
$import_stats = null;

// Function to handle the CSV mapping process
function process_sdg_csv($pdo, $filePath) {
    if (!file_exists($filePath)) {
        throw new Exception("ไม่พบไฟล์ CSV บนเซิร์ฟเวอร์");
    }

    $file = fopen($filePath, 'r');
    if (!$file) {
        throw new Exception("ไม่สามารถเปิดอ่านไฟล์ CSV ได้");
    }

    // Skip BOM if present
    $bom = fread($file, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($file);
    }

    // Read headers
    $headers = fgetcsv($file);
    if (!$headers) {
        fclose($file);
        throw new Exception("ไฟล์ CSV ไม่มีข้อมูลหัวตาราง");
    }

    // Map headers to column indices
    // 1: Title, 8: DOI, 14: SDG Primary, 15: SDG Secondary, 16: Rationale
    // To be robust, let's normalize header titles
    $header_map = [];
    foreach ($headers as $index => $col_name) {
        $col_name = strtolower(trim(preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $col_name)));
        if (strpos($col_name, 'doi') !== false) {
            $header_map['doi'] = $index;
        } elseif (strpos($col_name, 'title') !== false || strpos($col_name, 'ชื่อผลงาน') !== false) {
            $header_map['title'] = $index;
        } elseif (strpos($col_name, 'sdg หลัก') !== false || strpos($col_name, 'sdg_primary') !== false) {
            $header_map['sdg_primary'] = $index;
        } elseif (strpos($col_name, 'sdg รอง') !== false || strpos($col_name, 'sdg_secondary') !== false) {
            $header_map['sdg_secondary'] = $index;
        } elseif (strpos($col_name, 'เหตุผล') !== false || strpos($col_name, 'rationale') !== false) {
            $header_map['sdg_rationale'] = $index;
        }
    }

    // Fallbacks if header names differ
    $doi_idx = $header_map['doi'] ?? 8;
    $title_idx = $header_map['title'] ?? 1;
    $sdg_primary_idx = $header_map['sdg_primary'] ?? 14;
    $sdg_secondary_idx = $header_map['sdg_secondary'] ?? 15;
    $rationale_idx = $header_map['sdg_rationale'] ?? 16;

    // Fetch existing publications for matching
    $stmt = $pdo->query("SELECT id, doi, title FROM publications");
    $db_pubs = $stmt->fetchAll();

    $db_dois = [];
    $db_titles = [];
    foreach ($db_pubs as $p) {
        if (!empty($p['doi'])) {
            $db_dois[strtolower(trim($p['doi']))] = $p['id'];
        }
        $clean_title = strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $p['title'])));
        if (!empty($clean_title)) {
            $db_titles[$clean_title] = $p['id'];
        }
    }

    $total_rows = 0;
    $matched_doi = 0;
    $matched_title = 0;
    $updated = 0;
    $errors = [];

    $pdo->beginTransaction();
    try {
        $stmtUpdate = $pdo->prepare("
            UPDATE `publications` 
            SET `sdg_primary` = :sdg_primary, 
                `sdg_secondary` = :sdg_secondary, 
                `sdg_rationale` = :sdg_rationale
            WHERE `id` = :id
        ");

        while (($row = fgetcsv($file)) !== false) {
            if (empty($row) || count($row) < 3) continue;

            $total_rows++;
            $title = $row[$title_idx] ?? '';
            $doi = $row[$doi_idx] ?? '';
            $sdg_primary = $row[$sdg_primary_idx] ?? '';
            $sdg_secondary = $row[$sdg_secondary_idx] ?? '';
            $rationale = $row[$rationale_idx] ?? '';

            // Clean inputs
            $doi = trim($doi);
            $title = trim($title);
            $sdg_primary = trim($sdg_primary);
            $sdg_secondary = trim($sdg_secondary);
            $rationale = trim($rationale);

            // Basic normalization (e.g. if CSV contains "SDG 3", keep it, otherwise format "SDG X")
            $normalize_sdg = function($val) {
                if (empty($val)) return null;
                $val = trim(str_replace(['(?)', '( ? )', '?'], '', $val));
                $val = trim(strtoupper($val));
                if (empty($val)) return null;
                if (preg_match('/^SDG\s*\d+$/', $val)) {
                    $num = (int)preg_replace('/[^0-9]/', '', $val);
                    return "SDG " . $num;
                }
                // Handle raw digits e.g. "3" -> "SDG 3"
                if (is_numeric($val) && (int)$val >= 1 && (int)$val <= 17) {
                    return "SDG " . (int)$val;
                }
                // Handle short codes e.g. "SDG3" -> "SDG 3"
                if (preg_match('/^SDG(\d+)$/', $val, $matches)) {
                    return "SDG " . (int)$matches[1];
                }
                return $val; // Keep as is if special
            };

            $sdg_primary = $normalize_sdg($sdg_primary);
            $sdg_secondary = $normalize_sdg($sdg_secondary);

            $matched_id = null;
            $match_method = '';

            // 1. Try matching by DOI
            if (!empty($doi)) {
                $clean_doi = strtolower($doi);
                if (isset($db_dois[$clean_doi])) {
                    $matched_id = $db_dois[$clean_doi];
                    $match_method = 'doi';
                }
            }

            // 2. Try matching by Title fallback
            if (!$matched_id && !empty($title)) {
                $clean_title_key = strtolower(trim(preg_replace('/[^a-z0-9]/i', '', $title)));
                if (isset($db_titles[$clean_title_key])) {
                    $matched_id = $db_titles[$clean_title_key];
                    $match_method = 'title';
                }
            }

            if ($matched_id) {
                if ($match_method === 'doi') $matched_doi++;
                if ($match_method === 'title') $matched_title++;

                $stmtUpdate->execute([
                    ':sdg_primary' => $sdg_primary ?: null,
                    ':sdg_secondary' => $sdg_secondary ?: null,
                    ':sdg_rationale' => $rationale ?: null,
                    ':id' => $matched_id
                ]);
                $updated++;
            } else {
                $errors[] = "แถวที่ {$total_rows}: ไม่พบงานวิจัยในระบบ (ชื่องาน: \"" . mb_strimwidth($title, 0, 50, '...') . "\", DOI: \"{$doi}\")";
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($file);
        throw $e;
    }

    fclose($file);

    return [
        'total_rows' => $total_rows,
        'matched_doi' => $matched_doi,
        'matched_title' => $matched_title,
        'updated' => $updated,
        'unmatched' => count($errors),
        'errors' => $errors
    ];
}

// Handle local server seed
if (isset($_POST['seed_sdgs'])) {
    try {
        $server_csv = __DIR__ . '/../SDGs/Duangjai_A.csv';
        $import_stats = process_sdg_csv($pdo, $server_csv);
        $message = "นำเข้าและอัปเดตข้อมูล SDGs จากไฟล์ตัวอย่างบนเซิร์ฟเวอร์เรียบร้อยแล้ว! (จับคู่สำเร็จ {$import_stats['updated']} รายการ)";
        $message_type = "success";
    } catch (Exception $e) {
        $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle CSV File Upload
if (isset($_POST['upload_csv'])) {
    if (isset($_FILES['sdg_file']) && $_FILES['sdg_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['sdg_file']['tmp_name'];
        try {
            $import_stats = process_sdg_csv($pdo, $tmpName);
            $message = "นำเข้าและอัปเดตข้อมูล SDGs จากไฟล์อัปโหลดเรียบร้อยแล้ว! (จับคู่สำเร็จ {$import_stats['updated']} รายการ)";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "เกิดข้อผิดพลาดในการนำเข้าไฟล์: " . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = "กรุณาเลือกไฟล์ CSV ที่ถูกต้องในการอัปโหลด";
        $message_type = "error";
    }
}
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>จัดการและนำเข้าเป้าหมายการพัฒนาที่ยั่งยืน (SDGs Mapping)</h2>
    <p>อัปเดตเป้าหมายหลักและเป้าหมายรองของ SDGs (Sustainable Development Goals) ให้กับบทความวิจัยที่อยู่ในระบบ</p>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $message_type; ?> animate-fade-in" style="margin-bottom: 25px; padding: 15px; border-radius: 10px; font-weight: 500;">
        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>" style="margin-right: 8px;"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div class="main-grid" style="grid-template-columns: 1fr 1.2fr; gap: 30px;">
    <!-- Left Panel: Import Forms -->
    <div style="display: flex; flex-direction: column; gap: 25px;">
        <!-- Option 1: Seed from Local File -->
        <div class="glass-panel" style="padding: 25px;">
            <h3 style="margin-bottom: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-database" style="color: var(--color-primary);"></i>
                <span>นำเข้าไฟล์ SDGs ในเซิร์ฟเวอร์</span>
            </h3>
            <p style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 20px; line-height: 1.5;">
                ระบบตรวจพบไฟล์ข้อมูล SDGs (`SDGs/Duangjai_A.csv`) บนระบบอยู่แล้ว สามารถกดปุ่มด้านล่างเพื่อเริ่มการจับคู่และบันทึกข้อมูล SDGs ทั้งหมดลงตารางบทความได้ทันที
            </p>
            <form method="POST" action="sdg_import.php">
                <button type="submit" name="seed_sdgs" class="btn" style="background: linear-gradient(135deg, var(--color-primary), var(--color-secondary)); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; transition: var(--transition-smooth);">
                    <i class="fa-solid fa-play"></i> เริ่มจับคู่และอัปเดตจากไฟล์บนเซิร์ฟเวอร์
                </button>
            </form>
        </div>

        <!-- Option 2: Upload CSV File -->
        <div class="glass-panel" style="padding: 25px;">
            <h3 style="margin-bottom: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-file-csv" style="color: var(--color-accent);"></i>
                <span>อัปโหลดไฟล์ CSV เพื่ออัปเดต</span>
            </h3>
            <p style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 20px; line-height: 1.5;">
                สำหรับอัปเดตข้อมูล SDGs ในอนาคต ให้จัดเตรียมไฟล์ CSV ที่มีคอลัมน์ชื่อผลงาน (`Title`), รหัสผลงาน (`DOI`), `SDG หลัก` และ `SDG รอง` เพื่อนำเข้าข้อมูล
            </p>
            <form method="POST" action="sdg_import.php" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 15px;">
                <div>
                    <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 6px;">เลือกไฟล์ CSV (.csv)</label>
                    <input type="file" name="sdg_file" accept=".csv" class="search-input" style="padding: 8px; height: auto;" required>
                </div>
                <button type="submit" name="upload_csv" class="btn" style="background: rgba(255,255,255,0.05); color: var(--color-text-main); border: 1px solid var(--border-glass); padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: var(--transition-smooth);" onmouseover="this.style.background='var(--color-accent)'; this.style.color='white'; this.style.borderColor='transparent';" onmouseout="this.style.background='rgba(255,255,255,0.05)'; this.style.color='var(--color-text-main)'; this.style.borderColor='var(--border-glass)';">
                    <i class="fa-solid fa-cloud-arrow-up"></i> อัปโหลดและประมวลผลไฟล์ CSV
                </button>
            </form>
        </div>
    </div>

    <!-- Right Panel: Import Results & Documentation -->
    <div class="glass-panel" style="padding: 25px; display: flex; flex-direction: column; gap: 20px;">
        <h3 style="margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px;">
            <i class="fa-solid fa-receipt" style="color: #10b981;"></i>
            <span>ผลการประมวลผลล่าลุด / โครงสร้างนำเข้า</span>
        </h3>

        <?php if ($import_stats): ?>
            <!-- Display Stats -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 12px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">จำนวนแถวทั้งหมดที่อ่าน</div>
                    <div style="font-size: 1.4rem; font-weight: 700; color: var(--color-text-main); font-family: var(--font-eng);"><?php echo $import_stats['total_rows']; ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 12px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">อัปเดตสำเร็จ</div>
                    <div style="font-size: 1.4rem; font-weight: 700; color: #10b981; font-family: var(--font-eng);"><?php echo $import_stats['updated']; ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 12px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">จับคู่ด้วย DOI</div>
                    <div style="font-size: 1.2rem; font-weight: 600; color: #60a5fa; font-family: var(--font-eng);"><?php echo $import_stats['matched_doi']; ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 12px; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">จับคู่ด้วยชื่อเรื่อง</div>
                    <div style="font-size: 1.2rem; font-weight: 600; color: #fb7185; font-family: var(--font-eng);"><?php echo $import_stats['matched_title']; ?></div>
                </div>
            </div>

            <?php if (!empty($import_stats['errors'])): ?>
                <div>
                    <h4 style="font-size: 0.85rem; color: #f87171; font-weight: 600; margin-bottom: 8px;">รายการที่ไม่พบในระบบ (<?php echo $import_stats['unmatched']; ?> รายการ):</h4>
                    <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.2); padding: 12px; border-radius: 8px; max-height: 200px; overflow-y: auto; font-family: var(--font-eng); font-size: 0.78rem; display: flex; flex-direction: column; gap: 6px; color: #fca5a5;">
                        <?php foreach ($import_stats['errors'] as $err): ?>
                            <div>• <?php echo htmlspecialchars($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <!-- Default structure guide -->
            <div style="font-size: 0.85rem; line-height: 1.6; color: var(--color-text-muted);">
                <p style="margin-bottom: 12px; font-weight: 500; color: var(--color-text-main);">คำแนะนำรูปแบบไฟล์ CSV:</p>
                <ul style="padding-left: 20px; display: flex; flex-direction: column; gap: 8px;">
                    <li>คอลัมน์จับคู่หลัก: ต้องมีคอลัมน์ชื่อ <strong>`DOI`</strong> หรือ <strong>`ชื่อผลงาน` (Title)</strong> สำหรับเปรียบเทียบหาตัวบทความในระบบ</li>
                    <li>คอลัมน์ระบุข้อมูล: คอลัมน์ระบุ SDGs ต้องมีคำว่า <strong>`SDG หลัก`</strong> (Primary) และ <strong>`SDG รอง`</strong> (Secondary)</li>
                    <li>รูปแบบของค่าข้อมูลเป้าหมาย: สามารถกรอกเป็นตัวเลขเปล่าๆ เช่น <code>3</code> หรือกรอกคำนำหน้า เช่น <code>SDG 3</code></li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
