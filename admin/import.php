<?php
// admin/import.php
// Manual Single Publication Input & CSV Import Interface

$current_page = 'admin_import';
$page_title = 'นำเข้าผลงานแบบกำหนดเอง - Admin Panel';

require_once __DIR__ . '/admin_header.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$message = '';
$message_type = 'success';

// Fetch all researchers to populate drop-downs
$researchers = get_all_researchers($pdo, false); // admin manages everyone, including inactive

// 1. Process Single Manual Publication Insert
if (isset($_POST['import_single'])) {
    $researcher_id = (int)$_POST['researcher_id'];
    $title = trim($_POST['title']);
    $authors = trim($_POST['authors']);
    $journal_name = !empty($_POST['journal_name']) ? trim($_POST['journal_name']) : null;
    $publish_year = (int)$_POST['publish_year'];
    $publish_date = !empty($_POST['publish_date']) ? $_POST['publish_date'] : null;
    $doi = !empty($_POST['doi']) ? trim($_POST['doi']) : null;
    $citation_count = (int)($_POST['citation_count'] ?? 0);
    $url = !empty($_POST['url']) ? trim($_POST['url']) : null;
    $abstract = !empty($_POST['abstract']) ? trim($_POST['abstract']) : null;
    $journal_issn = !empty($_POST['journal_issn']) ? trim($_POST['journal_issn']) : null;
    $quartile = !empty($_POST['quartile']) ? trim($_POST['quartile']) : null;
    $countries = !empty($_POST['countries']) ? trim($_POST['countries']) : null;
    $funding_sponsor = !empty($_POST['funding_sponsor']) ? trim($_POST['funding_sponsor']) : null;
    $funding_no = !empty($_POST['funding_no']) ? trim($_POST['funding_no']) : null;
    $funding_amount = !empty($_POST['funding_amount']) && is_numeric($_POST['funding_amount']) ? (float)$_POST['funding_amount'] : null;
    $sdg_primary = !empty($_POST['sdg_primary']) ? trim($_POST['sdg_primary']) : null;
    $sdg_secondary = !empty($_POST['sdg_secondary']) ? trim($_POST['sdg_secondary']) : null;
    $sdg_tertiary = !empty($_POST['sdg_tertiary']) ? trim($_POST['sdg_tertiary']) : null;
    $sdg_rationale = !empty($_POST['sdg_rationale']) ? trim($_POST['sdg_rationale']) : null;
    $corresponding_author_id = !empty($_POST['corresponding_author_id']) ? (int)$_POST['corresponding_author_id'] : null;

    if (empty($title) || empty($authors) || empty($publish_year) || empty($researcher_id)) {
        $message = "กรุณากรอกฟิลด์สำคัญให้ครบถ้วน (ชื่อเรื่อง, ผู้แต่ง, ปีพิมพ์ และ เลือกนักวิจัย)";
        $message_type = "error";
    } else {
        $data = [
            'title' => $title,
            'authors' => $authors,
            'journal_name' => $journal_name,
            'journal_issn' => $journal_issn,
            'quartile' => $quartile,
            'countries' => $countries,
            'corresponding_author_id' => $corresponding_author_id,
            'funding_sponsor' => $funding_sponsor,
            'funding_no' => $funding_no,
            'funding_amount' => $funding_amount,
            'sdg_primary' => $sdg_primary,
            'sdg_secondary' => $sdg_secondary,
            'sdg_tertiary' => $sdg_tertiary,
            'sdg_rationale' => $sdg_rationale,
            'publish_year' => $publish_year,
            'publish_date' => $publish_date,
            'doi' => $doi,
            'citation_count' => $citation_count,
            'source' => 'manual',
            'url' => $url,
            'abstract' => $abstract
        ];
        
        $success = add_or_update_publication($pdo, $data, $researcher_id);
        if ($success) {
            $message = "บันทึกและเชื่อมโยงผลงานวิจัยแบบกำหนดเองเรียบร้อยแล้ว";
            $message_type = "success";
        } else {
            $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูลผลงาน";
            $message_type = "error";
        }
    }
}

// 2. Process CSV File Import
if (isset($_POST['import_csv'])) {
    $researcher_id = (int)$_POST['csv_researcher_id'];
    
    if (empty($researcher_id)) {
        $message = "กรุณาเลือกนักวิจัยที่ต้องการเชื่อมโยงผลงานก่อนทำการนำเข้า";
        $message_type = "error";
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $file_path = $_FILES['csv_file']['tmp_name'];
        
        if (($handle = fopen($file_path, "r")) !== FALSE) {
            // Read header row
            $header = fgetcsv($handle, 1000, ",");
            
            // Map header column names to lowercase keys
            $header_map = [];
            foreach ($header as $key => $val) {
                // Remove BOM if present and sanitize
                $cleaned_val = preg_replace('/[\x{FEFF}\x{FFFE}]/u', '', $val);
                $header_map[strtolower(trim($cleaned_val))] = $key;
            }
            
            // Expected headers: title, authors, journal, year, doi, url, citations
            $success_count = 0;
            $fail_count = 0;
            
            while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                // Helper closure to get index or fallback
                $valOf = function($col) use ($header_map, $row) {
                    return isset($header_map[$col]) ? ($row[$header_map[$col]] ?? null) : null;
                };
                
                $title = $valOf('title') ?? $valOf('title/name') ?? $valOf('name');
                $authors = $valOf('authors') ?? $valOf('author');
                $publish_year = $valOf('year') ?? $valOf('publish_year');
                
                if (empty($title) || empty($authors)) {
                    $fail_count++;
                    continue; // Skip invalid rows
                }
                
                $data = [
                    'title' => trim($title),
                    'authors' => trim($authors),
                    'journal_name' => $valOf('journal') ?? $valOf('journal_name') ?? $valOf('publisher'),
                    'journal_issn' => $valOf('issn') ?? $valOf('journal_issn'),
                    'quartile' => $valOf('quartile') ?? $valOf('sjr_quartile') ?? $valOf('sjr q'),
                    'countries' => $valOf('countries') ?? $valOf('country'),
                    'funding_sponsor' => $valOf('funding_sponsor') ?? $valOf('funding') ?? $valOf('sponsor'),
                    'funding_no' => $valOf('funding_no') ?? $valOf('grant_no') ?? $valOf('grant_number') ?? $valOf('grant_no'),
                    'publish_year' => (int)($publish_year ?? date('Y')),
                    'publish_date' => $valOf('publish_date') ?? $valOf('date'),
                    'doi' => $valOf('doi'),
                    'citation_count' => (int)($valOf('citations') ?? $valOf('citation_count') ?? 0),
                    'source' => 'manual',
                    'url' => $valOf('url') ?? $valOf('link'),
                    'abstract' => $valOf('abstract') ?? $valOf('description')
                ];
                
                if (add_or_update_publication($pdo, $data, $researcher_id)) {
                    $success_count++;
                } else {
                    $fail_count++;
                }
            }
            fclose($handle);
            
            $message = "นำเข้าข้อมูลจากไฟล์ CSV สำเร็จ: นำเข้าสำเร็จ {$success_count} รายการ, ล้มเหลว {$fail_count} รายการ";
            $message_type = $fail_count > 0 ? "warning" : "success";
        } else {
            $message = "ไม่สามารถเปิดอ่านไฟล์อัปโหลดได้";
            $message_type = "error";
        }
    } else {
        $message = "มีข้อผิดพลาดในการอัปโหลดไฟล์ CSV";
        $message_type = "error";
    }
}
?>

<!-- Info Alerts -->
<?php if ($message): ?>
    <div class="glass-panel" style="padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; 
         background: <?php echo $message_type === 'success' ? 'rgba(16, 185, 129, 0.15)' : ($message_type === 'warning' ? 'rgba(245, 158, 11, 0.15)' : 'rgba(239, 68, 68, 0.15)'); ?>;
         border-color: <?php echo $message_type === 'success' ? '#10b981' : ($message_type === 'warning' ? '#f59e0b' : '#ef4444'); ?>;
         color: <?php echo $message_type === 'success' ? '#34d399' : ($message_type === 'warning' ? '#fbbf24' : '#f87171'); ?>;">
        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : ($message_type === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-exclamation'); ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="main-grid" style="grid-template-columns: 1fr 1fr;">
    <!-- Column 1: Manual Single Publication Entry -->
    <div class="glass-panel" style="padding: 24px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-square-plus" style="color: var(--color-primary);"></i>
            <span>กรอกข้อมูลทีละรายการ (Manual Input)</span>
        </h3>
        
        <form method="POST" style="display: flex; flex-direction: column; gap: 15px;">
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">เลือกนักวิจัยเจ้าของผลงาน *</label>
                <select name="researcher_id" class="search-input" style="padding: 8px 12px; height: auto;" required>
                    <option value="">-- เลือกนักวิจัย --</option>
                    <?php foreach ($researchers as $r): ?>
                        <option value="<?php echo $r['id']; ?>">
                            <?php echo htmlspecialchars(($r['title_th'] ?? '') . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']); ?> (<?php echo htmlspecialchars($r['department']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">ชื่อหัวข้อผลงานวิจัย (Title) *</label>
                <input type="text" name="title" class="search-input" style="padding: 8px 12px;" required placeholder="พิมพ์ชื่องานวิจัยตีพิมพ์...">
            </div>

            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">รายชื่อผู้แต่งทั้งหมด (Authors) *</label>
                <input type="text" name="authors" class="search-input" style="padding: 8px 12px;" required placeholder="Somchit D., Deejai M., Smith J. (คั่นด้วยจุลภาค)">
            </div>

            <div style="display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">วารสารที่ตีพิมพ์ (Journal Name)</label>
                    <input type="text" name="journal_name" class="search-input" style="padding: 8px 12px;" placeholder="เช่น Nature Medicine">
                </div>
                <div style="width: 100px;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">ปีที่ตีพิมพ์ *</label>
                    <input type="number" name="publish_year" class="search-input" style="padding: 8px 12px;" required value="<?php echo date('Y'); ?>" min="1900" max="2100">
                </div>
            </div>

            <div style="display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">เลข DOI (ถ้ามี)</label>
                    <input type="text" name="doi" class="search-input" style="padding: 8px 12px;" placeholder="10.1000/xyz123">
                </div>
                <div style="width: 120px;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">จำนวน Citations</label>
                    <input type="number" name="citation_count" class="search-input" style="padding: 8px 12px;" value="0" min="0">
                </div>
            </div>

            <div style="display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">ISSN วารสาร (ถ้ามี)</label>
                    <input type="text" name="journal_issn" class="search-input" style="padding: 8px 12px;" placeholder="เช่น 0021-9541">
                </div>
                <div style="width: 120px;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">Quartile (SJR)</label>
                    <select name="quartile" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25);">
                        <option value="">— ไม่ระบุ —</option>
                        <option value="Q1">Q1</option>
                        <option value="Q2">Q2</option>
                        <option value="Q3">Q3</option>
                        <option value="Q4">Q4</option>
                    </select>
                </div>
            </div>

            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">ประเทศของผู้แต่ง/สถาบันร่วมวิจัย (Countries)</label>
                <input type="text" name="countries" class="search-input" style="padding: 8px 12px;" placeholder="เช่น Thailand, Italy, United Kingdom (คั่นด้วยจุลภาค)">
            </div>

            <div style="display: flex; gap: 10px;">
                <div style="flex: 1.5;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">แหล่งทุนวิจัย (Funding Sponsor)</label>
                    <input type="text" name="funding_sponsor" class="search-input" style="padding: 8px 12px;" placeholder="เช่น University of Phayao">
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">เลขที่สัญญา/ทุน (Grant No.)</label>
                    <input type="text" name="funding_no" class="search-input" style="padding: 8px 12px;" placeholder="เช่น 5036/2567">
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">จำนวนเงินทุน (บาท)</label>
                    <input type="number" name="funding_amount" step="0.01" min="0" class="search-input" style="padding: 8px 12px;" placeholder="เช่น 50000">
                </div>
            </div>

            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">ผู้แต่งประสานงานในคณะฯ (Corresponding Author)</label>
                <select name="corresponding_author_id" class="search-input" style="padding: 8px 12px; height: auto;">
                    <option value="">— ไม่ระบุ / เป็นผู้แต่งภายนอก —</option>
                    <?php foreach ($researchers as $r): ?>
                        <option value="<?php echo $r['id']; ?>">
                            <?php echo htmlspecialchars(($r['title_th'] ?? '') . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']); ?> (<?php echo htmlspecialchars($r['department']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; gap: 10px;">
                <div style="flex: 1;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">SDG หลัก (Primary SDG)</label>
                    <select name="sdg_primary" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25);">
                        <option value="">— ไม่ระบุ —</option>
                        <?php foreach (get_all_sdgs() as $code => $details): ?>
                            <option value="<?php echo $code; ?>">
                                <?php echo $code . ': ' . htmlspecialchars($details['th_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">SDG รอง (Secondary SDG)</label>
                    <select name="sdg_secondary" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25);">
                        <option value="">— ไม่ระบุ —</option>
                        <?php foreach (get_all_sdgs() as $code => $details): ?>
                            <option value="<?php echo $code; ?>">
                                <?php echo $code . ': ' . htmlspecialchars($details['th_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 0.8rem; color: var(--color-text-muted);">SDG ลำดับ 3 (Tertiary SDG)</label>
                    <select name="sdg_tertiary" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25);">
                        <option value="">— ไม่ระบุ —</option>
                        <?php foreach (get_all_sdgs() as $code => $details): ?>
                            <option value="<?php echo $code; ?>">
                                <?php echo $code . ': ' . htmlspecialchars($details['th_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">เหตุผล/เกณฑ์ที่ใช้ประเมิน SDGs</label>
                <textarea name="sdg_rationale" class="search-input" rows="2" style="padding: 8px 12px; font-family: inherit; height: auto;" placeholder="ระบุเหตุผลเกณฑ์ประกอบตัวอย่าง: วัณโรค = โรคติดต่อ ตรงกับ target 3.3"></textarea>
            </div>

            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">ลิงก์ผลงาน (URL)</label>
                <input type="url" name="url" class="search-input" style="padding: 8px 12px;" placeholder="https://doi.org/10.1000/xyz123">
            </div>

            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">บทคัดย่อ (Abstract)</label>
                <textarea name="abstract" class="search-input" style="padding: 8px 12px; height: 100px; resize: vertical;" placeholder="พิมพ์บทคัดย่องานวิจัยที่นี่..."></textarea>
            </div>

            <div style="margin-top: 10px;">
                <button type="submit" name="import_single" class="btn-premium" style="width: 100%; justify-content: center; padding: 12px;">
                    <i class="fa-solid fa-circle-check"></i> บันทึกข้อมูลผลงาน
                </button>
            </div>
        </form>
    </div>

    <!-- Column 2: CSV File Import -->
    <div class="glass-panel" style="padding: 24px; align-self: start;">
        <h3 style="margin-bottom: 20px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-file-csv" style="color: var(--color-accent);"></i>
            <span>นำเข้าข้อมูลผ่านไฟล์ CSV</span>
        </h3>
        
        <form method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 20px;">
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">เลือกนักวิจัยเจ้าของผลงาน *</label>
                <select name="csv_researcher_id" class="search-input" style="padding: 8px 12px; height: auto;" required>
                    <option value="">-- เลือกนักวิจัย --</option>
                    <?php foreach ($researchers as $r): ?>
                        <option value="<?php echo $r['id']; ?>">
                            <?php echo htmlspecialchars(($r['title_th'] ?? '') . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="border: 2px dashed rgba(255,255,255,0.08); border-radius: 12px; padding: 30px; text-align: center; background: rgba(0,0,0,0.15);">
                <i class="fa-solid fa-cloud-arrow-up" style="font-size: 3rem; color: var(--color-primary); margin-bottom: 12px;"></i>
                <div style="font-size: 0.9rem; margin-bottom: 10px;">เลือกไฟล์ CSV จากเครื่องคอมพิวเตอร์ของคุณ</div>
                <input type="file" name="csv_file" accept=".csv" required style="display: inline-block; font-size: 0.85rem; color: var(--color-text-muted);">
            </div>

            <div style="font-size: 0.8rem; color: var(--color-text-muted); background: rgba(255,255,255,0.02); padding: 15px; border-radius: 8px; border-left: 3px solid var(--color-accent); line-height: 1.5;">
                <strong>โครงสร้างหัวตาราง CSV ที่ต้องการ (Header):</strong><br>
                หัวตารางต้องประกอบด้วยอย่างน้อย: <span style="font-family: monospace; color: var(--color-text-main);">title, authors, year</span><br>
                และสามารถเพิ่มเติมฟิลด์อื่นๆ ได้แก่: <span style="font-family: monospace; color: var(--color-text-main);">journal, doi, url, citations, abstract</span>
            </div>

            <div>
                <button type="submit" name="import_csv" class="btn-premium" style="width: 100%; justify-content: center; padding: 12px; background: linear-gradient(135deg, var(--color-accent), #d97706); color: #000; box-shadow: 0 4px 15px rgba(251, 191, 36, 0.3);">
                    <i class="fa-solid fa-upload"></i> เริ่มนำเข้าข้อมูลผลงาน
                </button>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
