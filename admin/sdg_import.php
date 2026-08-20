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
                `sdg_tertiary` = :sdg_tertiary,
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

            // Extract every valid "SDG N" (N = 1-17) code found in a cell,
            // in order. Handles both a single value and a list crammed into
            // one cell (e.g. "SDG 3; SDG 12" - seen in real data: some
            // publications genuinely match 3+ SDGs, and whoever built the
            // source CSV had nowhere else to put the extra ones). Anything
            // that isn't a recognizable SDG code (e.g. the literal Thai
            // "ไม่ทราบ" = "unknown" found in real data) is dropped rather
            // than kept as-is - SDG-03 requires unclassified to be null, not
            // an arbitrary string.
            $extract_sdg_codes = function ($val) {
                if (empty($val)) return [];
                $val = str_replace(['(?)', '( ? )', '?'], '', $val);
                $codes = [];
                foreach (preg_split('/[;,]/', $val) as $piece) {
                    $piece = trim(strtoupper($piece));
                    if ($piece === '') continue;
                    $num = null;
                    if (preg_match('/^SDG\s*(\d+)$/', $piece, $matches)) {
                        $num = (int)$matches[1];
                    } elseif (is_numeric($piece)) {
                        $num = (int)$piece;
                    }
                    if ($num !== null && $num >= 1 && $num <= 17) {
                        $codes[] = "SDG {$num}";
                    }
                }
                return array_values(array_unique($codes));
            };

            // The schema has room for 3 (sdg_primary, sdg_secondary,
            // sdg_tertiary). Combine whatever codes were found across both
            // source cells, keep the first 3 distinct ones, and preserve any
            // overflow as a note in the rationale instead of silently
            // discarding it.
            $all_codes = array_values(array_unique(array_merge(
                $extract_sdg_codes($sdg_primary),
                $extract_sdg_codes($sdg_secondary)
            )));
            $overflow_codes = array_slice($all_codes, 3);
            $sdg_primary = $all_codes[0] ?? null;
            $sdg_secondary = $all_codes[1] ?? null;
            $sdg_tertiary = $all_codes[2] ?? null;
            if (!empty($overflow_codes)) {
                $overflow_note = "[พบ SDG เพิ่มเติมจากไฟล์นำเข้าที่เก็บไม่ได้ (เก็บได้สูงสุด 3 ข้อ): " . implode(', ', $overflow_codes) . "]";
                $rationale = trim($rationale . ' ' . $overflow_note);
            }

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
                    ':sdg_tertiary' => $sdg_tertiary ?: null,
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

// Dictionary status panel (SDG-06a visibility): shows admins whether this
// page is reading the live msc_sdgs dictionary or the bundled fallback, how
// many keyphrases exist per SDG, and how classified the publication data
// currently is - so "what's actually going on" is visible instead of a
// blank import form with no context.
$dict_info = get_sdg_dictionary_info();
try {
    $total_pubs = (int)$pdo->query("SELECT COUNT(*) FROM `publications`")->fetchColumn();
    $classified_pubs = (int)$pdo->query("SELECT COUNT(*) FROM `publications` WHERE (sdg_primary IS NOT NULL AND sdg_primary != '') OR (sdg_secondary IS NOT NULL AND sdg_secondary != '') OR (sdg_tertiary IS NOT NULL AND sdg_tertiary != '')")->fetchColumn();
} catch (PDOException $e) {
    $total_pubs = 0;
    $classified_pubs = 0;
}
$unclassified_pubs = $total_pubs - $classified_pubs;

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

<!-- Dictionary Status Panel -->
<div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 30px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
        <h3 style="margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-database" style="color: var(--color-primary);"></i>
            <span>สถานะพจนานุกรมคำสำคัญ SDGs (msc_sdgs Dictionary)</span>
        </h3>
        <?php if ($dict_info['source'] === 'live'): ?>
            <span style="display:inline-flex; align-items:center; gap:6px; background:rgba(16, 185, 129, 0.15); color:#10b981; border:1px solid rgba(16, 185, 129, 0.3); font-size:0.8rem; padding:4px 12px; border-radius:14px; font-weight:600;">
                <i class="fa-solid fa-bolt"></i> Live: msc_sdgs
            </span>
        <?php elseif ($dict_info['source'] === 'bundled'): ?>
            <span style="display:inline-flex; align-items:center; gap:6px; background:rgba(245, 158, 11, 0.15); color:#f59e0b; border:1px solid rgba(245, 158, 11, 0.3); font-size:0.8rem; padding:4px 12px; border-radius:14px; font-weight:600;">
                <i class="fa-solid fa-box-archive"></i> Bundled copy (สำรอง - อาจไม่ล่าสุด)
            </span>
        <?php else: ?>
            <span style="display:inline-flex; align-items:center; gap:6px; background:rgba(239, 68, 68, 0.15); color:#f87171; border:1px solid rgba(239, 68, 68, 0.3); font-size:0.8rem; padding:4px 12px; border-radius:14px; font-weight:600;">
                <i class="fa-solid fa-circle-exclamation"></i> ไม่พบพจนานุกรม
            </span>
        <?php endif; ?>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px;">
        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">จำนวน SDGs ในพจนานุกรม</div>
            <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo (int)$dict_info['count']; ?> / 17</div>
        </div>
        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">คำสำคัญ (Keyphrases) ทั้งหมด</div>
            <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo number_format(array_sum(array_map(fn($s) => count($s['keyphrases'] ?? []), $dict_info['data']))); ?></div>
        </div>
        <div style="background: rgba(16, 185, 129, 0.06); border: 1px solid rgba(16, 185, 129, 0.2); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">ผลงานที่จำแนก SDG แล้ว</div>
            <div id="kpi-classified-pubs" style="font-size: 1.5rem; font-weight: 700; color: #10b981; font-family: var(--font-eng);"><?php echo number_format($classified_pubs); ?></div>
        </div>
        <div style="background: rgba(239, 68, 68, 0.06); border: 1px solid rgba(239, 68, 68, 0.2); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">ผลงานที่ยังไม่จำแนก</div>
            <div id="kpi-unclassified-pubs" style="font-size: 1.5rem; font-weight: 700; color: #f87171; font-family: var(--font-eng);"><?php echo number_format($unclassified_pubs); ?></div>
        </div>
    </div>

    <!-- Auto-Classify All -->
    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 16px 18px; border-radius: 10px; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <div style="font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color: var(--color-accent);"></i>
                    <span>Auto-Classify All (จัดกลุ่ม SDG อัตโนมัติทั้งคณะ)</span>
                </div>
                <p style="font-size: 0.8rem; color: var(--color-text-muted); margin: 4px 0 0 0; line-height: 1.4;">
                    <strong>เขียน SDG สูงสุด 3 อันดับอัตโนมัติเฉพาะรายการที่คะแนนมั่นใจเพียงพอ</strong> (≥ <span id="min-score-label">0.5</span>) — รายการที่คะแนนต่ำจะถูกข้ามไว้ให้ตรวจสอบด้วยตนเองผ่านปุ่ม "แนะนำ SDG" ทีละรายการแทน ไม่มีการเขียนทับรายการที่จำแนกไว้แล้ว
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-shrink:0;">
                <button type="button" id="auto-classify-start-btn" class="btn-premium" style="padding: 10px 18px;" <?php echo $unclassified_pubs === 0 ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-play"></i> เริ่ม Auto-Classify
                </button>
                <button type="button" id="auto-classify-stop-btn" class="btn-premium" style="padding: 10px 18px; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #f87171; display: none;">
                    <i class="fa-solid fa-stop"></i> หยุด
                </button>
            </div>
        </div>

        <div id="auto-classify-progress" style="display: none; margin-top: 18px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 6px;">
                <span id="auto-classify-status-text">กำลังเริ่มต้น...</span>
                <span id="auto-classify-counter">0 / 0</span>
            </div>
            <div style="background: rgba(255,255,255,0.05); border-radius: 8px; height: 10px; overflow: hidden;">
                <div id="auto-classify-bar" style="background: linear-gradient(90deg, var(--color-primary), var(--color-accent)); height: 100%; width: 0%; transition: width 0.2s ease;"></div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 12px;">
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#10b981;" id="auto-classify-applied-count">0</div>จำแนกสำเร็จ</div>
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#f59e0b;" id="auto-classify-lowconf-count">0</div>คะแนนต่ำ (ข้าม)</div>
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#94a3b8;" id="auto-classify-skipped-count">0</div>มีอยู่แล้ว (ข้าม)</div>
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#f87171;" id="auto-classify-error-count">0</div>ผิดพลาด</div>
            </div>

            <!-- Summary Report Box after completion -->
            <div id="auto-classify-summary-box" style="display:none; margin-top:15px; padding:15px; border-radius:10px; background:rgba(16, 185, 129, 0.08); border:1px solid rgba(16, 185, 129, 0.3);">
                <div style="font-weight:600; font-size:0.95rem; color:#10b981; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-circle-check"></i>
                    <span id="auto-classify-summary-title">ประมวลผล Auto-Classify เสร็จสิ้นเรียบร้อย!</span>
                </div>
                <div id="auto-classify-summary-details" style="font-size:0.85rem; line-height:1.6; color:var(--color-text-main);"></div>
                <div style="margin-top:12px; display:flex; gap:10px;">
                    <button type="button" class="btn-premium" onclick="window.location.reload();" style="padding:6px 14px; font-size:0.8rem;">
                        <i class="fa-solid fa-rotate-right"></i> รีเฟรชหน้าเพื่อดูสถิติใหม่
                    </button>
                </div>
            </div>

            <div id="auto-classify-log" style="margin-top: 12px; max-height: 180px; overflow-y: auto; font-size: 0.75rem; color: var(--color-text-muted); background: rgba(0,0,0,0.15); border-radius: 8px; padding: 10px; display: flex; flex-direction: column-reverse; gap: 4px;"></div>
        </div>
    </div>

    <?php if (!empty($dict_info['data'])): ?>
    <details>
        <summary style="cursor: pointer; font-size: 0.85rem; color: var(--color-text-muted); font-weight: 500; margin-bottom: 10px;">
            <i class="fa-solid fa-chevron-down" style="font-size: 0.7rem;"></i> ดูรายละเอียดคำสำคัญแยกตาม SDG (17 เป้าหมาย)
        </summary>
        <div style="overflow-x: auto; max-height: 320px; overflow-y: auto; margin-top: 10px;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted); position: sticky; top: 0; background: #0f172a;">
                        <th style="padding: 8px;">SDG</th>
                        <th style="padding: 8px;">จำนวนคำสำคัญ</th>
                        <th style="padding: 8px;">อัปเดตล่าสุด (จากพจนานุกรม)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dict_info['data'] as $sdg): ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                            <td style="padding: 8px;">
                                <span style="display:inline-flex; align-items:center; gap:8px;">
                                    <span style="width:12px; height:12px; border-radius:3px; background:<?php echo htmlspecialchars($sdg['color'] ?? '#64748b'); ?>; flex-shrink:0;"></span>
                                    <strong>SDG <?php echo (int)($sdg['num'] ?? 0); ?></strong> — <?php echo htmlspecialchars($sdg['name_th'] ?? $sdg['name'] ?? ''); ?>
                                </span>
                            </td>
                            <td style="padding: 8px; font-family: var(--font-eng);"><?php echo number_format(count($sdg['keyphrases'] ?? [])); ?></td>
                            <td style="padding: 8px; color: var(--color-text-muted); font-size: 0.8rem;"><?php echo htmlspecialchars($sdg['updated'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    const startBtn = document.getElementById('auto-classify-start-btn');
    const stopBtn = document.getElementById('auto-classify-stop-btn');
    if (!startBtn) return;

    const progressEl = document.getElementById('auto-classify-progress');
    const statusText = document.getElementById('auto-classify-status-text');
    const counterEl = document.getElementById('auto-classify-counter');
    const barEl = document.getElementById('auto-classify-bar');
    const logEl = document.getElementById('auto-classify-log');
    const countEls = {
        applied: document.getElementById('auto-classify-applied-count'),
        low_confidence: document.getElementById('auto-classify-lowconf-count'),
        skipped: document.getElementById('auto-classify-skipped-count'),
        error: document.getElementById('auto-classify-error-count'),
    };

    let stopRequested = false;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function log(text, color) {
        const div = document.createElement('div');
        if (color) div.style.color = color;
        div.textContent = text;
        logEl.prepend(div);
    }

    startBtn.addEventListener('click', async () => {
        const totalUnclassified = <?php echo (int)$unclassified_pubs; ?>;
        if (!confirm('ระบบจะดึงบทคัดย่อ/คำสำคัญและเขียน SDG หลัก/รองอัตโนมัติให้กับผลงานที่ยัง Unclassified ทั้งหมด (' + totalUnclassified.toLocaleString() + ' เรื่อง โดยประมาณ) เฉพาะรายการที่คะแนนมั่นใจเพียงพอ - การกระทำนี้เขียนข้อมูลจริงลงฐานข้อมูลทันทีต่อรายการ (ไม่ใช่แค่ตัวอย่าง) ยืนยันที่จะเริ่มหรือไม่?')) {
            return;
        }

        stopRequested = false;
        startBtn.style.display = 'none';
        stopBtn.style.display = 'inline-flex';
        progressEl.style.display = 'block';
        counterEl.textContent = '0 / 0';
        barEl.style.width = '0%';
        logEl.innerHTML = '';
        Object.values(countEls).forEach(el => el.textContent = '0');
        const counts = { applied: 0, low_confidence: 0, skipped: 0, error: 0 };

        statusText.textContent = 'กำลังดึงรายชื่อผลงานที่ยัง Unclassified...';
        let items;
        try {
            const listResp = await fetch('auto_classify_sdgs.php?action=list');
            const listData = await listResp.json();
            if (listData.error) {
                statusText.textContent = 'เกิดข้อผิดพลาด: ' + listData.error;
                startBtn.style.display = 'inline-flex';
                stopBtn.style.display = 'none';
                return;
            }
            items = listData.items;
            if (listData.min_score !== undefined) {
                document.getElementById('min-score-label').textContent = listData.min_score;
            }
        } catch (e) {
            statusText.textContent = 'เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่';
            startBtn.style.display = 'inline-flex';
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
            statusText.textContent = 'กำลังประมวลผล: ' + (item.title || ('#' + item.id));

            try {
                const resp = await fetch('auto_classify_sdgs.php?action=process&id=' + encodeURIComponent(item.id));
                const data = await resp.json();
                const status = data.status || 'error';
                counts[status] = (counts[status] || 0) + 1;
                if (countEls[status]) countEls[status].textContent = counts[status];

                if (status === 'applied') {
                    log('✓ #' + item.id + ' → ' + data.sdg_primary + (data.sdg_secondary ? ', ' + data.sdg_secondary : '') + ' (คะแนน ' + data.top_score + ')', '#10b981');
                } else if (status === 'low_confidence') {
                    log('… #' + item.id + ' ข้าม: ' + (data.reason || 'คะแนนต่ำ'), '#94a3b8');
                } else if (status === 'error') {
                    log('✗ #' + item.id + ' ผิดพลาด: ' + (data.reason || data.error || 'unknown'), '#f87171');
                } else {
                    log('- #' + item.id + ' ' + (data.reason || status), '#94a3b8');
                }
            } catch (e) {
                counts.error++;
                countEls.error.textContent = counts.error;
                log('✗ #' + item.id + ' เชื่อมต่อไม่สำเร็จ', '#f87171');
            }
        }

        statusText.textContent = stopRequested ? 'หยุดกระบวนการแล้ว' : 'ประมวลผลเสร็จสิ้นสมบูรณ์';
        stopBtn.style.display = 'none';
        startBtn.style.display = 'inline-flex';
        startBtn.disabled = false;

        // Dynamic update KPI numbers without page refresh
        const kpiClassified = document.getElementById('kpi-classified-pubs');
        const kpiUnclassified = document.getElementById('kpi-unclassified-pubs');
        if (kpiClassified && kpiUnclassified) {
            let currentClassified = parseInt(kpiClassified.textContent.replace(/,/g, ''), 10) || 0;
            let currentUnclassified = parseInt(kpiUnclassified.textContent.replace(/,/g, ''), 10) || 0;
            let newlyClassified = counts.applied || 0;
            kpiClassified.textContent = (currentClassified + newlyClassified).toLocaleString();
            kpiUnclassified.textContent = Math.max(0, currentUnclassified - newlyClassified).toLocaleString();
        }

        // Show Summary Report Box
        const summaryBox = document.getElementById('auto-classify-summary-box');
        const summaryTitle = document.getElementById('auto-classify-summary-title');
        const summaryDetails = document.getElementById('auto-classify-summary-details');
        if (summaryBox && summaryDetails) {
            summaryBox.style.display = 'block';
            summaryTitle.innerHTML = stopRequested 
                ? '<i class="fa-solid fa-circle-exclamation" style="color:#f59e0b;"></i> <span style="color:#f59e0b;">หยุดการทำงานชั่วคราว</span>' 
                : '<i class="fa-solid fa-circle-check" style="color:#10b981;"></i> <span>ประมวลผล Auto-Classify เสร็จสิ้นเรียบร้อย!</span>';
            
            summaryDetails.innerHTML = `
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-top:8px;">
                    <div>• <strong>จัดกลุ่ม SDG สำเร็จ:</strong> <span style="color:#10b981; font-weight:700;">${counts.applied.toLocaleString()}</span> เรื่อง</div>
                    <div>• <strong>คะแนนต่ำกว่าเกณฑ์ (ข้าม):</strong> <span style="color:#f59e0b; font-weight:700;">${counts.low_confidence.toLocaleString()}</span> เรื่อง</div>
                    <div>• <strong>มี SDG เดิมอยู่แล้ว (ข้าม):</strong> <span style="color:#94a3b8; font-weight:700;">${counts.skipped.toLocaleString()}</span> เรื่อง</div>
                    <div>• <strong>ข้อผิดพลาด/ไม่พบ:</strong> <span style="color:#f87171; font-weight:700;">${counts.error.toLocaleString()}</span> เรื่อง</div>
                </div>
                <div style="margin-top:8px; font-size:0.8rem; color:var(--color-text-muted);">
                    <em>* ข้อมูลที่จัดกลุ่มสำเร็จได้รับการบันทึกลงฐานข้อมูลและอัปเดตไปยังหน้า Dashboard / Reports เรียบร้อยแล้ว สามารถดูประวัติการจับคู่แต่ละรายการได้จากกล่องประวัติสีดำด้านล่าง</em>
                </div>
            `;
        }
    });

    stopBtn.addEventListener('click', () => {
        stopRequested = true;
        statusText.textContent = 'กำลังหยุด...';
    });
});
</script>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
