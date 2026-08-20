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

// Phase 9 (Auto-Quartile): status for the API-driven batch tool below -
// counts unique journal ISSNs in use, not publications, since quartile is
// a journal-level fact fetched once per ISSN regardless of how many
// publications share that journal.
$unique_issns_stmt = $pdo->query("SELECT DISTINCT journal_issn FROM `publications` WHERE journal_issn IS NOT NULL AND journal_issn != ''");
$unique_issn_count = 0;
$seen_clean_issns = [];
foreach ($unique_issns_stmt->fetchAll(PDO::FETCH_COLUMN) as $raw_issn) {
    $clean = preg_replace('/[^0-9Xx]/', '', $raw_issn);
    if (strlen($clean) >= 8) {
        $seen_clean_issns[$clean] = true;
    }
}
$unique_issn_count = count($seen_clean_issns);
$issn_fetched_count = (int)$pdo->query("SELECT COUNT(DISTINCT issn) FROM `journal_quartiles` WHERE source = 'scopus_citescore_api'")->fetchColumn();
$issn_pending_count = max(0, $unique_issn_count - $issn_fetched_count);

// Yearly Quartile Distribution across publications
$yearly_quartiles = $pdo->query("
    SELECT publish_year,
           COUNT(*) as total_pubs,
           SUM(CASE WHEN quartile = 'Q1' THEN 1 ELSE 0 END) as q1_count,
           SUM(CASE WHEN quartile = 'Q2' THEN 1 ELSE 0 END) as q2_count,
           SUM(CASE WHEN quartile = 'Q3' THEN 1 ELSE 0 END) as q3_count,
           SUM(CASE WHEN quartile = 'Q4' THEN 1 ELSE 0 END) as q4_count,
           SUM(CASE WHEN quartile IS NULL OR quartile = '' OR quartile = 'N/A' THEN 1 ELSE 0 END) as no_q_count
    FROM `publications`
    WHERE publish_year IS NOT NULL AND publish_year != ''
    GROUP BY publish_year
    ORDER BY publish_year DESC
")->fetchAll(PDO::FETCH_ASSOC);

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

<!-- Phase 9: Auto-Quartile from Scopus CiteScore API -->
<div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 30px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 15px;">
        <div>
            <h3 style="margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-bolt" style="color: var(--color-accent);"></i>
                <span>ดึง Quartile อัตโนมัติจาก Scopus CiteScore API</span>
            </h3>
            <p style="font-size: 0.82rem; color: var(--color-text-muted); margin: 6px 0 0; line-height: 1.5; max-width: 640px;">
                ดึง CiteScore Percentile ต่อวารสาร (ตาม ISSN) จาก Scopus โดยตรง แล้วคำนวณ Quartile ตามนิยามที่ Scopus ใช้เอง (Q1 ≥ percentile 75, Q2 ≥ 50, Q3 ≥ 25, Q4 ต่ำกว่านั้น) — <strong>แทนที่ Quartile เดิมทุกครั้งที่ดึงสำเร็จ</strong> รวมถึงค่าที่นำเข้าจาก SCImago/CSV ก่อนหน้านี้ ตามนโยบายที่ใช้ Scopus Quartile เป็นหลักแล้ว
            </p>
        </div>
        <div style="display:flex; gap:8px; flex-shrink:0;">
            <button type="button" id="quartile-start-btn" class="btn-premium" style="padding: 10px 18px;" <?php echo $issn_pending_count === 0 ? 'disabled' : ''; ?>>
                <i class="fa-solid fa-play"></i> เริ่มดึง Quartile
            </button>
            <button type="button" id="quartile-refresh-btn" class="btn-premium" style="padding: 10px 18px; background: rgba(255,255,255,0.05);" <?php echo $unique_issn_count === 0 ? 'disabled' : ''; ?>>
                <i class="fa-solid fa-rotate"></i> Refresh ทั้งหมด
            </button>
            <button type="button" id="quartile-stop-btn" class="btn-premium" style="padding: 10px 18px; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #f87171; display: none;">
                <i class="fa-solid fa-stop"></i> หยุด
            </button>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 6px;">
        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 12px; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.72rem; color: var(--color-text-muted);">วารสารที่ใช้อยู่ทั้งหมด</div>
            <div style="font-size: 1.3rem; font-weight: 700; font-family: var(--font-eng);"><?php echo number_format($unique_issn_count); ?></div>
        </div>
        <div style="background: rgba(16, 185, 129, 0.06); border: 1px solid rgba(16, 185, 129, 0.2); padding: 12px; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.72rem; color: var(--color-text-muted);">ดึงผ่าน API แล้ว</div>
            <div id="quartile-kpi-fetched" style="font-size: 1.3rem; font-weight: 700; color: #10b981; font-family: var(--font-eng);"><?php echo number_format($issn_fetched_count); ?></div>
        </div>
        <div style="background: rgba(239, 68, 68, 0.06); border: 1px solid rgba(239, 68, 68, 0.2); padding: 12px; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.72rem; color: var(--color-text-muted);">ยังไม่เคยดึง</div>
            <div id="quartile-kpi-pending" style="font-size: 1.3rem; font-weight: 700; color: #f87171; font-family: var(--font-eng);"><?php echo number_format($issn_pending_count); ?></div>
        </div>
    </div>

    <div id="quartile-progress" style="display: none; margin-top: 16px;">
        <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 6px;">
            <span id="quartile-status-text">กำลังเริ่มต้น...</span>
            <span id="quartile-counter">0 / 0</span>
        </div>
        <div style="background: rgba(255,255,255,0.05); border-radius: 8px; height: 10px; overflow: hidden;">
            <div id="quartile-bar" style="background: linear-gradient(90deg, var(--color-primary), var(--color-accent)); height: 100%; width: 0%; transition: width 0.2s ease;"></div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 12px;">
            <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#10b981;" id="quartile-applied-count">0</div>ดึงสำเร็จ</div>
            <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#94a3b8;" id="quartile-nomatch-count">0</div>ไม่พบ/ไม่มีข้อมูล</div>
            <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#f87171;" id="quartile-error-count">0</div>ผิดพลาด</div>
        </div>
        <div id="quartile-log" style="margin-top: 12px; max-height: 180px; overflow-y: auto; font-size: 0.75rem; color: var(--color-text-muted); background: rgba(0,0,0,0.15); border-radius: 8px; padding: 10px; display: flex; flex-direction: column-reverse; gap: 4px;"></div>
    </div>
</div>

<!-- Yearly Quartile Distribution Table -->
<?php if (!empty($yearly_quartiles)): ?>
<div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 30px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
        <div>
            <h3 style="margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-chart-column" style="color: var(--color-primary);"></i>
                <span>สรุปจำนวนผลงานและ Quartiles แยกตามปีที่ตีพิมพ์ (Publication Years)</span>
            </h3>
            <p style="font-size: 0.8rem; color: var(--color-text-muted); margin: 4px 0 0 0;">
                แสดงสถานะ Quartile ย้อนหลังตามปีที่ตีพิมพ์จริงของบทความ (ครอบคลุม <?php echo count($yearly_quartiles); ?> ปี)
            </p>
        </div>
        <span class="badge" style="font-size: 0.8rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); padding: 4px 10px; border-radius: 6px; font-family: var(--font-eng);">
            <?php echo end($yearly_quartiles)['publish_year'] ?? ''; ?> – <?php echo $yearly_quartiles[0]['publish_year'] ?? ''; ?>
        </span>
    </div>

    <div style="overflow-x: auto; background: rgba(0,0,0,0.15); border: 1px solid var(--border-glass); border-radius: 10px;">
        <table style="width: 100%; border-collapse: collapse; text-align: center; font-size: 0.85rem;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-glass); background: rgba(255,255,255,0.02); color: var(--color-text-muted); font-size: 0.78rem;">
                    <th style="padding: 12px 14px; text-align: left;">ปีที่ตีพิมพ์ (Year)</th>
                    <th style="padding: 12px 10px; font-family: var(--font-eng);">ผลงานทั้งหมด</th>
                    <th style="padding: 12px 10px; color: #10b981;">Q1</th>
                    <th style="padding: 12px 10px; color: #60a5fa;">Q2</th>
                    <th style="padding: 12px 10px; color: #fbbf24;">Q3</th>
                    <th style="padding: 12px 10px; color: #f87171;">Q4</th>
                    <th style="padding: 12px 10px; color: var(--color-text-muted);">ยังไม่มี Q</th>
                    <th style="padding: 12px 14px; text-align: right;">สัดส่วน Q1 + Q2</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sum_total = 0;
                $sum_q1 = 0;
                $sum_q2 = 0;
                $sum_q3 = 0;
                $sum_q4 = 0;
                $sum_no_q = 0;
                foreach ($yearly_quartiles as $yq): 
                    $sum_total += (int)$yq['total_pubs'];
                    $sum_q1 += (int)$yq['q1_count'];
                    $sum_q2 += (int)$yq['q2_count'];
                    $sum_q3 += (int)$yq['q3_count'];
                    $sum_q4 += (int)$yq['q4_count'];
                    $sum_no_q += (int)$yq['no_q_count'];
                    $top_tier_pct = $yq['total_pubs'] > 0 ? round((($yq['q1_count'] + $yq['q2_count']) / $yq['total_pubs']) * 100, 1) : 0;
                ?>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.15s ease;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                    <td style="padding: 10px 14px; text-align: left; font-weight: 600; font-family: var(--font-eng); color: var(--color-text-main);">
                        <?php echo htmlspecialchars($yq['publish_year']); ?>
                    </td>
                    <td style="padding: 10px; font-family: var(--font-eng); font-weight: 600;">
                        <?php echo number_format($yq['total_pubs']); ?>
                    </td>
                    <td style="padding: 10px; font-family: var(--font-eng); font-weight: 700; color: #10b981;">
                        <?php echo $yq['q1_count'] > 0 ? number_format($yq['q1_count']) : '<span style="color:rgba(255,255,255,0.15);">-</span>'; ?>
                    </td>
                    <td style="padding: 10px; font-family: var(--font-eng); font-weight: 700; color: #60a5fa;">
                        <?php echo $yq['q2_count'] > 0 ? number_format($yq['q2_count']) : '<span style="color:rgba(255,255,255,0.15);">-</span>'; ?>
                    </td>
                    <td style="padding: 10px; font-family: var(--font-eng); font-weight: 700; color: #fbbf24;">
                        <?php echo $yq['q3_count'] > 0 ? number_format($yq['q3_count']) : '<span style="color:rgba(255,255,255,0.15);">-</span>'; ?>
                    </td>
                    <td style="padding: 10px; font-family: var(--font-eng); font-weight: 700; color: #f87171;">
                        <?php echo $yq['q4_count'] > 0 ? number_format($yq['q4_count']) : '<span style="color:rgba(255,255,255,0.15);">-</span>'; ?>
                    </td>
                    <td style="padding: 10px; font-family: var(--font-eng); color: var(--color-text-muted);">
                        <?php echo $yq['no_q_count'] > 0 ? number_format($yq['no_q_count']) : '<span style="color:rgba(255,255,255,0.15);">-</span>'; ?>
                    </td>
                    <td style="padding: 10px 14px; text-align: right; font-family: var(--font-eng); font-weight: 600; color: <?php echo $top_tier_pct >= 70 ? '#10b981' : ($top_tier_pct >= 50 ? '#60a5fa' : '#fbbf24'); ?>;">
                        <?php echo $top_tier_pct; ?>%
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php 
                $grand_top_pct = $sum_total > 0 ? round((($sum_q1 + $sum_q2) / $sum_total) * 100, 1) : 0;
                ?>
                <tr style="background: rgba(255,255,255,0.05); font-weight: 700; border-top: 2px solid var(--border-glass); color: var(--color-text-main);">
                    <td style="padding: 12px 14px; text-align: left;">รวมทั้งหมด</td>
                    <td style="padding: 12px 10px; font-family: var(--font-eng);"><?php echo number_format($sum_total); ?></td>
                    <td style="padding: 12px 10px; font-family: var(--font-eng); color: #10b981;"><?php echo number_format($sum_q1); ?></td>
                    <td style="padding: 12px 10px; font-family: var(--font-eng); color: #60a5fa;"><?php echo number_format($sum_q2); ?></td>
                    <td style="padding: 12px 10px; font-family: var(--font-eng); color: #fbbf24;"><?php echo number_format($sum_q3); ?></td>
                    <td style="padding: 12px 10px; font-family: var(--font-eng); color: #f87171;"><?php echo number_format($sum_q4); ?></td>
                    <td style="padding: 12px 10px; font-family: var(--font-eng); color: var(--color-text-muted);"><?php echo number_format($sum_no_q); ?></td>
                    <td style="padding: 12px 14px; text-align: right; font-family: var(--font-eng); color: #10b981; font-size: 1rem;"><?php echo $grand_top_pct; ?>%</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const startBtn = document.getElementById('quartile-start-btn');
    const refreshBtn = document.getElementById('quartile-refresh-btn');
    const stopBtn = document.getElementById('quartile-stop-btn');
    if (!startBtn) return;

    const progressEl = document.getElementById('quartile-progress');
    const statusText = document.getElementById('quartile-status-text');
    const counterEl = document.getElementById('quartile-counter');
    const barEl = document.getElementById('quartile-bar');
    const logEl = document.getElementById('quartile-log');
    const countEls = {
        applied: document.getElementById('quartile-applied-count'),
        no_match: document.getElementById('quartile-nomatch-count'),
        error: document.getElementById('quartile-error-count'),
    };

    let stopRequested = false;

    function log(text, color) {
        const div = document.createElement('div');
        if (color) div.style.color = color;
        div.textContent = text;
        logEl.prepend(div);
    }

    async function runBatch(mode) {
        const confirmMsg = mode === 'refresh'
            ? 'ระบบจะดึง Quartile ใหม่จาก Scopus ให้กับวารสารทั้งหมดที่ใช้อยู่ (รวมที่เคยดึงแล้ว) และเขียนทับ Quartile เดิมของผลงานที่เกี่ยวข้องทุกรายการ ยืนยันที่จะเริ่มหรือไม่?'
            : 'ระบบจะดึง Quartile จาก Scopus ให้กับวารสารที่ยังไม่เคยดึง ยืนยันที่จะเริ่มหรือไม่?';
        if (!confirm(confirmMsg)) return;

        stopRequested = false;
        startBtn.style.display = 'none';
        refreshBtn.style.display = 'none';
        stopBtn.style.display = 'inline-flex';
        progressEl.style.display = 'block';
        counterEl.textContent = '0 / 0';
        barEl.style.width = '0%';
        logEl.innerHTML = '';
        Object.values(countEls).forEach(el => el.textContent = '0');
        const counts = { applied: 0, no_match: 0, error: 0 };

        statusText.textContent = 'กำลังดึงรายชื่อวารสาร...';
        let items;
        try {
            const listResp = await fetch('fetch_quartiles.php?action=list' + (mode === 'refresh' ? '&mode=refresh' : ''));
            const listData = await listResp.json();
            if (listData.error) {
                statusText.textContent = 'เกิดข้อผิดพลาด: ' + listData.error;
                startBtn.style.display = 'inline-flex';
                refreshBtn.style.display = 'inline-flex';
                stopBtn.style.display = 'none';
                return;
            }
            items = listData.items;
        } catch (e) {
            statusText.textContent = 'เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่';
            startBtn.style.display = 'inline-flex';
            refreshBtn.style.display = 'inline-flex';
            stopBtn.style.display = 'none';
            return;
        }

        const total = items.length;
        for (let i = 0; i < total; i++) {
            if (stopRequested) {
                log('--- หยุดโดยผู้ใช้ ---', '#f59e0b');
                break;
            }
            const item = items[i];
            counterEl.textContent = (i + 1) + ' / ' + total;
            barEl.style.width = Math.round(((i + 1) / total) * 100) + '%';
            statusText.textContent = 'กำลังประมวลผล ISSN: ' + item.issn;

            try {
                const resp = await fetch('fetch_quartiles.php?action=process&issn=' + encodeURIComponent(item.issn));
                const data = await resp.json();
                const status = data.status || 'error';
                counts[status] = (counts[status] || 0) + 1;
                if (countEls[status]) countEls[status].textContent = counts[status];

                if (status === 'applied') {
                    log('✓ ' + item.issn + ' (' + (data.title || '?') + ') → ' + data.quartile + ' (percentile ' + data.percentile + ')', '#10b981');
                } else if (status === 'no_match') {
                    log('… ' + item.issn + ' ' + (data.reason || 'ไม่พบข้อมูล'), '#94a3b8');
                } else {
                    log('✗ ' + item.issn + ' ผิดพลาด: ' + (data.reason || data.error || 'unknown'), '#f87171');
                }
            } catch (e) {
                counts.error++;
                countEls.error.textContent = counts.error;
                log('✗ ' + item.issn + ' เชื่อมต่อไม่สำเร็จ', '#f87171');
            }
        }

        statusText.textContent = stopRequested ? 'หยุดกระบวนการแล้ว' : 'ประมวลผลเสร็จสิ้นสมบูรณ์';
        stopBtn.style.display = 'none';
        startBtn.style.display = 'inline-flex';
        refreshBtn.style.display = 'inline-flex';

        const kpiFetched = document.getElementById('quartile-kpi-fetched');
        const kpiPending = document.getElementById('quartile-kpi-pending');
        if (kpiFetched && kpiPending && mode !== 'refresh') {
            let currentFetched = parseInt(kpiFetched.textContent.replace(/,/g, ''), 10) || 0;
            let currentPending = parseInt(kpiPending.textContent.replace(/,/g, ''), 10) || 0;
            kpiFetched.textContent = (currentFetched + counts.applied).toLocaleString();
            kpiPending.textContent = Math.max(0, currentPending - counts.applied - counts.no_match - counts.error).toLocaleString();
        }
    }

    startBtn.addEventListener('click', () => runBatch('initial'));
    refreshBtn.addEventListener('click', () => runBatch('refresh'));
    stopBtn.addEventListener('click', () => {
        stopRequested = true;
        statusText.textContent = 'กำลังหยุด...';
    });
});
</script>

<?php require_once __DIR__ . '/admin_footer.php'; ?>
