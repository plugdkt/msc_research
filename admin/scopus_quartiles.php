<?php
// admin/scopus_quartiles.php
// Manage Scopus Quartiles mapping (Upload Scopus Source List CSV, View statistics, Search)

$current_page = 'admin_scimago';
$page_title = 'จัดการ Quartiles (Scopus) - Admin Panel';

require_once __DIR__ . '/admin_header.php';
require_once __DIR__ . '/../config/db.php';

$message = '';
$message_type = 'success';

// ── Handle Scopus CSV Import ──────────────────────────────────────────────
if (isset($_POST['import_scopus_csv']) && isset($_FILES['scopus_csv_file']) && $_FILES['scopus_csv_file']['error'] === UPLOAD_ERR_OK) {
    $file_path = $_FILES['scopus_csv_file']['tmp_name'];
    
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        // Detect delimiter: comma or semicolon
        $first_line = fgets($handle);
        $delimiter = ',';
        if (strpos($first_line, ';') !== false) {
            $delimiter = ';';
        }
        rewind($handle);
        
        // Read header
        $headers = fgetcsv($handle, 4096, $delimiter);
        
        // Map headers to indices
        $title_idx = -1;
        $issn_indices = [];
        $q_idx = -1;
        $percentile_idx = -1;
        $sjr_idx = -1; // If they have SJR in Scopus list
        
        foreach ($headers as $idx => $header) {
            $clean_h = strtolower(trim(preg_replace('/[\x{FEFF}\x{200B}]/u', '', $header))); // Remove BOM
            
            if (in_array($clean_h, ['source title', 'title', 'journal', 'journal title'])) {
                $title_idx = $idx;
            } elseif (in_array($clean_h, ['issn', 'print issn', 'print-issn', 'e-issn', 'electronic issn', 'eissn'])) {
                $issn_indices[] = $idx;
            } elseif (in_array($clean_h, ['quartile', 'cite2023_quartile', 'citescore quartile', 'sjr quartile', 'sjr q'])) {
                $q_idx = $idx;
            } elseif (in_array($clean_h, ['percentile', 'citescore percentile', 'highest percentile', 'percentile rank'])) {
                $percentile_idx = $idx;
            } elseif (in_array($clean_h, ['sjr', 'citescore'])) {
                $sjr_idx = $idx;
            }
        }
        
        // Guess indices if not exact
        if ($title_idx === -1) {
            foreach ($headers as $idx => $header) {
                if (strpos(strtolower($header), 'title') !== false) { $title_idx = $idx; break; }
            }
        }
        if (empty($issn_indices)) {
            foreach ($headers as $idx => $header) {
                if (strpos(strtolower($header), 'issn') !== false) { $issn_indices[] = $idx; }
            }
        }
        
        if ($title_idx === -1 || empty($issn_indices)) {
            $message = "ไม่พบหัวคอลัมน์ Title หรือ ISSN ในไฟล์ CSV กรุณาตรวจสอบรูปแบบไฟล์";
            $message_type = "error";
            fclose($handle);
        } else {
            try {
                $pdo->beginTransaction();
                
                // Clear existing quartiles
                $pdo->exec("TRUNCATE TABLE `journal_quartiles`");
                
                $stmt = $pdo->prepare("INSERT INTO `journal_quartiles` (issn, title, quartile, sjr, source) VALUES (?, ?, ?, ?, 'scopus')");
                
                $row_count = 0;
                $insert_count = 0;
                
                while (($data = fgetcsv($handle, 4096, $delimiter)) !== FALSE) {
                    $row_count++;
                    $title = trim($data[$title_idx] ?? '');
                    
                    // Collect all ISSNs in this row
                    $raw_issns = [];
                    foreach ($issn_indices as $issn_idx) {
                        if (!empty($data[$issn_idx])) {
                            $raw_issns[] = trim($data[$issn_idx]);
                        }
                    }
                    
                    if (empty($raw_issns)) continue;
                    
                    // Parse Quartile
                    $quartile = '';
                    if ($q_idx !== -1 && !empty($data[$q_idx])) {
                        $q_val = strtoupper(trim($data[$q_idx]));
                        if (preg_match('/Q[1-4]/', $q_val, $matches)) {
                            $quartile = $matches[0];
                        }
                    }
                    
                    // If no quartile column but we have percentile
                    if (empty($quartile) && $percentile_idx !== -1 && !empty($data[$percentile_idx])) {
                        $percentile = (float)str_replace(['%', ','], ['', '.'], $data[$percentile_idx]);
                        if ($percentile >= 75) {
                            $quartile = 'Q1';
                        } elseif ($percentile >= 50) {
                            $quartile = 'Q2';
                        } elseif ($percentile >= 25) {
                            $quartile = 'Q3';
                        } else {
                            $quartile = 'Q4';
                        }
                    }
                    
                    $sjr = 0.0;
                    if ($sjr_idx !== -1 && !empty($data[$sjr_idx])) {
                        $sjr = (float)str_replace(',', '.', $data[$sjr_idx]);
                    }
                    
                    // Process ISSNs
                    foreach ($raw_issns as $issn_raw) {
                        $issns = preg_split('/[\s,;]+/', $issn_raw);
                        foreach ($issns as $issn) {
                            $clean_issn = preg_replace('/[^0-9X]/i', '', $issn);
                            if (strlen($clean_issn) === 8 || strlen($clean_issn) === 9) {
                                $stmt->execute([$clean_issn, $title, $quartile, $sjr]);
                                $insert_count++;
                            }
                        }
                    }
                }
                
                $pdo->commit();
                $message = "นำเข้าข้อมูล Scopus Source List สำเร็จ! ประมวลผล {$row_count} แถว บันทึก {$insert_count} ISSNs เข้าฐานข้อมูล";
                $message_type = "success";
                
                // Perform retroactive update on existing publications
                $stmtRetro = $pdo->query("
                    SELECT id, journal_issn, journal_name FROM `publications` 
                    WHERE journal_issn IS NOT NULL AND journal_issn != ''
                ");
                $retro_pubs = $stmtRetro->fetchAll();
                
                if (!empty($retro_pubs)) {
                    $stmtUpdate = $pdo->prepare("UPDATE `publications` SET quartile = ? WHERE id = ?");
                    $stmtFindQ = $pdo->prepare("SELECT quartile FROM `journal_quartiles` WHERE issn = ? AND quartile != '' LIMIT 1");
                    $updated_retro_count = 0;
                    
                    foreach ($retro_pubs as $pub) {
                        $clean_pub_issn = preg_replace('/[^0-9X]/i', '', $pub['journal_issn']);
                        $stmtFindQ->execute([$clean_pub_issn]);
                        $q = $stmtFindQ->fetchColumn();
                        
                        // Always update, even if it clears old SCImago quartiles with new Scopus ones
                        $stmtUpdate->execute([$q ?: null, $pub['id']]);
                        if ($q) {
                            $updated_retro_count++;
                        }
                    }
                    if ($updated_retro_count > 0) {
                        $message .= "<br>จับคู่ย้อนหลังและอัปเดต Scopus Quartile ของผลงานวิจัยเก่าสำเร็จ {$updated_retro_count} รายการ";
                    }
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "เกิดข้อผิดพลาดระหว่างนำเข้าข้อมูล: " . $e->getMessage();
                $message_type = "error";
            }
            fclose($handle);
        }
    } else {
        $message = "ไม่สามารถเปิดไฟล์อัปโหลดได้";
        $message_type = "error";
    }
}

// ── Handle Clear Data ────────────────────────────────────────────────────────
if (isset($_POST['clear_quartiles'])) {
    $pdo->exec("TRUNCATE TABLE `journal_quartiles`");
    $message = "ล้างข้อมูล Quartiles ในตารางจับคู่เรียบร้อยแล้ว";
    $message_type = "success";
}

// ── Search Journal ISSN ──────────────────────────────────────────────────────
$search_query = trim($_GET['search'] ?? '');
$search_results = [];
if ($search_query !== '') {
    $clean_search = preg_replace('/[^0-9X]/i', '', $search_query);
    $stmtSearch = $pdo->prepare("
        SELECT * FROM `journal_quartiles` 
        WHERE issn = ? OR title LIKE ? 
        ORDER BY sjr DESC LIMIT 20
    ");
    $stmtSearch->execute([$clean_search, '%' . $search_query . '%']);
    $search_results = $stmtSearch->fetchAll();
}

// Get statistics
$stats = $pdo->query("
    SELECT COUNT(*) as total_issns, 
           COUNT(DISTINCT title) as total_journals,
           SUM(CASE WHEN quartile = 'Q1' THEN 1 ELSE 0 END) as q1_count,
           SUM(CASE WHEN quartile = 'Q2' THEN 1 ELSE 0 END) as q2_count,
           SUM(CASE WHEN quartile = 'Q3' THEN 1 ELSE 0 END) as q3_count,
           SUM(CASE WHEN quartile = 'Q4' THEN 1 ELSE 0 END) as q4_count
    FROM `journal_quartiles`
")->fetch();

$publications_need_q = $pdo->query("
    SELECT COUNT(*) FROM `publications` 
    WHERE (quartile IS NULL OR quartile = '') AND (journal_issn IS NOT NULL AND journal_issn != '')
")->fetchColumn();

?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>จัดการฐานข้อมูลวารสาร Quartiles (Scopus)</h2>
    <p>อัปโหลดข้อมูลรายชื่อวารสารพร้อมค่า CiteScore/Quartile ของฐานข้อมูล Scopus เพื่อใช้จับคู่ค่า Q1–Q4 ของผลงานตีพิมพ์โดยอัตโนมัติจาก ISSN</p>
</div>

<?php if ($message): ?>
    <div class="glass-panel animate-fade-in" style="padding: 15px 20px; border-radius: 12px; margin-bottom: 25px;
         background: <?php echo $message_type === 'success' ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)'; ?>;
         border-color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;
         color: <?php echo $message_type === 'success' ? '#34d399' : '#f87171'; ?>;">
        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid animate-fade-in" style="margin-bottom: 30px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
    <div class="stat-card glass-panel" style="border-top: 3px solid var(--color-primary); padding: 20px;">
        <div class="stat-number" style="color: var(--color-primary); font-size: 1.8rem; font-weight: 700;">
            <?php echo number_format($stats['total_journals'] ?? 0); ?>
        </div>
        <div class="stat-label" style="font-size: 0.8rem; color: var(--color-text-muted);">จำนวนวารสาร Scopus ทั้งหมด</div>
    </div>
    <div class="stat-card glass-panel" style="border-top: 3px solid #10b981; padding: 20px;">
        <div class="stat-number" style="color: #10b981; font-size: 1.8rem; font-weight: 700;">
            <?php echo number_format($stats['q1_count'] ?? 0); ?>
        </div>
        <div class="stat-label" style="font-size: 0.8rem; color: var(--color-text-muted);">วารสารระดับ Q1</div>
    </div>
    <div class="stat-card glass-panel" style="border-top: 3px solid #60a5fa; padding: 20px;">
        <div class="stat-number" style="color: #60a5fa; font-size: 1.8rem; font-weight: 700;">
            <?php echo number_format($stats['q2_count'] ?? 0); ?>
        </div>
        <div class="stat-label" style="font-size: 0.8rem; color: var(--color-text-muted);">วารสารระดับ Q2</div>
    </div>
    <div class="stat-card glass-panel" style="border-top: 3px solid #fbbf24; padding: 20px;">
        <div class="stat-number" style="color: #fbbf24; font-size: 1.8rem; font-weight: 700;">
            <?php echo number_format($stats['q3_count'] ?? 0); ?>
        </div>
        <div class="stat-label" style="font-size: 0.8rem; color: var(--color-text-muted);">วารสารระดับ Q3</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; margin-bottom: 30px; flex-wrap: wrap;">
    <!-- Import Form -->
    <div class="glass-panel" style="padding: 25px;">
        <h3 style="margin-bottom: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-file-csv" style="color: #10b981;"></i> นำเข้าไฟล์ CSV จาก Scopus Source List
        </h3>
        <p style="font-size: 0.82rem; color: var(--color-text-muted); line-height: 1.6; margin-bottom: 15px;">
            คุณสามารถดาวน์โหลดไฟล์ข้อมูลรายชื่อวารสารของฐานข้อมูล Scopus ได้ฟรีจากเว็บไซต์ 
            <a href="https://www.scopus.com/sources" target="_blank" style="color: var(--color-primary); text-decoration: underline;">scopus.com/sources</a> 
            (ดาวน์โหลดเป็น Excel แล้วทำการ Save As เป็นไฟล์ CSV) แล้วนำมาอัปโหลดที่นี่
        </p>
        
        <form method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 15px;">
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 6px;">เลือกไฟล์ CSV (Scopus Source List) *</label>
                <input type="file" name="scopus_csv_file" accept=".csv" required
                       style="width: 100%; padding: 8px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-glass); border-radius: 8px; color: var(--color-text-main);">
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" name="import_scopus_csv" class="btn-premium" style="flex: 1; padding: 10px; justify-content: center; font-weight: 600; background: linear-gradient(135deg, #10b981, #059669); border-color: rgba(16,185,129,0.4);">
                    <i class="fa-solid fa-upload"></i> อัปโหลดและนำเข้า
                </button>
                
                <?php if ($stats['total_journals'] > 0): ?>
                    <button type="submit" name="clear_quartiles" onclick="return confirm('ยืนยันที่จะลบข้อมูลวารสารทั้งหมดในระบบจับคู่หรือไม่?')" class="btn-logout" style="padding: 10px; font-weight: 600; border-radius: 8px;">
                        <i class="fa-solid fa-trash"></i> ล้างข้อมูล
                    </button>
                <?php endif; ?>
            </div>
        </form>
        
        <?php if ($publications_need_q > 0): ?>
            <div style="background: rgba(251,191,36,0.1); border: 1px solid rgba(251,191,36,0.2); border-radius: 8px; padding: 12px; margin-top: 15px; font-size: 0.8rem; color: #fbbf24;">
                <i class="fa-solid fa-circle-info"></i> ปัจจุบันมีผลงานวิจัย <strong><?php echo $publications_need_q; ?></strong> เรื่องที่เก็บ ISSN ไว้แล้ว แต่ยังไม่มี Quartile การอัปโหลดไฟล์ใหม่จะช่วยจับคู่วารสารและอัปเดตให้อัตโนมัติ
            </div>
        <?php endif; ?>
    </div>

    <!-- Search Tool -->
    <div class="glass-panel" style="padding: 25px;">
        <h3 style="margin-bottom: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-magnifying-glass" style="color: var(--color-accent);"></i> ค้นหาอันดับวารสารในระบบ
        </h3>
        
        <form method="GET" action="scopus_quartiles.php" style="display: flex; gap: 10px; margin-bottom: 15px;">
            <input type="text" name="search" class="search-input" style="padding: 9px 12px; flex: 1;" 
                   placeholder="พิมพ์ชื่อวารสาร หรือ ISSN (เช่น 0021-9541)..." 
                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
            <button type="submit" class="btn-premium" style="padding: 9px 18px; box-shadow: none;">
                ค้นหา
            </button>
        </form>

        <?php if ($search_query !== ''): ?>
            <div style="max-height: 250px; overflow-y: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-glass); text-align: left; color: var(--color-text-muted);">
                            <th style="padding: 6px;">ISSN</th>
                            <th style="padding: 6px;">ชื่อวารสาร (Title)</th>
                            <th style="padding: 6px; text-align: center;">CiteScore</th>
                            <th style="padding: 6px; text-align: center;">Quartile</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($search_results)): ?>
                            <tr>
                                <td colspan="4" style="padding: 15px; text-align: center; color: var(--color-text-muted);">
                                    ไม่พบข้อมูลวารสารที่ค้นหาในระบบ
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($search_results as $res): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                                    <td style="padding: 8px 6px; font-family: var(--font-eng); font-weight: 500;">
                                        <?php echo htmlspecialchars(strlen($res['issn']) === 8 ? substr($res['issn'], 0, 4) . '-' . substr($res['issn'], 4) : $res['issn']); ?>
                                    </td>
                                    <td style="padding: 8px 6px; font-weight: 600; color: var(--color-text-main);">
                                        <?php echo htmlspecialchars($res['title']); ?>
                                    </td>
                                    <td style="padding: 8px 6px; text-align: center; font-family: var(--font-eng);">
                                        <?php echo number_format($res['sjr'], 1); ?>
                                    </td>
                                    <td style="padding: 8px 6px; text-align: center;">
                                        <?php if ($res['quartile'] === 'Q1'): ?>
                                            <span class="badge" style="background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid rgba(16,185,129,0.3); font-weight: 700;">Q1</span>
                                        <?php elseif ($res['quartile'] === 'Q2'): ?>
                                            <span class="badge" style="background: rgba(59,130,246,0.2); color: #3b82f6; border: 1px solid rgba(59,130,246,0.3); font-weight: 700;">Q2</span>
                                        <?php elseif ($res['quartile'] === 'Q3'): ?>
                                            <span class="badge" style="background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); font-weight: 700;">Q3</span>
                                        <?php elseif ($res['quartile'] === 'Q4'): ?>
                                            <span class="badge" style="background: rgba(239,68,68,0.2); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); font-weight: 700;">Q4</span>
                                        <?php else: ?>
                                            <span style="color: var(--color-text-muted);">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/admin_footer.php'; ?>
