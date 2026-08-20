<?php
// profile.php
// Detailed profile for a single researcher displaying their publications and personal metrics

$current_page = 'researchers';

require_once __DIR__ . '/includes/functions.php';

$researcher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$researcher = get_researcher_by_id($pdo, $researcher_id);

if (!$researcher) {
    // Researcher not found
    header('Location: researchers_list.php');
    exit;
}

$page_title = ($researcher['title_th'] ?? '') . ' ' . $researcher['first_name_th'] . ' ' . $researcher['last_name_th'] . ' - MSC Research';

$publications = get_researcher_publications($pdo, $researcher_id);

// Fetch Corresponding Author Publications
$stmtCorr = $pdo->prepare("SELECT * FROM `corresponding_publications` WHERE researcher_id = ? ORDER BY id DESC");
$stmtCorr->execute([$researcher_id]);
$corresponding_publications = $stmtCorr->fetchAll();

// Calculate metrics
$total_citations = 0;
$first_author_count = 0;
$co_author_count = 0;
$yearly_data = [];
$quartile_counts = ['Q1' => 0, 'Q2' => 0, 'Q3' => 0, 'Q4' => 0];
$researcher_sdgs = [];
$rcr_list = [];
$country_list = [];

foreach ($publications as $pub) {
    $total_citations += $pub['citation_count'];
    $year = $pub['publish_year'];
    if (!isset($yearly_data[$year])) {
        $yearly_data[$year] = 0;
    }
    $yearly_data[$year]++;

    $role = determine_author_role($pub['authors'], $researcher['first_name_en'], $researcher['last_name_en']);
    if ($role === 'First Author') {
        $first_author_count++;
    } else {
        $co_author_count++;
    }

    // Quartiles
    $q = $pub['quartile'] ?? '';
    if (isset($quartile_counts[$q])) {
        $quartile_counts[$q]++;
    }

    // SDGs
    foreach (['sdg_primary', 'sdg_secondary', 'sdg_tertiary'] as $slot) {
        $s = $pub[$slot] ?? '';
        if (!empty($s) && preg_match('/SDG\s*(\d+)/i', $s, $m)) {
            $num = (int)$m[1];
            $researcher_sdgs[$num] = ($researcher_sdgs[$num] ?? 0) + 1;
        }
    }

    // RCR
    if ($pub['rcr'] !== null && $pub['rcr'] !== '') {
        $rcr_list[] = (float)$pub['rcr'];
    }

    // Countries
    if (!empty($pub['countries'])) {
        $c_parts = preg_split('/\s*[,;]\s*/', $pub['countries']);
        foreach ($c_parts as $cp) {
            $cp = trim($cp);
            if (!empty($cp) && strcasecmp($cp, 'Thailand') !== 0) {
                $country_list[$cp] = ($country_list[$cp] ?? 0) + 1;
            }
        }
    }
}
ksort($yearly_data);
arsort($researcher_sdgs);
arsort($country_list);

$q1_q2_total = $quartile_counts['Q1'] + $quartile_counts['Q2'];
$pub_total = count($publications);
$q1_q2_pct = $pub_total > 0 ? round(($q1_q2_total / $pub_total) * 100) : 0;

$avg_rcr = !empty($rcr_list) ? (array_sum($rcr_list) / count($rcr_list)) : null;
$max_rcr = !empty($rcr_list) ? max($rcr_list) : null;

// Fetch Top Topics from OpenAlex for this researcher
$top_topics = [];
$pub_ids = array_column($publications, 'id');
if (!empty($pub_ids)) {
    $placeholders = implode(',', array_fill(0, count($pub_ids), '?'));
    $topics_stmt = $pdo->prepare("
        SELECT pt.display_name, COUNT(*) as cnt, pt.subfield, pt.field
        FROM publication_topics pt
        WHERE pt.publication_id IN ($placeholders)
        GROUP BY pt.display_name, pt.subfield, pt.field
        ORDER BY cnt DESC
        LIMIT 2
    ");
    $topics_stmt->execute($pub_ids);
    $top_topics = $topics_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Prepare Chart.js data
$chart_years = array_keys($yearly_data);
$chart_counts = array_values($yearly_data);

include_once __DIR__ . '/includes/header.php';
?>

<!-- Back Button -->
<div style="margin-bottom: 20px;" class="animate-fade-in">
    <a href="researchers_list.php" style="text-decoration: none; color: var(--color-primary); font-size: 0.95rem; display: inline-flex; align-items: center; gap: 6px;">
        <i class="fa-solid fa-arrow-left"></i> ย้อนกลับไปรายชื่อนักวิจัย
    </a>
</div>

<!-- Profile Info Card -->
<div class="glass-panel animate-fade-in" style="padding: 40px; display: flex; gap: 30px; margin-bottom: 30px; flex-wrap: wrap; align-items: center;">
    <div class="researcher-avatar" style="width: 140px; height: 140px; font-size: 3.5rem; border-width: 4px;">
        <?php if (!empty($researcher['avatar_url'])): ?>
            <img src="<?php echo htmlspecialchars($researcher['avatar_url']); ?>" alt="avatar">
        <?php else: ?>
            <i class="fa-solid fa-user-tie"></i>
        <?php endif; ?>
    </div>
    
    <div style="flex-grow: 1; min-width: 300px;">
        <div style="font-size: 1.8rem; font-weight: 700; margin-bottom: 4px;">
            <?php echo htmlspecialchars(($researcher['title_th'] ?? '') . ' ' . $researcher['first_name_th'] . ' ' . $researcher['last_name_th']); ?>
        </div>
        <div style="font-size: 1.1rem; color: var(--color-text-muted); margin-bottom: 12px; font-family: var(--font-eng);">
            <?php echo htmlspecialchars(($researcher['title_en'] ?? '') . ' ' . $researcher['first_name_en'] . ' ' . $researcher['last_name_en']); ?>
        </div>
        
        <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; font-size: 0.95rem;">
            <div>
                <i class="fa-solid fa-building-columns" style="color: var(--color-primary); width: 20px;"></i>
                <span>ภาควิชา: <strong><?php echo htmlspecialchars($researcher['department']); ?></strong></span>
            </div>
            <div>
                <i class="fa-solid fa-user-tag" style="color: var(--color-accent); width: 20px;"></i>
                <span>ประเภทบุคลากร: <strong><?php echo htmlspecialchars($researcher['researcher_type'] ?? 'สายวิชาการ'); ?></strong></span>
            </div>
            <?php if (!empty($researcher['email'])): ?>
                <div>
                    <i class="fa-solid fa-envelope" style="color: var(--color-secondary); width: 20px;"></i>
                    <span>อีเมล: <a href="mailto:<?php echo htmlspecialchars($researcher['email']); ?>" style="color: inherit; text-decoration: underline;"><?php echo htmlspecialchars($researcher['email']); ?></a></span>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- External Profiles Links -->
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <?php if (!empty($researcher['orcid_id'])): ?>
                <a href="https://orcid.org/<?php echo htmlspecialchars($researcher['orcid_id']); ?>" target="_blank" class="badge badge-orcid" style="padding: 6px 12px; text-decoration: none; font-weight: 500;">
                    <i class="fa-brands fa-orcid"></i> ORCID Profile <i class="fa-solid fa-external-link" style="font-size: 0.65rem;"></i>
                </a>
            <?php endif; ?>
            <?php if (!empty($researcher['scopus_author_id'])): ?>
                <a href="https://www.scopus.com/authid/detail.uri?authorId=<?php echo htmlspecialchars($researcher['scopus_author_id']); ?>" target="_blank" class="badge badge-scopus" style="padding: 6px 12px; text-decoration: none; font-weight: 500;">
                    <i class="fa-solid fa-graduation-cap"></i> Scopus Author Profile <i class="fa-solid fa-external-link" style="font-size: 0.65rem;"></i>
                </a>
            <?php endif; ?>
            <?php if (!empty($researcher['google_scholar_id'])): ?>
                <a href="https://scholar.google.com/citations?user=<?php echo htmlspecialchars($researcher['google_scholar_id']); ?>" target="_blank" class="badge badge-scholar" style="padding: 6px 12px; text-decoration: none; font-weight: 500;">
                    <i class="fa-solid fa-graduation-cap"></i> Google Scholar Profile <i class="fa-solid fa-external-link" style="font-size: 0.65rem;"></i>
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Metrics Boxes -->
    <div style="display: flex; gap: 15px; min-width: 250px; flex-wrap: wrap;">
        <div class="glass-panel" style="padding: 15px 25px; text-align: center; flex: 1; min-width: 110px;">
            <div style="font-size: 1.8rem; font-weight: 700; color: var(--color-primary); font-family: var(--font-eng); line-height: 1.2;"><?php echo count($publications); ?></div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted); margin-bottom: 6px;">ผลงานตีพิมพ์</div>
            <div style="font-size: 0.72rem; color: var(--color-text-muted); border-top: 1px solid var(--border-glass); padding-top: 6px; display: flex; justify-content: center; gap: 6px; font-weight: 500;">
                <span style="color: #34d399;">First: <?php echo $first_author_count; ?></span>
                <span>|</span>
                <span style="color: #60a5fa;">Co: <?php echo $co_author_count; ?></span>
            </div>
        </div>
        <div class="glass-panel" style="padding: 15px 25px; text-align: center; flex: 1; min-width: 110px;">
            <div style="font-size: 1.8rem; font-weight: 700; color: var(--color-accent); font-family: var(--font-eng);"><?php echo $total_citations; ?></div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">Citations รวม</div>
        </div>
        <div class="glass-panel" style="padding: 15px 25px; text-align: center; flex: 1; min-width: 110px;">
            <div style="font-size: 1.8rem; font-weight: 700; color: #10b981; font-family: var(--font-eng);"><?php echo get_researcher_h_index_peak($pdo, $researcher_id); ?></div>
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">h-index</div>
        </div>
    </div>
</div>

<!-- Executive Highlights (4 Cards Grid) -->
<?php if (!empty($publications)): ?>
<div style="margin-bottom: 35px;" class="animate-fade-in">
    <div style="font-size: 1rem; font-weight: 700; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; color: var(--color-text-main);">
        <i class="fa-solid fa-sparkles" style="color: var(--color-accent);"></i>
        <span>ภาพรวมความเชี่ยวชาญและคุณภาพผลงานวิจัย (Research Highlights & Impact)</span>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px;">
        <!-- Card 1: Quartiles -->
        <div class="glass-panel" style="padding: 20px; border-top: 3px solid #10b981; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <span style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 600; text-transform: uppercase;">
                        <i class="fa-solid fa-award" style="color: #10b981; margin-right: 4px;"></i> คุณภาพวารสาร (Quartiles)
                    </span>
                    <span style="font-size: 0.75rem; font-weight: 700; color: #10b981; background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.3); padding: 2px 7px; border-radius: 4px; font-family: var(--font-eng);">
                        Q1+Q2: <?php echo $q1_q2_pct; ?>%
                    </span>
                </div>
                
                <!-- Visual Bar -->
                <?php 
                $q_total_classified = $quartile_counts['Q1'] + $quartile_counts['Q2'] + $quartile_counts['Q3'] + $quartile_counts['Q4'];
                $q1_w = $q_total_classified > 0 ? ($quartile_counts['Q1'] / $q_total_classified) * 100 : 0;
                $q2_w = $q_total_classified > 0 ? ($quartile_counts['Q2'] / $q_total_classified) * 100 : 0;
                $q3_w = $q_total_classified > 0 ? ($quartile_counts['Q3'] / $q_total_classified) * 100 : 0;
                $q4_w = $q_total_classified > 0 ? ($quartile_counts['Q4'] / $q_total_classified) * 100 : 0;
                ?>
                <div style="height: 8px; border-radius: 4px; overflow: hidden; display: flex; background: rgba(255,255,255,0.05); margin-bottom: 14px;">
                    <?php if ($q1_w > 0): ?><div style="width: <?php echo $q1_w; ?>%; background: #10b981;" title="Q1: <?php echo $quartile_counts['Q1']; ?>"></div><?php endif; ?>
                    <?php if ($q2_w > 0): ?><div style="width: <?php echo $q2_w; ?>%; background: #3b82f6;" title="Q2: <?php echo $quartile_counts['Q2']; ?>"></div><?php endif; ?>
                    <?php if ($q3_w > 0): ?><div style="width: <?php echo $q3_w; ?>%; background: #f59e0b;" title="Q3: <?php echo $quartile_counts['Q3']; ?>"></div><?php endif; ?>
                    <?php if ($q4_w > 0): ?><div style="width: <?php echo $q4_w; ?>%; background: #ef4444;" title="Q4: <?php echo $quartile_counts['Q4']; ?>"></div><?php endif; ?>
                </div>

                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; text-align: center;">
                    <div style="background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2); border-radius: 6px; padding: 6px 2px;">
                        <div style="font-size: 0.68rem; color: #10b981; font-weight: 700;">Q1</div>
                        <div style="font-size: 1.1rem; font-weight: 700; font-family: var(--font-eng); color: #10b981;"><?php echo $quartile_counts['Q1']; ?></div>
                    </div>
                    <div style="background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.2); border-radius: 6px; padding: 6px 2px;">
                        <div style="font-size: 0.68rem; color: #60a5fa; font-weight: 700;">Q2</div>
                        <div style="font-size: 1.1rem; font-weight: 700; font-family: var(--font-eng); color: #60a5fa;"><?php echo $quartile_counts['Q2']; ?></div>
                    </div>
                    <div style="background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.2); border-radius: 6px; padding: 6px 2px;">
                        <div style="font-size: 0.68rem; color: #fbbf24; font-weight: 700;">Q3</div>
                        <div style="font-size: 1.1rem; font-weight: 700; font-family: var(--font-eng); color: #fbbf24;"><?php echo $quartile_counts['Q3']; ?></div>
                    </div>
                    <div style="background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); border-radius: 6px; padding: 6px 2px;">
                        <div style="font-size: 0.68rem; color: #f87171; font-weight: 700;">Q4</div>
                        <div style="font-size: 1.1rem; font-weight: 700; font-family: var(--font-eng); color: #f87171;"><?php echo $quartile_counts['Q4']; ?></div>
                    </div>
                </div>
            </div>
            <?php $no_q_count = $pub_total - $q_total_classified; ?>
            <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 12px; border-top: 1px solid var(--border-glass); padding-top: 8px; display: flex; justify-content: space-between; align-items: center;">
                <span>ยึดตาม Scopus CiteScore</span>
                <?php if ($no_q_count > 0): ?>
                    <span style="color: var(--color-text-muted); background: rgba(255,255,255,0.04); padding: 1px 6px; border-radius: 4px;" title="ผลงานในวารสารที่ยังไม่มีการจัดอันดับ CiteScore หรือยุติการตีพิมพ์ไปแล้ว">
                        ไม่มี Q: <strong><?php echo $no_q_count; ?></strong>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card 2: SDGs Focus -->
        <div class="glass-panel" style="padding: 20px; border-top: 3px solid var(--color-accent); display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <span style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 600; text-transform: uppercase;">
                        <i class="fa-solid fa-bullseye" style="color: var(--color-accent); margin-right: 4px;"></i> SDGs เชี่ยวชาญหลัก
                    </span>
                    <span style="font-size: 0.72rem; color: var(--color-text-muted);">
                        พบ <?php echo count($researcher_sdgs); ?> เป้าหมาย
                    </span>
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php if (!empty($researcher_sdgs)): ?>
                        <?php 
                        $top_sdgs = array_slice($researcher_sdgs, 0, 3, true);
                        foreach ($top_sdgs as $sdg_num => $cnt): 
                            $sdg_code = 'SDG ' . $sdg_num;
                            $sdg_info = get_sdg_badge_details($sdg_code);
                            $sdg_color = $sdg_info['color'] ?? '#3b82f6';
                            $sdg_name = $sdg_info['th_name'] ?? $sdg_info['name'] ?? ('SDG ' . $sdg_num);
                        ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 6px 10px; border-radius: 6px;">
                                <div style="display: flex; align-items: center; gap: 8px; overflow: hidden;">
                                    <span style="background: <?php echo $sdg_color; ?>; color: white; width: 20px; height: 20px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.72rem; font-weight: 900; flex-shrink: 0;">
                                        <?php echo $sdg_num; ?>
                                    </span>
                                    <span style="font-size: 0.8rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--color-text-main);">
                                        <?php echo htmlspecialchars($sdg_name); ?>
                                    </span>
                                </div>
                                <span style="font-size: 0.75rem; font-weight: 700; font-family: var(--font-eng); color: <?php echo $sdg_color; ?>; background: <?php echo $sdg_color; ?>18; padding: 2px 7px; border-radius: 4px; flex-shrink: 0;">
                                    <?php echo $cnt; ?> เรื่อง
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size: 0.8rem; color: var(--color-text-muted); text-align: center; padding: 15px;">ยังไม่มีการระบุเป้าหมาย SDG</div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 12px; border-top: 1px solid var(--border-glass); padding-top: 8px;">
                จำแนกตามพจนานุกรมคำสำคัญของคณะ
            </div>
        </div>

        <!-- Card 3: RCR & Citations -->
        <div class="glass-panel" style="padding: 20px; border-top: 3px solid #60a5fa; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <span style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 600; text-transform: uppercase;">
                        <i class="fa-solid fa-chart-line" style="color: #60a5fa; margin-right: 4px;"></i> ดัชนีอ้างอิงสากล (NIH RCR)
                    </span>
                    <span style="font-size: 0.72rem; color: var(--color-text-muted);">
                        NIH iCite Benchmark
                    </span>
                </div>

                <?php if ($avg_rcr !== null): ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                        <div style="background: rgba(16,185,129,0.06); border: 1px solid rgba(16,185,129,0.2); border-radius: 8px; padding: 10px; text-align: center;">
                            <div style="font-size: 0.7rem; color: var(--color-text-muted);">RCR เฉลี่ย</div>
                            <div style="font-size: 1.4rem; font-weight: 700; color: #10b981; font-family: var(--font-eng);"><?php echo number_format($avg_rcr, 2); ?></div>
                        </div>
                        <div style="background: rgba(59,130,246,0.06); border: 1px solid rgba(59,130,246,0.2); border-radius: 8px; padding: 10px; text-align: center;">
                            <div style="font-size: 0.7rem; color: var(--color-text-muted);">RCR สูงสุด</div>
                            <div style="font-size: 1.4rem; font-weight: 700; color: #60a5fa; font-family: var(--font-eng);"><?php echo number_format($max_rcr, 2); ?></div>
                        </div>
                    </div>
                    <div style="font-size: 0.75rem; color: #10b981; font-weight: 500; display: flex; align-items: center; gap: 5px;">
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?php echo $avg_rcr >= 1.0 ? 'ได้รับการอ้างอิงสูงกว่าเกณฑ์เฉลี่ยโลก' : 'อยู่ในเกณฑ์มาตรฐานสากล'; ?></span>
                    </div>
                <?php else: ?>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted); text-align: center; padding: 20px 0;">
                        ไม่มีผลงานที่มีข้อมูลใน PubMed / NIH iCite
                    </div>
                <?php endif; ?>
            </div>
            <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 12px; border-top: 1px solid var(--border-glass); padding-top: 8px;">
                เกณฑ์มาตรฐานโลก = 1.00 (NIH Benchmark)
            </div>
        </div>

        <!-- Card 4: Global Network & Topics -->
        <div class="glass-panel" style="padding: 20px; border-top: 3px solid #fbbf24; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 600; text-transform: uppercase;">
                        <i class="fa-solid fa-globe" style="color: #fbbf24; margin-right: 4px;"></i> เครือข่ายสากล & หัวข้อวิจัย
                    </span>
                </div>

                <!-- Top Countries -->
                <div style="margin-bottom: 10px;">
                    <div style="font-size: 0.7rem; color: var(--color-text-muted); margin-bottom: 5px;">ประเทศร่วมวิจัยหลัก:</div>
                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                        <?php if (!empty($country_list)): ?>
                            <?php 
                            $top_countries = array_slice($country_list, 0, 3, true);
                            foreach ($top_countries as $c_name => $c_cnt): 
                                $c_flag = get_country_flag_url($c_name);
                            ?>
                                <span class="badge" style="font-size: 0.72rem; padding: 3px 8px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass);">
                                    <?php if ($c_flag): ?>
                                        <img src="<?php echo $c_flag; ?>" style="width: 13px; height: 9px; vertical-align: middle; border-radius: 1px; margin-right: 3px;" alt="" />
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($c_name); ?> (<?php echo $c_cnt; ?>)
                                </span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="font-size: 0.72rem; color: var(--color-text-muted); font-style: italic;">งานวิจัยในประเทศ</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top OpenAlex Topics -->
                <?php if (!empty($top_topics)): ?>
                    <div style="border-top: 1px dashed var(--border-glass); padding-top: 8px;">
                        <div style="font-size: 0.7rem; color: var(--color-text-muted); margin-bottom: 5px;">หัวข้อวิจัยเด่น (OpenAlex):</div>
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <?php foreach ($top_topics as $tp): ?>
                                <div style="font-size: 0.72rem; color: var(--color-text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 5px;">
                                    <i class="fa-solid fa-tag" style="color: var(--color-primary); font-size: 0.6rem;"></i>
                                    <span title="<?php echo htmlspecialchars($tp['display_name']); ?>"><?php echo htmlspecialchars($tp['display_name']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 12px; border-top: 1px solid var(--border-glass); padding-top: 8px;">
                การวิเคราะห์คลัสเตอร์สากล (OpenAlex Topics)
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Main Details Layout Grid -->
<div class="main-grid animate-fade-in" style="animation-delay: 0.15s;">
    <!-- Sidebar (Analytics Chart) -->
    <div class="sidebar glass-panel">
        <div class="sidebar-title">
            <span>สถิติการอัปเดตผลงาน</span>
            <i class="fa-solid fa-chart-line" style="color: var(--color-primary);"></i>
        </div>
        
        <div style="position: relative; height: 200px; width: 100%; margin-bottom: 20px;">
            <canvas id="researcherYearlyChart"></canvas>
        </div>
        
        <div class="filter-group" style="margin-top: 25px; border-top: 1px solid var(--border-glass); padding-top: 15px;">
            <label class="filter-label">ค้นหาภายใต้โปรไฟล์</label>
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" id="search-input" class="search-input" placeholder="ค้นหาชื่อผลงาน, ปี, วารสาร...">
            </div>
        </div>
    </div>

    <!-- Publications List -->
    <div>
        <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-book" style="color: var(--color-primary);"></i> รายการผลงานสิ่งตีพิมพ์ (<?php echo count($publications); ?> รายการ)
        </h3>
        
        <div class="publications-list">
            <?php if (empty($publications)): ?>
                <div class="glass-panel" style="padding: 40px; text-align: center; color: var(--color-text-muted);">
                    <i class="fa-regular fa-folder-open" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
                    ยังไม่มีผลงานตีพิมพ์วิชาการของนักวิจัยท่านนี้ในระบบ
                </div>
            <?php else: ?>
                <?php foreach ($publications as $pub): ?>
                    <div class="glass-panel publication-card searchable-item animate-fade-in">
                        <div class="pub-title"><?php echo htmlspecialchars($pub['title']); ?></div>
                        <div class="pub-authors">ผู้แต่ง: <?php echo htmlspecialchars($pub['authors']); ?></div>
                        
                        <div class="pub-journal">
                            <i class="fa-solid fa-journal-whills"></i> <?php echo htmlspecialchars($pub['journal_name'] ?? 'ไม่ระบุวารสาร'); ?> (<?php echo $pub['publish_year']; ?>)
                        </div>
                        
                        <?php if (!empty($pub['countries'])): ?>
                            <div style="font-size: 0.8rem; margin-bottom: 8px; color: var(--color-text-muted); display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">
                                <i class="fa-solid fa-earth-americas" style="color: #60a5fa; width: 14px;"></i> ประเทศร่วมวิจัย: 
                                <strong>
                                    <?php 
                                    $c_parts = preg_split('/\s*[,;]\s*/', $pub['countries']);
                                    $rendered_countries = [];
                                    foreach ($c_parts as $c_part) {
                                        $c_part = trim($c_part);
                                        if (empty($c_part)) continue;
                                        $c_flag = get_country_flag_url($c_part);
                                        if ($c_flag) {
                                            $rendered_countries[] = '<img src="' . $c_flag . '" style="width: 14px; height: 10px; border-radius: 1px; object-fit: cover; vertical-align: middle; margin-right: 2px; border: 1px solid rgba(255,255,255,0.1);" alt="" /> ' . htmlspecialchars($c_part);
                                        } else {
                                            $rendered_countries[] = htmlspecialchars($c_part);
                                        }
                                    }
                                    echo implode(', ', $rendered_countries);
                                    ?>
                                </strong>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pub['funding_sponsor'])): ?>
                            <div style="font-size: 0.8rem; margin-bottom: 8px; color: var(--color-text-muted);">
                                <i class="fa-solid fa-hand-holding-dollar" style="color: #10b981; width: 14px;"></i> แหล่งทุนวิจัย: <strong><?php echo htmlspecialchars($pub['funding_sponsor']); ?><?php echo !empty($pub['funding_no']) ? ' (' . htmlspecialchars($pub['funding_no']) . ')' : ''; ?></strong>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pub['corresponding_author_name'])): ?>
                            <div style="font-size: 0.8rem; margin-bottom: 8px; color: var(--color-text-muted);">
                                <i class="fa-solid fa-envelope-circle-check" style="color: #f472b6; width: 14px;"></i> ผู้ประสานงาน (Corresponding Author): <strong><?php echo htmlspecialchars($pub['corresponding_author_name']); ?></strong>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pub['abstract'])): ?>
                            <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 15px; line-height: 1.5; background: rgba(0,0,0,0.1); padding: 10px; border-radius: 8px; border-left: 3px solid var(--color-primary);">
                                <strong>บทคัดย่อ (Abstract):</strong> <?php echo htmlspecialchars(mb_strimwidth($pub['abstract'], 0, 300, '...')); ?>
                            </div>
                        <?php endif; ?>

                        <div class="pub-footer">
                            <div class="pub-tags">
                                <span class="pub-source-badge source-<?php echo strtolower($pub['source']); ?>">
                                    <?php echo htmlspecialchars($pub['source']); ?>
                                </span>
                                <?php if (!empty($pub['doi'])): ?>
                                    <span class="badge">
                                        DOI: <?php echo htmlspecialchars($pub['doi']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($pub['quartile'])): ?>
                                    <?php 
                                        $q_color = '#ef4444';
                                        $q_bg = 'rgba(239, 68, 68, 0.15)';
                                        if ($pub['quartile'] === 'Q1') { $q_color = '#10b981'; $q_bg = 'rgba(16, 185, 129, 0.15)'; }
                                        elseif ($pub['quartile'] === 'Q2') { $q_color = '#3b82f6'; $q_bg = 'rgba(59, 130, 246, 0.15)'; }
                                        elseif ($pub['quartile'] === 'Q3') { $q_color = '#f59e0b'; $q_bg = 'rgba(245, 158, 11, 0.15)'; }
                                    ?>
                                    <span class="badge" style="background: <?php echo $q_bg; ?>; color: <?php echo $q_color; ?>; border: 1px solid <?php echo $q_color; ?>44; font-weight: 700;">
                                        <?php echo $pub['quartile']; ?>
                                    </span>
                                <?php endif; ?>
                                <?php 
                                $role = determine_author_role($pub['authors'], $researcher['first_name_en'], $researcher['last_name_en']);
                                $role_class = ($role === 'First Author') 
                                    ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' 
                                    : 'background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3);';
                                ?>
                                <span class="badge" style="<?php echo $role_class; ?> font-weight: 600;">
                                    <?php echo htmlspecialchars($role); ?>
                                </span>
                                <?php if (!empty($pub['sdg_primary'])): ?>
                                    <?php echo render_sdg_badge($pub['sdg_primary'], true); ?>
                                <?php endif; ?>
                                <?php if (!empty($pub['sdg_secondary'])): ?>
                                    <?php echo render_sdg_badge($pub['sdg_secondary'], false); ?>
                                <?php endif; ?>
                                <?php if (!empty($pub['sdg_tertiary'])): ?>
                                    <?php echo render_sdg_badge($pub['sdg_tertiary'], false); ?>
                                <?php endif; ?>
                                <?php echo render_rcr_badge($pub['rcr'] ?? null, $pub['nih_percentile'] ?? null); ?>
                            </div>
                            
                            <div style="display: flex; gap: 15px; align-items: center;">
                                <span class="pub-citations">
                                    <i class="fa-solid fa-quote-right"></i> Cited by: <?php echo (int)$pub['citation_count']; ?>
                                </span>
                                <?php if (!empty($pub['url'])): ?>
                                    <a href="<?php echo htmlspecialchars($pub['url']); ?>" target="_blank" class="btn-premium" style="padding: 6px 12px; font-size: 0.8rem; font-weight: 500;">
                                        <i class="fa-solid fa-external-link"></i> ลิงก์ภายนอก
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Corresponding Author Section (If any) -->
        <?php if (!empty($corresponding_publications)): ?>
            <h3 style="margin-top: 40px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-envelope-open-text" style="color: var(--color-secondary);"></i> ผลงานในฐานะผู้แต่งประสานงาน (Corresponding Author)
            </h3>
            <div class="publications-list">
                <?php foreach ($corresponding_publications as $cpub): ?>
                    <div class="glass-panel publication-card searchable-item animate-fade-in" style="border-left: 3px solid var(--color-secondary);">
                        <div class="pub-title"><?php echo htmlspecialchars($cpub['title']); ?></div>
                        <div class="pub-authors">ผู้แต่ง: <?php echo htmlspecialchars($cpub['authors']); ?></div>
                        <div class="pub-footer" style="margin-top: 15px;">
                            <div class="pub-tags">
                                <span class="badge" style="background: rgba(139, 92, 246, 0.15); color: var(--color-primary); border: 1px solid rgba(139, 92, 246, 0.3); font-weight: 600;">
                                    Corresponding Author
                                </span>
                            </div>
                            <?php if (!empty($cpub['url'])): ?>
                                <a href="<?php echo htmlspecialchars($cpub['url']); ?>" target="_blank" class="btn-premium" style="padding: 6px 12px; font-size: 0.8rem; font-weight: 500; background: linear-gradient(135deg, var(--color-secondary), #b45309);">
                                    <i class="fa-solid fa-external-link"></i> ลิงก์ปลายทาง
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Researcher Yearly Chart
    const ctx = document.getElementById('researcherYearlyChart');
    if (ctx) {
        const isLight = document.body.classList.contains('light-mode');
        const textColor = isLight ? '#475569' : '#94a3b8';
        const gridColor = isLight ? 'rgba(0, 0, 0, 0.05)' : 'rgba(255, 255, 255, 0.05)';

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_years); ?>,
                datasets: [{
                    label: 'จำนวนงานตีพิมพ์',
                    data: <?php echo json_encode($chart_counts); ?>,
                    borderColor: '#ffd700',
                    backgroundColor: 'rgba(255, 215, 0, 0.1)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointBackgroundColor: '#ffd700',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { color: gridColor },
                        ticks: { color: textColor, font: { family: 'Sarabun' } }
                    },
                    y: {
                        grid: { color: gridColor },
                        ticks: { color: textColor, precision: 0 }
                    }
                }
            }
        });
    }
});
</script>

<?php
include_once __DIR__ . '/includes/footer.php';
?>
