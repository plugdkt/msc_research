<?php
// admin/publications.php
// Manage publications (Edit metadata, correct links, remove duplicates)

$current_page = 'admin_publications';
$page_title = 'จัดการผลงานวิจัย - Admin Panel';

require_once __DIR__ . '/admin_header.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$message = '';
$message_type = 'success';

$edit_mode = false;
$edit_pub = null;

// Handle Delete Action
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    try {
        $pdo->beginTransaction();
        
        // Remove relationships first
        $stmtRel = $pdo->prepare("DELETE FROM `researcher_publications` WHERE publication_id = ?");
        $stmtRel->execute([$delete_id]);
        
        // Remove the publication itself
        $stmtPub = $pdo->prepare("DELETE FROM `publications` WHERE id = ?");
        $stmtPub->execute([$delete_id]);
        
        $pdo->commit();
        $message = "ลบผลงานวิจัยออกจากระบบเรียบร้อยแล้ว!";
        $message_type = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "เกิดข้อผิดพลาดในการลบ: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Handle Edit Mode Request
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM `publications` WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_pub = $stmt->fetch();
    if ($edit_pub) {
        $edit_mode = true;
    }
}

// Handle Form Submission (Save/Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pub_id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $title = trim($_POST['title']);
    $authors = trim($_POST['authors']);
    $journal_name = trim($_POST['journal_name']);
    $publish_year = (int)$_POST['publish_year'];
    $publish_date = !empty($_POST['publish_date']) ? $_POST['publish_date'] : null;
    $doi = !empty($_POST['doi']) ? trim($_POST['doi']) : null;
    $url = !empty($_POST['url']) ? trim($_POST['url']) : null;
    $citation_count = (int)$_POST['citation_count'];
    $abstract = trim($_POST['abstract']);
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
    
    if (empty($title)) {
        $message = "กรุณากรอกชื่อเรื่องผลงานวิจัย";
        $message_type = 'error';
    } else {
        try {
            // Auto lookup quartile if ISSN is set but quartile is empty
            if ($journal_issn && empty($quartile)) {
                $clean_issn = preg_replace('/[^0-9X]/i', '', $journal_issn);
                $stmtFindQ = $pdo->prepare("SELECT quartile FROM `journal_quartiles` WHERE issn = ? AND quartile != '' LIMIT 1");
                $stmtFindQ->execute([$clean_issn]);
                $found_q = $stmtFindQ->fetchColumn();
                if ($found_q) {
                    $quartile = $found_q;
                }
            }

            if ($pub_id) {
                // Update Existing
                $stmt = $pdo->prepare("
                    UPDATE `publications` 
                    SET title = :title, 
                        authors = :authors, 
                        journal_name = :journal_name, 
                        journal_issn = :journal_issn,
                        quartile = :quartile,
                        countries = :countries,
                        corresponding_author_id = :corresponding_author_id,
                        funding_sponsor = :funding_sponsor,
                        funding_no = :funding_no,
                        funding_amount = :funding_amount,
                        sdg_primary = :sdg_primary,
                        sdg_secondary = :sdg_secondary,
                        sdg_tertiary = :sdg_tertiary,
                        sdg_rationale = :sdg_rationale,
                        publish_year = :publish_year, 
                        publish_date = :publish_date, 
                        doi = :doi, 
                        url = :url, 
                        citation_count = :citation_count, 
                        abstract = :abstract
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':title' => $title,
                    ':authors' => $authors,
                    ':journal_name' => $journal_name,
                    ':journal_issn' => $journal_issn,
                    ':quartile' => $quartile,
                    ':countries' => $countries,
                    ':corresponding_author_id' => $corresponding_author_id,
                    ':funding_sponsor' => $funding_sponsor,
                    ':funding_no' => $funding_no,
                    ':funding_amount' => $funding_amount,
                    ':sdg_primary' => $sdg_primary,
                    ':sdg_secondary' => $sdg_secondary,
                    ':sdg_tertiary' => $sdg_tertiary,
                    ':sdg_rationale' => $sdg_rationale,
                    ':publish_year' => $publish_year,
                    ':publish_date' => $publish_date,
                    ':doi' => $doi,
                    ':url' => $url,
                    ':citation_count' => $citation_count,
                    ':abstract' => $abstract,
                    ':id' => $pub_id
                ]);
                $message = "อัปเดตข้อมูลผลงานวิจัยเรียบร้อยแล้ว!";
                $message_type = 'success';
                $edit_mode = false;
            }
        } catch (PDOException $e) {
            $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Fetch all publications for the listing table
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_intl = isset($_GET['intl']) ? trim($_GET['intl']) : '';
$publications = [];

try {
    $where_clauses = [];
    $params = [];
    
    if (!empty($search_query)) {
        $where_clauses[] = "(p.title LIKE :search OR p.authors LIKE :search OR p.journal_name LIKE :search OR p.doi LIKE :search)";
        $params[':search'] = "%{$search_query}%";
    }
    
    if ($filter_intl === '1') {
        // Contains any country outside Thailand
        $where_clauses[] = "p.countries IS NOT NULL AND p.countries != '' AND REPLACE(REPLACE(REPLACE(p.countries, 'Thailand', ''), ' ', ''), ',', '') != ''";
    } elseif ($filter_intl === '0') {
        // Thailand only or empty
        $where_clauses[] = "(p.countries IS NULL OR p.countries = '' OR REPLACE(REPLACE(REPLACE(p.countries, 'Thailand', ''), ' ', ''), ',', '') = '')";
    }
    
    $where_sql = '';
    if (!empty($where_clauses)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    }
    
    $stmt = $pdo->prepare("
        SELECT p.*, 
               GROUP_CONCAT(CONCAT(COALESCE(r.title_th, ''), COALESCE(r.first_name_th, ''), ' ', COALESCE(r.last_name_th, '')) SEPARATOR ', ') as linked_researchers,
               CONCAT(COALESCE(ca.title_th, ''), COALESCE(ca.first_name_th, ''), ' ', COALESCE(ca.last_name_th, '')) as corresponding_author_name
        FROM `publications` p
        LEFT JOIN `researcher_publications` rp ON p.id = rp.publication_id
        LEFT JOIN `researchers` r ON rp.researcher_id = r.id
        LEFT JOIN `researchers` ca ON p.corresponding_author_id = ca.id
        $where_sql
        GROUP BY p.id
        ORDER BY p.publish_year DESC, p.id DESC
    ");
    $stmt->execute($params);
    $publications = $stmt->fetchAll();
    
    // Fetch all researchers for corresponding author selection
    $stmtRes = $pdo->query("SELECT id, title_th, first_name_th, last_name_th, department FROM `researchers` ORDER BY first_name_th ASC");
    $all_researchers = $stmtRes->fetchAll();
} catch (PDOException $e) {
    $message = "เกิดข้อผิดพลาดในการดึงข้อมูลสิ่งตีพิมพ์: " . $e->getMessage();
    $message_type = 'error';
}
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>จัดการรายการผลงานสิ่งตีพิมพ์ (Manage Publications)</h2>
    <p>คุณสามารถแก้ไขรายละเอียด บทคัดย่อ ค่าอ้างอิง และตัวชี้วัดลิงก์ปลายทางของบทความวิจัยที่อยู่ในฐานข้อมูลระบบได้ที่นี่</p>
</div>

<!-- Info Alerts -->
<?php if ($message): ?>
    <div class="glass-panel" style="padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; 
         background: <?php echo $message_type === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)'; ?>;
         border-color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;
         color: <?php echo $message_type === 'success' ? '#34d399' : '#f87171'; ?>;">
        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="main-grid" style="grid-template-columns: <?php echo $edit_mode ? '450px 1fr' : '1fr'; ?>;">
    
    <!-- Edit Form Pane (Only visible in edit mode) -->
    <?php if ($edit_mode && $edit_pub): ?>
        <div class="glass-panel animate-slide-in" style="padding: 25px;">
            <h3 style="margin-bottom: 20px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px; display: flex; align-items: center; justify-content: space-between;">
                <span><i class="fa-solid fa-pen-to-square" style="color: var(--color-primary); margin-right: 8px;"></i> แก้ไขผลงานวิจัย</span>
                <a href="publications.php?<?php echo http_build_query(array_filter(['search' => $search_query, 'intl' => $filter_intl])); ?>" style="font-size: 0.85rem; color: var(--color-text-muted); text-decoration: none;">ยกเลิก</a>
            </h3>
            
            <form method="POST" action="publications.php?<?php echo http_build_query(array_filter(['search' => $search_query, 'intl' => $filter_intl])); ?>" style="display: flex; flex-direction: column; gap: 15px;">
                <input type="hidden" name="id" value="<?php echo (int)$edit_pub['id']; ?>">
                
                <div>
                    <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">ชื่อบทความวิจัย (Title) *</label>
                    <textarea name="title" class="search-input" style="padding: 10px; font-size: 0.9rem; height: 80px; resize: vertical;" required><?php echo htmlspecialchars($edit_pub['title']); ?></textarea>
                </div>
                
                <div>
                    <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">ผู้แต่ง (Authors) *</label>
                    <textarea name="authors" class="search-input" style="padding: 10px; font-size: 0.9rem; height: 60px; resize: vertical;" required><?php echo htmlspecialchars($edit_pub['authors']); ?></textarea>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <div style="flex: 2;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">วารสาร/แหล่งเผยแพร่ (Journal)</label>
                        <input type="text" name="journal_name" class="search-input" style="padding: 8px 12px;" value="<?php echo htmlspecialchars($edit_pub['journal_name'] ?? ''); ?>">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">ปีที่ตีพิมพ์ *</label>
                        <input type="number" name="publish_year" class="search-input" style="padding: 8px 12px;" value="<?php echo (int)$edit_pub['publish_year']; ?>" required>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">วันที่เผยแพร่ (YYYY-MM-DD)</label>
                        <input type="text" name="publish_date" class="search-input" style="padding: 8px 12px;" value="<?php echo htmlspecialchars($edit_pub['publish_date'] ?? ''); ?>" placeholder="เช่น 2024-05-18">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">Citations Count</label>
                        <input type="number" name="citation_count" class="search-input" style="padding: 8px 12px;" value="<?php echo (int)($edit_pub['citation_count'] ?? 0); ?>">
                    </div>
                </div>

                <div>
                    <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">เลขทะเบียน DOI</label>
                    <input type="text" name="doi" class="search-input" style="padding: 8px 12px;" value="<?php echo htmlspecialchars($edit_pub['doi'] ?? ''); ?>" placeholder="เช่น 10.1016/j.jmedsci.2025.01.002">
                </div>

                <div style="display: flex; gap: 10px;">
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">ISSN วารสาร</label>
                        <input type="text" name="journal_issn" class="search-input" style="padding: 8px 12px;" value="<?php echo htmlspecialchars($edit_pub['journal_issn'] ?? ''); ?>" placeholder="เช่น 0021-9541">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">Quartile (SJR)</label>
                        <select name="quartile" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25);">
                            <option value="">— ไม่ระบุ —</option>
                            <option value="Q1" <?php echo ($edit_pub['quartile'] ?? '') === 'Q1' ? 'selected' : ''; ?>>Q1</option>
                            <option value="Q2" <?php echo ($edit_pub['quartile'] ?? '') === 'Q2' ? 'selected' : ''; ?>>Q2</option>
                            <option value="Q3" <?php echo ($edit_pub['quartile'] ?? '') === 'Q3' ? 'selected' : ''; ?>>Q3</option>
                            <option value="Q4" <?php echo ($edit_pub['quartile'] ?? '') === 'Q4' ? 'selected' : ''; ?>>Q4</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">ประเทศของผู้แต่ง/สถาบันร่วมวิจัย (Countries)</label>
                    <input type="text" name="countries" class="search-input" style="padding: 8px 12px;" value="<?php echo htmlspecialchars($edit_pub['countries'] ?? ''); ?>" placeholder="เช่น Thailand, Italy, United Kingdom (คั่นด้วยจุลภาค)">
                </div>

                <div style="display: flex; gap: 10px;">
                    <div style="flex: 1.5;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">แหล่งทุนวิจัย (Funding Sponsor)</label>
                        <input type="text" name="funding_sponsor" class="search-input" style="padding: 8px 12px;" value="<?php echo htmlspecialchars($edit_pub['funding_sponsor'] ?? ''); ?>" placeholder="เช่น University of Phayao">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">เลขที่สัญญา/ทุน (Grant No.)</label>
                        <input type="text" name="funding_no" class="search-input" style="padding: 8px 12px;" value="<?php echo htmlspecialchars($edit_pub['funding_no'] ?? ''); ?>" placeholder="เช่น 5036/2567">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">จำนวนเงินทุน (บาท)</label>
                        <input type="number" name="funding_amount" step="0.01" min="0" class="search-input" style="padding: 8px 12px;" value="<?php echo htmlspecialchars($edit_pub['funding_amount'] ?? ''); ?>" placeholder="เช่น 50000">
                    </div>
                </div>

                <div>
                    <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">ผู้แต่งประสานงานในคณะฯ (Corresponding Author)</label>
                    <select name="corresponding_author_id" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25);">
                        <option value="">— ไม่ระบุ / เป็นผู้แต่งภายนอก —</option>
                        <?php foreach ($all_researchers as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo ((int)($edit_pub['corresponding_author_id'] ?? 0) === (int)$r['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($r['title_th'] ?? '') . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']); ?> (<?php echo htmlspecialchars($r['department']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; gap: 15px;">
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">SDG หลัก (Primary SDG)</label>
                        <select name="sdg_primary" id="sdg_primary_select" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25);">
                            <option value="">— ไม่ระบุ —</option>
                            <?php foreach (get_all_sdgs() as $code => $details): ?>
                                <option value="<?php echo $code; ?>" <?php echo (($edit_pub['sdg_primary'] ?? '') === $code) ? 'selected' : ''; ?>>
                                    <?php echo $code . ': ' . htmlspecialchars($details['th_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">SDG รอง (Secondary SDG)</label>
                        <select name="sdg_secondary" id="sdg_secondary_select" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25);">
                            <option value="">— ไม่ระบุ —</option>
                            <?php foreach (get_all_sdgs() as $code => $details): ?>
                                <option value="<?php echo $code; ?>" <?php echo (($edit_pub['sdg_secondary'] ?? '') === $code) ? 'selected' : ''; ?>>
                                    <?php echo $code . ': ' . htmlspecialchars($details['th_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">SDG ลำดับ 3 (Tertiary SDG)</label>
                        <select name="sdg_tertiary" id="sdg_tertiary_select" class="search-input" style="padding: 8px 12px; height: auto; background: rgba(0,0,0,0.25);">
                            <option value="">— ไม่ระบุ —</option>
                            <?php foreach (get_all_sdgs() as $code => $details): ?>
                                <option value="<?php echo $code; ?>" <?php echo (($edit_pub['sdg_tertiary'] ?? '') === $code) ? 'selected' : ''; ?>>
                                    <?php echo $code . ': ' . htmlspecialchars($details['th_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">เหตุผล/เกณฑ์ที่ใช้ประเมิน SDGs</label>
                    <textarea name="sdg_rationale" id="sdg_rationale_input" class="search-input" rows="2" style="padding: 8px 12px; font-family: inherit; height: auto;" placeholder="ระบุเหตุผลเกณฑ์ประกอบตัวอย่าง: วัณโรค = โรคติดต่อ ตรงกับ target 3.3"><?php echo htmlspecialchars($edit_pub['sdg_rationale'] ?? ''); ?></textarea>
                </div>

                <div style="background: rgba(139, 92, 246, 0.08); border: 1px solid rgba(139, 92, 246, 0.25); border-radius: 10px; padding: 14px;">
                    <button type="button" id="suggest-sdg-btn" class="btn-premium" style="padding: 8px 14px; font-size: 0.85rem;" data-pub-id="<?php echo (int)$edit_pub['id']; ?>">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> แนะนำ SDG จากคำสำคัญ (Suggest SDGs)
                    </button>
                    <small style="color: var(--color-text-muted); display: block; margin-top: 6px;">
                        ระบบจะจับคู่ชื่อเรื่อง/คำสำคัญ/บทคัดย่อกับพจนานุกรมคำสำคัญ SDG ของคณะ (msc_sdgs) เป็นเพียง <strong>ข้อเสนอแนะ</strong> เท่านั้น — กรุณาตรวจสอบก่อนกดปุ่ม "ใช้ค่านี้" แล้วบันทึกข้อมูลตามปกติ
                    </small>
                    <div id="suggest-sdg-status" style="font-size: 0.82rem; margin-top: 8px;"></div>
                    <div id="suggest-sdg-results" style="margin-top: 10px; display: flex; flex-direction: column; gap: 8px;"></div>
                </div>

                <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); border-radius: 10px; padding: 14px;">
                    <button type="button" id="fetch-rcr-btn" class="btn-premium" style="padding: 8px 14px; font-size: 0.85rem;" data-pub-id="<?php echo (int)$edit_pub['id']; ?>">
                        <i class="fa-solid fa-chart-line"></i> ดึงค่า RCR (NIH iCite)
                    </button>
                    <small style="color: var(--color-text-muted); display: block; margin-top: 6px;">
                        แปลง DOI เป็น PubMed ID แล้วดึงค่า Relative Citation Ratio จาก NIH iCite โดยอัตโนมัติ — ผลงานที่ไม่ได้อยู่ใน PubMed จะไม่มีค่านี้ (ข้อจำกัดของ PubMed เอง)
                        <?php if (!empty($edit_pub['rcr'])): ?>
                            <br>ค่าปัจจุบัน: <strong>RCR <?php echo number_format((float)$edit_pub['rcr'], 2); ?></strong><?php echo $edit_pub['nih_percentile'] !== null ? ' (เปอร์เซ็นไทล์ ' . number_format((float)$edit_pub['nih_percentile'], 1) . ')' : ' <span class="badge" style="font-size:0.7rem; background:rgba(251,191,36,0.1); color:#fbbf24; border:1px solid rgba(251,191,36,0.3); padding:1px 5px; border-radius:3px;">รอสรุปเปอร์เซ็นไทล์สิ้นปี (Provisional)</span>'; ?>
                        <?php endif; ?>
                    </small>
                    <div id="fetch-rcr-status" style="font-size: 0.82rem; margin-top: 8px;"></div>
                </div>

                <div>
                    <label style="font-size: 0.8rem; color: var(--color-primary); display: block; margin-bottom: 5px; font-weight: 600;"><i class="fa-solid fa-link"></i> ลิงก์ปลายทางงานวิจัย (URL)</label>
                    <input type="url" name="url" class="search-input" style="padding: 8px 12px; border-color: rgba(255, 215, 0, 0.4);" value="<?php echo htmlspecialchars($edit_pub['url'] ?? ''); ?>" placeholder="https://...">
                    <small style="color: var(--color-text-muted); display: block; margin-top: 4px;">หากต้องการให้คลิกปุ่มไปบทความตัวจริง คุณสามารถแก้ไขและนำลิงก์ตรงมาป้อนในช่องนี้ได้เลยครับ</small>
                </div>

                <div>
                    <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 5px;">บทคัดย่อ (Abstract)</label>
                    <textarea name="abstract" class="search-input" style="padding: 10px; font-size: 0.85rem; height: 120px; resize: vertical;"><?php echo htmlspecialchars($edit_pub['abstract'] ?? ''); ?></textarea>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="submit" class="btn-premium" style="flex: 1; padding: 12px; cursor: pointer; border-radius: 8px;">
                        <i class="fa-solid fa-save"></i> บันทึกข้อมูล
                    </button>
                    <a href="publications.php?<?php echo http_build_query(array_filter(['search' => $search_query, 'intl' => $filter_intl])); ?>" class="btn-premium" style="text-align: center; flex: 1; padding: 12px; background: rgba(255,255,255,0.05); border-color: var(--border-glass); color: var(--color-text-main);">
                        ยกเลิก
                    </a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Publications List Pane -->
    <div class="glass-panel" style="padding: 25px; overflow-x: auto;">
        
        <!-- Search and Filter Bar -->
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 25px;">
            <h3 style="font-weight: 600;"><i class="fa-solid fa-list-check" style="color: var(--color-accent); margin-right: 8px;"></i> รายชื่อผลงานทั้งหมดในระบบ</h3>
            
            <form method="GET" action="publications.php" style="display: flex; gap: 8px; width: 100%; max-width: 600px; flex-wrap: wrap;">
                <select name="intl" onchange="this.form.submit()" style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); border-radius: 8px; color: var(--color-text-main); font-size: 0.85rem; padding: 6px 12px; outline: none; cursor: pointer;">
                    <option value="">— ทุกความร่วมมือ —</option>
                    <option value="1" <?php echo $filter_intl === '1' ? 'selected' : ''; ?>>ความร่วมมือต่างประเทศ (มีสถาบันนอก TH)</option>
                    <option value="0" <?php echo $filter_intl === '0' ? 'selected' : ''; ?>>เฉพาะภายในประเทศไทย / ไม่ระบุ</option>
                </select>
                <div class="search-box" style="flex: 1; min-width: 200px; margin: 0;">
                    <i class="fa-solid fa-magnifying-glass search-icon"></i>
                    <input type="text" name="search" class="search-input" placeholder="ค้นหาตามชื่อ, ผู้แต่ง, วารสาร..." value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                <button type="submit" class="btn-premium" style="padding: 0 15px; border-radius: 8px;">ค้นหา</button>
                <?php if (!empty($search_query) || !empty($filter_intl)): ?>
                    <a href="publications.php" class="btn-premium" style="padding: 8px 12px; background: rgba(255,255,255,0.05); border-color: var(--border-glass); color: var(--color-text-main);"><i class="fa-solid fa-close"></i></a>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Publications Table -->
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted);">
                    <th style="padding: 12px 8px; width: 60px;">แหล่งที่มา</th>
                    <th style="padding: 12px 8px;">ชื่อเรื่อง / ผู้ร่วมแต่ง</th>
                    <th style="padding: 12px 8px; width: 150px;">วารสาร / ปี</th>
                    <th style="padding: 12px 8px; width: 150px;">ผู้วิจัยของคณะ</th>
                    <th style="padding: 12px 8px; width: 110px; text-align: center;">การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($publications)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: var(--color-text-muted);">
                            <i class="fa-regular fa-folder-open" style="font-size: 2.5rem; display: block; margin-bottom: 10px;"></i>
                            ไม่พบข้อมูลผลงานตีพิมพ์ในระบบ
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($publications as $pub): ?>
                        <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05); transition: background 0.2s;" onmouseover="this.style.background='rgba(255, 255, 255, 0.02)'" onmouseout="this.style.background='none'">
                            <!-- Source Badge -->
                            <td style="padding: 12px 8px; vertical-align: top;">
                                <span class="pub-source-badge source-<?php echo strtolower($pub['source']); ?>" style="font-size: 0.75rem; padding: 3px 6px;">
                                    <?php echo htmlspecialchars($pub['source']); ?>
                                </span>
                            </td>
                            
                            <!-- Title & Authors -->
                            <td style="padding: 12px 8px; vertical-align: top;">
                                <div style="font-weight: 500; color: var(--color-text-main); margin-bottom: 4px; line-height: 1.4;">
                                    <?php echo htmlspecialchars($pub['title']); ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                                    ผู้แต่ง: <?php echo htmlspecialchars(mb_strimwidth($pub['authors'], 0, 100, '...')); ?>
                                </div>
                                <?php if (!empty($pub['countries'])): ?>
                                    <div style="font-size: 0.75rem; color: #60a5fa; margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                                        <i class="fa-solid fa-earth-americas"></i> ประเทศ: <strong><?php echo htmlspecialchars($pub['countries']); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($pub['funding_sponsor'])): ?>
                                    <div style="font-size: 0.75rem; color: #10b981; margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                                        <i class="fa-solid fa-hand-holding-dollar"></i> แหล่งทุน: <strong><?php echo htmlspecialchars($pub['funding_sponsor']); ?><?php echo !empty($pub['funding_no']) ? ' (' . htmlspecialchars($pub['funding_no']) . ')' : ''; ?><?php echo !empty($pub['funding_amount']) ? ' | งบประมาณ: ' . number_format($pub['funding_amount'], 2) . ' บาท' : ''; ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($pub['corresponding_author_name'])): ?>
                                    <div style="font-size: 0.75rem; color: #f472b6; margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                                        <i class="fa-solid fa-envelope-circle-check"></i> ผู้ประสานงาน (Corresponding): <strong><?php echo htmlspecialchars($pub['corresponding_author_name']); ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php $eff_admin = get_effective_sdgs($pub); ?>
                                <?php if ($eff_admin['primary'] || $eff_admin['secondary'] || $eff_admin['tertiary']): ?>
                                    <div style="margin-top: 6px; display: flex; flex-wrap: wrap; gap: 6px;">
                                        <?php echo render_effective_sdg_badges($pub, '0.72rem'); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($pub['url'])): ?>
                                    <div style="margin-top: 6px;">
                                        <a href="<?php echo htmlspecialchars($pub['url']); ?>" target="_blank" style="font-size: 0.75rem; color: var(--color-accent); text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i> เปิดลิงก์ตรงผลงาน
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 0.75rem; color: var(--color-error);"><i class="fa-solid fa-link-slash"></i> ไม่มีลิงก์เชื่อมต่อ</div>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Journal & Year -->
                            <td style="padding: 12px 8px; vertical-align: top;">
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($pub['journal_name'] ?? 'ไม่ระบุวารสาร'); ?></div>
                                <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap;">
                                    <span style="font-size: 0.8rem; color: var(--color-text-muted);">ปี: <?php echo $pub['publish_year']; ?></span>
                                    <?php if (!empty($pub['quartile'])): ?>
                                        <?php 
                                            $q_color = '#ef4444';
                                            $q_bg = 'rgba(239, 68, 68, 0.15)';
                                            if ($pub['quartile'] === 'Q1') { $q_color = '#10b981'; $q_bg = 'rgba(16, 185, 129, 0.15)'; }
                                            elseif ($pub['quartile'] === 'Q2') { $q_color = '#3b82f6'; $q_bg = 'rgba(59, 130, 246, 0.15)'; }
                                            elseif ($pub['quartile'] === 'Q3') { $q_color = '#f59e0b'; $q_bg = 'rgba(245, 158, 11, 0.15)'; }
                                        ?>
                                        <span class="badge" style="background: <?php echo $q_bg; ?>; color: <?php echo $q_color; ?>; border: 1px solid <?php echo $q_color; ?>44; font-weight: 700; font-size: 0.72rem; padding: 1px 6px;"><?php echo $pub['quartile']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Linked Researchers -->
                            <td style="padding: 12px 8px; vertical-align: top; font-size: 0.8rem; color: var(--color-primary);">
                                <?php if (!empty($pub['linked_researchers'])): ?>
                                    <i class="fa-solid fa-user-tag"></i> <?php echo htmlspecialchars($pub['linked_researchers']); ?>
                                <?php else: ?>
                                    <span style="color: var(--color-text-muted);">ไม่ได้เชื่อมโยง</span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Actions -->
                            <td style="padding: 12px 8px; text-align: center; vertical-align: middle;">
                                <div style="display: flex; gap: 6px; justify-content: center;">
                                    <a href="publications.php?edit=<?php echo $pub['id']; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo !empty($filter_intl) ? '&intl=' . urlencode($filter_intl) : ''; ?>" class="btn-premium" style="padding: 6px 10px; font-size: 0.8rem; background: rgba(59, 130, 246, 0.15); border-color: rgba(59, 130, 246, 0.3); color: #60a5fa;" title="แก้ไขรายละเอียด">
                                        <i class="fa-solid fa-edit"></i>
                                    </a>
                                    <a href="publications.php?delete=<?php echo $pub['id']; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?><?php echo !empty($filter_intl) ? '&intl=' . urlencode($filter_intl) : ''; ?>" class="btn-premium" style="padding: 6px 10px; font-size: 0.8rem; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #f87171;" onclick="return confirm('ยืนยันที่จะลบผลงานวิจัยนี้ออกจากระบบหรือไม่? การกระทำนี้ไม่สามารถย้อนคืนได้')" title="ลบผลงาน">
                                        <i class="fa-solid fa-trash-can"></i>
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

<?php if ($edit_mode && $edit_pub): ?>
<script>
const CSRF_TOKEN = "<?php echo htmlspecialchars(get_csrf_token()); ?>";
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('suggest-sdg-btn');
    if (!btn) return;
    const statusEl = document.getElementById('suggest-sdg-status');
    const resultsEl = document.getElementById('suggest-sdg-results');
    const primarySelect = document.getElementById('sdg_primary_select');
    const secondarySelect = document.getElementById('sdg_secondary_select');
    const tertiarySelect = document.getElementById('sdg_tertiary_select');
    const rationaleInput = document.getElementById('sdg_rationale_input');

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function renderSuggestions(data) {
        resultsEl.innerHTML = '';
        let badgeHtml = '';
        if (data.dictionary_source === 'live') {
            badgeHtml = '<span style="display:inline-flex; align-items:center; gap:4px; background:rgba(16, 185, 129, 0.15); color:#10b981; border:1px solid rgba(16, 185, 129, 0.3); font-size:0.72rem; padding:2px 8px; border-radius:12px; font-weight:500;"><i class="fa-solid fa-bolt"></i> Live: msc_sdgs</span>';
        } else if (data.dictionary_source === 'bundled') {
            badgeHtml = '<span style="display:inline-flex; align-items:center; gap:4px; background:rgba(245, 158, 11, 0.15); color:#f59e0b; border:1px solid rgba(245, 158, 11, 0.3); font-size:0.72rem; padding:2px 8px; border-radius:12px; font-weight:500;"><i class="fa-solid fa-box-archive"></i> Bundled copy</span>';
        }

        statusEl.innerHTML = badgeHtml;
        if (data.warning) {
            statusEl.innerHTML += '<div style="color:#f59e0b; margin-top:4px;"><i class="fa-solid fa-triangle-exclamation"></i> ' + escapeHtml(data.warning) + '</div>';
        }
        if (!data.suggestions || data.suggestions.length === 0) {
            statusEl.innerHTML += '<div style="color: var(--color-text-muted); margin-top: 4px;">ไม่พบคำสำคัญที่ตรงกับ SDG ใดเลย (อาจเป็นเพราะยังไม่มีบทคัดย่อ/คำสำคัญของผลงานนี้)</div>';
            return;
        }
        data.suggestions.forEach((s, idx) => {
            const div = document.createElement('div');
            div.style.cssText = 'border: 1px solid ' + s.color + '55; background:' + s.color + '15; border-radius: 8px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;';
            div.innerHTML =
                '<div>' +
                    '<div style="font-weight: 600; color: ' + s.color + ';">' + escapeHtml(s.code) + ': ' + escapeHtml(s.name_th) + '</div>' +
                    '<div style="font-size: 0.78rem; color: var(--color-text-muted); margin-top: 2px;">คะแนน: ' + s.score.toFixed(2) + (s.rationale ? ' — จับคู่กับ: ' + escapeHtml(s.rationale) : '') + '</div>' +
                '</div>' +
                '<div style="display:flex; gap:6px; flex-wrap: wrap;">' +
                    '<button type="button" class="btn-premium apply-sdg-btn" data-slot="primary" data-idx="' + idx + '" style="padding: 5px 10px; font-size: 0.78rem;">ใช้เป็น SDG หลัก</button>' +
                    '<button type="button" class="btn-premium apply-sdg-btn" data-slot="secondary" data-idx="' + idx + '" style="padding: 5px 10px; font-size: 0.78rem; background: rgba(255,255,255,0.05);">ใช้เป็น SDG รอง</button>' +
                    '<button type="button" class="btn-premium apply-sdg-btn" data-slot="tertiary" data-idx="' + idx + '" style="padding: 5px 10px; font-size: 0.78rem; background: rgba(255,255,255,0.05);">ใช้เป็น SDG ลำดับ 3</button>' +
                '</div>';
            resultsEl.appendChild(div);
        });

        const slotSelects = { primary: primarySelect, secondary: secondarySelect, tertiary: tertiarySelect };
        resultsEl.querySelectorAll('.apply-sdg-btn').forEach(b => {
            b.addEventListener('click', () => {
                const s = data.suggestions[parseInt(b.dataset.idx, 10)];
                const select = slotSelects[b.dataset.slot];
                if (!select) return;
                select.value = s.code;
                if (rationaleInput.value.trim() === '') {
                    rationaleInput.value = s.rationale ? (s.code + ' (คะแนน ' + s.score.toFixed(2) + '): ' + s.rationale) : ('แนะนำโดยระบบจากคำสำคัญ (' + s.code + ', คะแนน ' + s.score.toFixed(2) + ')');
                }
            });
        });
    }

    btn.addEventListener('click', () => {
        btn.disabled = true;
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังวิเคราะห์...';
        resultsEl.innerHTML = '';
        fetch('suggest_sdgs.php?id=' + encodeURIComponent(btn.dataset.pubId), {
            headers: { 'X-CSRF-Token': CSRF_TOKEN }
        })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    statusEl.innerHTML = '<span style="color:#f87171;">' + escapeHtml(data.error) + '</span>';
                    return;
                }
                statusEl.innerHTML = '';
                renderSuggestions(data);
            })
            .catch(() => {
                statusEl.innerHTML = '<span style="color:#f87171;">เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่</span>';
            })
            .finally(() => { btn.disabled = false; });
    });

    // Phase 7 (RCR-06 secondary mechanism): on-demand fetch for the one
    // publication currently being edited - hits the same
    // admin/fetch_rcr.php?action=process endpoint the batch tool uses.
    const rcrBtn = document.getElementById('fetch-rcr-btn');
    if (rcrBtn) {
        const rcrStatusEl = document.getElementById('fetch-rcr-status');
        rcrBtn.addEventListener('click', () => {
            rcrBtn.disabled = true;
            rcrStatusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังดึงข้อมูล...';
            fetch('fetch_rcr.php?action=process&id=' + encodeURIComponent(rcrBtn.dataset.pubId), {
                headers: { 'X-CSRF-Token': CSRF_TOKEN }
            })
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        rcrStatusEl.innerHTML = '<span style="color:#f87171;">' + escapeHtml(data.error) + '</span>';
                        return;
                    }
                    if (data.status === 'applied') {
                        rcrStatusEl.innerHTML = '<span style="color:#10b981;">สำเร็จ: RCR ' + data.rcr + (data.nih_percentile !== null ? ' (เปอร์เซ็นไทล์ ' + data.nih_percentile + ')' : '') + ' — บันทึกแล้ว รีเฟรชหน้าเพื่อดูค่าล่าสุด</span>';
                    } else if (data.status === 'no_match' || data.status === 'skipped') {
                        rcrStatusEl.innerHTML = '<span style="color:var(--color-text-muted);">' + escapeHtml(data.reason || 'ไม่พบข้อมูล') + '</span>';
                    } else {
                        rcrStatusEl.innerHTML = '<span style="color:#f87171;">' + escapeHtml(data.reason || 'เกิดข้อผิดพลาด') + '</span>';
                    }
                })
                .catch(() => {
                    rcrStatusEl.innerHTML = '<span style="color:#f87171;">เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่</span>';
                })
                .finally(() => { rcrBtn.disabled = false; });
        });
    }
});
</script>
<?php endif; ?>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
