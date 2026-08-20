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
}
ksort($yearly_data);

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
<div class="glass-panel animate-fade-in" style="padding: 40px; display: flex; gap: 30px; margin-bottom: 40px; flex-wrap: wrap; align-items: center;">
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
