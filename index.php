<?php
// index.php
// Main landing page showing summary dashboard and analytics

$current_page = 'home';
$page_title = 'แดชบอร์ดหลัก';

require_once __DIR__ . '/includes/functions.php';

// Fetch statistics
$total_pubs = get_total_publications($pdo);
$total_researchers = get_total_researchers($pdo);
$total_citations = get_total_citations($pdo);

// Fetch international collaboration count
$stmtInterCollabs = $pdo->query("SELECT COUNT(*) FROM `publications` WHERE countries IS NOT NULL AND TRIM(countries) != '' AND TRIM(REPLACE(REPLACE(countries, 'Thailand', ''), ',', '')) != ''");
$international_collabs_count = $stmtInterCollabs->fetchColumn() ?: 0;

// Fetch RCR (Relative Citation Ratio) statistics from NIH iCite (matches reports.php)
$stmtRCR = $pdo->query("SELECT AVG(rcr) as avg_val, COUNT(rcr) as count_val FROM `publications` WHERE rcr IS NOT NULL");
$rcr_row = $stmtRCR->fetch();
$avg_rcr = ($rcr_row && $rcr_row['avg_val'] !== null) ? (float)$rcr_row['avg_val'] : null;
$rcr_covered_count = ($rcr_row && $rcr_row['count_val'] !== null) ? (int)$rcr_row['count_val'] : 0;

$recent_pubs = get_recent_publications($pdo, 6);
$yearly_stats = get_publications_by_year_summary($pdo);

// Fetch top researcher for publications count (with ties)
// RESEARCHER-05: inactive researchers aren't spotlighted here (their old
// publications still count in $total_pubs/$total_citations above, which
// don't filter by is_active) - but showing a departed researcher as the
// current "top researcher" would be misleading on a public page.
$stmtMaxPubs = $pdo->query("
    SELECT COUNT(rp.publication_id) as val
    FROM `researchers` r
    LEFT JOIN `researcher_publications` rp ON r.id = rp.researcher_id
    WHERE r.is_active = 1
    GROUP BY r.id
    ORDER BY val DESC
    LIMIT 1
");
$max_pub_val = $stmtMaxPubs->fetchColumn() ?: 0;

$stmtTopPubs = $pdo->prepare("
    SELECT r.id, r.title_th, r.first_name_th, r.last_name_th, r.department
    FROM `researchers` r
    LEFT JOIN `researcher_publications` rp ON r.id = rp.researcher_id
    WHERE r.is_active = 1
    GROUP BY r.id
    HAVING COUNT(rp.publication_id) = ?
    ORDER BY r.first_name_th ASC
");
$stmtTopPubs->execute([$max_pub_val]);
$top_pubs = $stmtTopPubs->fetchAll();

// Fetch top researcher for citations count (with ties)
$stmtMaxCitations = $pdo->query("
    SELECT COALESCE(SUM(p.citation_count), 0) as val
    FROM `researchers` r
    LEFT JOIN `researcher_publications` rp ON r.id = rp.researcher_id
    LEFT JOIN `publications` p ON rp.publication_id = p.id
    WHERE r.is_active = 1
    GROUP BY r.id
    ORDER BY val DESC
    LIMIT 1
");
$max_citation_val = $stmtMaxCitations->fetchColumn() ?: 0;

$stmtTopCitations = $pdo->prepare("
    SELECT r.id, r.title_th, r.first_name_th, r.last_name_th, r.department
    FROM `researchers` r
    LEFT JOIN `researcher_publications` rp ON r.id = rp.researcher_id
    LEFT JOIN `publications` p ON rp.publication_id = p.id
    WHERE r.is_active = 1
    GROUP BY r.id
    HAVING COALESCE(SUM(p.citation_count), 0) = ?
    ORDER BY r.first_name_th ASC
");
$stmtTopCitations->execute([$max_citation_val]);
$top_citations = $stmtTopCitations->fetchAll();

// Fetch top researcher for h-index (with ties)
$all_researchers = get_all_researchers($pdo);
$max_h = -1;
$h_values = [];
foreach ($all_researchers as $r) {
    $h = get_researcher_h_index_peak($pdo, $r['id']);
    $h_values[$r['id']] = $h;
    if ($h > $max_h) {
        $max_h = $h;
    }
}

$top_hindices = [];
if ($max_h >= 0) {
    foreach ($all_researchers as $r) {
        if ($h_values[$r['id']] === $max_h) {
            $r['val'] = $max_h;
            $top_hindices[] = $r;
        }
    }
}

// Prepare Chart.js data
$chart_years = [];
$chart_counts = [];
$chart_citations = [];
foreach ($yearly_stats as $stat) {
    $chart_years[] = $stat['year'];
    $chart_counts[] = $stat['count'];
    $chart_citations[] = $stat['citations'];
}

// Average H-Index trend per year: h_index_peak has no stored history (it's
// a running peak snapshot - see calculate_researcher_h_index() in
// functions.php), so each year's average is approximated the same way
// reports.php's leaderboard does it - each researcher's h-index computed
// from only the publications with publish_year <= that year, using
// today's citation_count (not a true historical citation count). Fetch
// every (researcher, year, citations) row once, then compute per-year
// cumulative h-index in PHP to avoid one DB round-trip per year.
$stmtAllResPubs = $pdo->query("
    SELECT rp.researcher_id, p.publish_year, p.citation_count
    FROM `researcher_publications` rp
    JOIN `publications` p ON rp.publication_id = p.id
    WHERE p.publish_year IS NOT NULL AND p.publish_year > 0
");
$all_res_pub_rows = $stmtAllResPubs->fetchAll(PDO::FETCH_ASSOC);

$chart_avg_hindex = [];
foreach ($chart_years as $cutoff_year) {
    $citations_by_researcher = [];
    foreach ($all_res_pub_rows as $row) {
        if ((int)$row['publish_year'] <= (int)$cutoff_year) {
            $citations_by_researcher[$row['researcher_id']][] = (int)$row['citation_count'];
        }
    }

    $h_index_sum = 0;
    foreach ($citations_by_researcher as $citations) {
        rsort($citations);
        $h = 0;
        foreach ($citations as $i => $c) {
            $rank = $i + 1;
            if ($c >= $rank) {
                $h = $rank;
            } else {
                break;
            }
        }
        $h_index_sum += $h;
    }

    $chart_avg_hindex[] = $total_researchers > 0 ? round($h_index_sum / $total_researchers, 2) : 0;
}

// Fetch publication count by quartile
$stmtQuartileStats = $pdo->query("
    SELECT quartile, COUNT(*) as count 
    FROM `publications` 
    GROUP BY quartile
");
$quartile_counts = [
    'Q1' => 0,
    'Q2' => 0,
    'Q3' => 0,
    'Q4' => 0,
    'N/A' => 0
];
while ($row = $stmtQuartileStats->fetch()) {
    $q = strtoupper(trim($row['quartile'] ?? ''));
    if (in_array($q, ['Q1', 'Q2', 'Q3', 'Q4'])) {
        $quartile_counts[$q] += (int)$row['count'];
    } else {
        $quartile_counts['N/A'] += (int)$row['count'];
    }
}

include_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<div class="hero glass-panel animate-fade-in">
    <h2>ระบบคลังผลงานวิจัย <br/>(Research Repository System)</h2>
    <p>บุคลากร คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา</p>
</div>

<!-- Stats Grid -->
<div class="stats-grid animate-fade-in" style="animation-delay: 0.1s;">
    <a href="researchers_list.php" class="stat-card glass-panel" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; justify-content: center; align-items: center; transition: var(--transition-smooth); cursor: pointer;">
        <div class="stat-number"><?php echo number_format($total_researchers); ?></div>
        <div class="stat-label">นักวิจัยในระบบ</div>
        <div style="font-size: 0.75rem; color: var(--color-primary); margin-top: 10px; display: flex; align-items: center; justify-content: center; gap: 5px; font-weight: 500;">
            <span>ดูรายชื่อทั้งหมด</span>
            <i class="fa-solid fa-chevron-right" style="font-size: 0.7rem;"></i>
        </div>
    </a>
    <div class="stat-card glass-panel" style="display: flex; flex-direction: column; justify-content: center; align-items: center;">
        <div class="stat-number"><?php echo number_format($total_pubs); ?></div>
        <div class="stat-label">ผลงานตีพิมพ์ทั้งหมด</div>
        <?php if ($international_collabs_count > 0): ?>
            <a href="reports.php?tab=countries" style="text-decoration: none; font-size: 0.75rem; color: #60a5fa; margin-top: 8px; display: inline-flex; align-items: center; gap: 5px; font-weight: 500; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='#60a5fa'">
                <i class="fa-solid fa-earth-americas" style="font-size: 0.8rem;"></i>
                <span>ร่วมมือต่างประเทศ: <strong><?php echo number_format($international_collabs_count); ?></strong> เรื่อง</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem; opacity: 0.7;"></i>
            </a>
        <?php endif; ?>
    </div>
    <div class="stat-card glass-panel" style="display: flex; flex-direction: column; justify-content: center; align-items: center;">
        <div class="stat-number"><?php echo number_format($total_citations); ?></div>
        <div class="stat-label">จำนวนการอ้างอิง (Citations)</div>
        <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 8px; display: flex; align-items: center; gap: 4px;">
            <span style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass); border-radius: 4px; padding: 2px 6px; font-size: 0.68rem;">
                <i class="fa-solid fa-database" style="color: #60a5fa; margin-right: 3px;"></i> แหล่งอ้างอิง: Scopus
            </span>
        </div>
    </div>
    <a href="reports.php" class="stat-card glass-panel" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; justify-content: center; align-items: center; transition: var(--transition-smooth); cursor: pointer;">
        <div class="stat-number" style="color: #10b981;">
            <?php echo $avg_rcr !== null ? number_format($avg_rcr, 2) : '-'; ?>
        </div>
        <div class="stat-label">ค่า RCR เฉลี่ย (ผลกระทบงานวิจัย)</div>
        <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 8px; display: flex; flex-direction: column; align-items: center; gap: 4px;">
            <span style="display: inline-flex; align-items: center; gap: 4px; background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 4px; padding: 2px 6px; font-size: 0.68rem; font-weight: 500;">
                <i class="fa-solid fa-square-poll-vertical"></i> แหล่งอ้างอิง: NIH iCite (สหรัฐฯ)
            </span>
            <span style="font-size: 0.68rem; color: var(--color-text-muted);">
                เกณฑ์เฉลี่ยโลก = 1.0<?php if ($avg_rcr !== null && $avg_rcr > 1.0): ?> | <strong style="color: #10b981;">+<?php echo round(($avg_rcr - 1.0) * 100); ?>%</strong> เหนือเกณฑ์โลก<?php endif; ?>
            </span>
        </div>
    </a>
</div>

<!-- Scopus Quartiles Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 20px; margin-top: 25px;" class="animate-fade-in">
    <!-- Q1 -->
    <a href="publications_search.php?quartile=Q1" class="stat-card glass-panel" style="text-decoration: none; color: inherit; display: block; transition: var(--transition-smooth); cursor: pointer; border-bottom: 4px solid #10b981; padding: 18px 12px; margin: 0;">
        <div style="font-size: 1.8rem; font-weight: 700; color: #10b981; font-family: var(--font-eng); line-height: 1.2;">
            <?php echo number_format($quartile_counts['Q1']); ?>
        </div>
        <div style="font-size: 0.8rem; font-weight: 600; margin-top: 6px; color: var(--color-text-main);">Scopus Q1</div>
        <div style="font-size: 0.68rem; color: var(--color-text-muted); margin-top: 4px;">
            คิดเป็น <?php echo $total_pubs > 0 ? round($quartile_counts['Q1'] / $total_pubs * 100, 1) : 0; ?>%
        </div>
    </a>
    <!-- Q2 -->
    <a href="publications_search.php?quartile=Q2" class="stat-card glass-panel" style="text-decoration: none; color: inherit; display: block; transition: var(--transition-smooth); cursor: pointer; border-bottom: 4px solid #3b82f6; padding: 18px 12px; margin: 0;">
        <div style="font-size: 1.8rem; font-weight: 700; color: #3b82f6; font-family: var(--font-eng); line-height: 1.2;">
            <?php echo number_format($quartile_counts['Q2']); ?>
        </div>
        <div style="font-size: 0.8rem; font-weight: 600; margin-top: 6px; color: var(--color-text-main);">Scopus Q2</div>
        <div style="font-size: 0.68rem; color: var(--color-text-muted); margin-top: 4px;">
            คิดเป็น <?php echo $total_pubs > 0 ? round($quartile_counts['Q2'] / $total_pubs * 100, 1) : 0; ?>%
        </div>
    </a>
    <!-- Q3 -->
    <a href="publications_search.php?quartile=Q3" class="stat-card glass-panel" style="text-decoration: none; color: inherit; display: block; transition: var(--transition-smooth); cursor: pointer; border-bottom: 4px solid #f59e0b; padding: 18px 12px; margin: 0;">
        <div style="font-size: 1.8rem; font-weight: 700; color: #f59e0b; font-family: var(--font-eng); line-height: 1.2;">
            <?php echo number_format($quartile_counts['Q3']); ?>
        </div>
        <div style="font-size: 0.8rem; font-weight: 600; margin-top: 6px; color: var(--color-text-main);">Scopus Q3</div>
        <div style="font-size: 0.68rem; color: var(--color-text-muted); margin-top: 4px;">
            คิดเป็น <?php echo $total_pubs > 0 ? round($quartile_counts['Q3'] / $total_pubs * 100, 1) : 0; ?>%
        </div>
    </a>
    <!-- Q4 -->
    <a href="publications_search.php?quartile=Q4" class="stat-card glass-panel" style="text-decoration: none; color: inherit; display: block; transition: var(--transition-smooth); cursor: pointer; border-bottom: 4px solid #ef4444; padding: 18px 12px; margin: 0;">
        <div style="font-size: 1.8rem; font-weight: 700; color: #ef4444; font-family: var(--font-eng); line-height: 1.2;">
            <?php echo number_format($quartile_counts['Q4']); ?>
        </div>
        <div style="font-size: 0.8rem; font-weight: 600; margin-top: 6px; color: var(--color-text-main);">Scopus Q4</div>
        <div style="font-size: 0.68rem; color: var(--color-text-muted); margin-top: 4px;">
            คิดเป็น <?php echo $total_pubs > 0 ? round($quartile_counts['Q4'] / $total_pubs * 100, 1) : 0; ?>%
        </div>
    </a>
    <!-- NA -->
    <a href="publications_search.php?quartile=NA" class="stat-card glass-panel" style="text-decoration: none; color: inherit; display: block; transition: var(--transition-smooth); cursor: pointer; border-bottom: 4px solid var(--color-text-muted); padding: 18px 12px; margin: 0;">
        <div style="font-size: 1.8rem; font-weight: 700; color: var(--color-text-muted); font-family: var(--font-eng); line-height: 1.2;">
            <?php echo number_format($quartile_counts['N/A']); ?>
        </div>
        <div style="font-size: 0.8rem; font-weight: 600; margin-top: 6px; color: var(--color-text-main);">Unclassified / อื่นๆ</div>
        <div style="font-size: 0.68rem; color: var(--color-text-muted); margin-top: 4px;">
            คิดเป็น <?php echo $total_pubs > 0 ? round($quartile_counts['N/A'] / $total_pubs * 100, 1) : 0; ?>%
        </div>
    </a>
</div>

<!-- Charts Grid -->
<div class="stats-grid animate-fade-in" style="grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); margin-top: 30px; animation-delay: 0.15s;">
    <div class="glass-panel" style="padding: 24px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-chart-bar" style="color: var(--color-primary);"></i> สถิติการตีพิมพ์รายปี
        </h3>
        <div style="position: relative; height: 260px; width: 100%;">
            <canvas id="yearlyChart"></canvas>
        </div>
    </div>

    <div class="glass-panel" style="padding: 24px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-chart-line" style="color: #06b6d4;"></i> แนวโน้มการอ้างอิงรายปี (Citations)
        </h3>
        <div style="position: relative; height: 260px; width: 100%;">
            <canvas id="citationsChart"></canvas>
        </div>
    </div>

    <div class="glass-panel" style="padding: 24px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-chart-line" style="color: #10b981;"></i> แนวโน้ม H-Index เฉลี่ยรายปี
        </h3>
        <div style="position: relative; height: 260px; width: 100%;">
            <canvas id="hindexChart"></canvas>
        </div>
    </div>
</div>

<!-- Top Researchers (Hall of Fame) -->
<div class="stats-grid animate-fade-in" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-top: 30px; animation-delay: 0.2s;">
    <!-- Top Publications -->
    <div class="glass-panel" style="padding: 20px; display: flex; align-items: flex-start; gap: 15px; border-left: 4px solid var(--color-primary);">
        <div style="background: rgba(var(--color-primary-rgb), 0.1); width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--color-primary); flex-shrink: 0;">
            <i class="fa-solid fa-file-invoice"></i>
        </div>
        <div style="flex: 1;">
            <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">ผลงานตีพิมพ์สูงสุด</div>
            <?php if (!empty($top_pubs)): ?>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($top_pubs as $tp): ?>
                        <div>
                            <a href="profile.php?id=<?php echo $tp['id']; ?>" style="text-decoration: none; color: inherit; display: block;">
                                <div style="font-weight: 700; font-size: 1rem;"><?php echo htmlspecialchars(($tp['title_th'] ? $tp['title_th'] . ' ' : '') . $tp['first_name_th'] . ' ' . $tp['last_name_th']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--color-text-muted);"><?php echo htmlspecialchars($tp['department']); ?></div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="font-size: 1.2rem; font-weight: 700; color: var(--color-primary); margin-top: 10px; font-family: var(--font-eng); border-top: 1px solid var(--border-glass); padding-top: 6px;"><?php echo number_format($max_pub_val); ?> <span style="font-size: 0.8rem; font-weight: 500; color: var(--color-text-muted);">ผลงาน</span></div>
            <?php else: ?>
                <div style="font-size: 0.9rem; color: var(--color-text-muted); margin-top: 4px;">ไม่มีข้อมูล</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Citations -->
    <div class="glass-panel" style="padding: 20px; display: flex; align-items: flex-start; gap: 15px; border-left: 4px solid var(--color-accent);">
        <div style="background: rgba(var(--color-accent-rgb), 0.1); width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--color-accent); flex-shrink: 0;">
            <i class="fa-solid fa-quote-right"></i>
        </div>
        <div style="flex: 1;">
            <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Citations สูงสุด</div>
            <?php if (!empty($top_citations)): ?>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($top_citations as $tc): ?>
                        <div>
                            <a href="profile.php?id=<?php echo $tc['id']; ?>" style="text-decoration: none; color: inherit; display: block;">
                                <div style="font-weight: 700; font-size: 1rem;"><?php echo htmlspecialchars(($tc['title_th'] ? $tc['title_th'] . ' ' : '') . $tc['first_name_th'] . ' ' . $tc['last_name_th']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--color-text-muted);"><?php echo htmlspecialchars($tc['department']); ?></div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="font-size: 1.2rem; font-weight: 700; color: var(--color-accent); margin-top: 10px; font-family: var(--font-eng); border-top: 1px solid var(--border-glass); padding-top: 6px;"><?php echo number_format($max_citation_val); ?> <span style="font-size: 0.8rem; font-weight: 500; color: var(--color-text-muted);">ครั้ง</span></div>
            <?php else: ?>
                <div style="font-size: 0.9rem; color: var(--color-text-muted); margin-top: 4px;">ไม่มีข้อมูล</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top h-index -->
    <div class="glass-panel" style="padding: 20px; display: flex; align-items: flex-start; gap: 15px; border-left: 4px solid #10b981;">
        <div style="background: rgba(16, 185, 129, 0.1); width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #10b981; flex-shrink: 0;">
            <i class="fa-solid fa-award"></i>
        </div>
        <div style="flex: 1;">
            <div style="font-size: 0.8rem; color: var(--color-text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">h-index สูงสุด</div>
            <?php if (!empty($top_hindices)): ?>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($top_hindices as $th): ?>
                        <div>
                            <a href="profile.php?id=<?php echo $th['id']; ?>" style="text-decoration: none; color: inherit; display: block;">
                                <div style="font-weight: 700; font-size: 1rem;"><?php echo htmlspecialchars(($th['title_th'] ? $th['title_th'] . ' ' : '') . $th['first_name_th'] . ' ' . $th['last_name_th']); ?></div>
                                <div style="font-size: 0.75rem; color: var(--color-text-muted);"><?php echo htmlspecialchars($th['department']); ?></div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="font-size: 1.2rem; font-weight: 700; color: #10b981; margin-top: 10px; font-family: var(--font-eng); border-top: 1px solid var(--border-glass); padding-top: 6px;">h-index: <?php echo number_format($max_h); ?></div>
            <?php else: ?>
                <div style="font-size: 0.9rem; color: var(--color-text-muted); margin-top: 4px;">ไม่มีข้อมูล</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Main Layout Grid (Recent Publications & Search) -->
<div class="main-grid animate-fade-in" style="margin-top: 40px; animation-delay: 0.3s;">
    <!-- Sidebar -->
    <div class="sidebar glass-panel">
        <div class="sidebar-title">
            <span>ค้นหางานวิจัย</span>
            <i class="fa-solid fa-search" style="color: var(--color-primary);"></i>
        </div>
        
        <div class="filter-group">
            <label class="filter-label">คำค้นหา</label>
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass search-icon"></i>
                <input type="text" id="search-input" class="search-input" placeholder="พิมพ์ชื่อผลงาน, ผู้แต่ง, วารสาร...">
            </div>
        </div>
        
        <div style="margin-top: 30px; font-size: 0.85rem; color: var(--color-text-muted);">
            <p><i class="fa-solid fa-info-circle"></i> ผลงานในหน้านี้จะถูกอัปเดตแบบเรียลไทม์เมื่อระบบ Admin ทำการซิงค์ข้อมูลสำเร็จ</p>
        </div>
    </div>

    <!-- Main Content (Publications List) -->
    <div>
        <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-book-open" style="color: var(--color-primary);"></i> ผลงานวิจัยตีพิมพ์ล่าสุด
        </h3>
        
        <div class="publications-list">
            <?php if (empty($recent_pubs)): ?>
                <div class="glass-panel" style="padding: 40px; text-align: center; color: var(--color-text-muted);">
                    <i class="fa-regular fa-folder-open" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
                    ยังไม่มีข้อมูลผลงานตีพิมพ์ในระบบ กรุณาเข้าสู่ระบบ Admin เพื่อเริ่มต้นดึงข้อมูล
                </div>
            <?php else: ?>
                <?php foreach ($recent_pubs as $pub): ?>
                    <div class="glass-panel publication-card searchable-item animate-fade-in">
                        <div class="pub-title"><?php echo htmlspecialchars($pub['title']); ?></div>
                        <div class="pub-authors">ผู้แต่ง: <?php echo htmlspecialchars($pub['authors']); ?></div>
                        
                        <?php if (!empty($pub['internal_authors'])): ?>
                            <div style="font-size: 0.8rem; margin-bottom: 8px; color: var(--color-text-muted);">
                                <i class="fa-solid fa-graduation-cap"></i> บุคลากรในคณะฯ: <strong><?php echo htmlspecialchars($pub['internal_authors']); ?></strong>
                            </div>
                        <?php endif; ?>

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
                                <?php echo render_effective_sdg_badges($pub); ?>
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
    </div>
</div>

<!-- Chart rendering script -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Shared chart styling config helper
    function getChartThemeColors() {
        const isLight = document.body.classList.contains('light-mode');
        return {
            text: isLight ? '#475569' : '#94a3b8',
            grid: isLight ? 'rgba(0, 0, 0, 0.05)' : 'rgba(255, 255, 255, 0.05)'
        };
    }

    let yearlyChartInstance = null;
    let citationsChartInstance = null;
    let hindexChartInstance = null;

    window.renderDashboardChart = function() {
        const theme = getChartThemeColors();
        
        // 1. Yearly Chart
        const ctxYear = document.getElementById('yearlyChart');
        if (ctxYear) {
            if (yearlyChartInstance) yearlyChartInstance.destroy();
            yearlyChartInstance = new Chart(ctxYear, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_years); ?>,
                    datasets: [{
                        label: 'จำนวนผลงานตีพิมพ์',
                        data: <?php echo json_encode($chart_counts); ?>,
                        backgroundColor: 'rgba(139, 92, 246, 0.5)',
                        borderColor: '#8b5cf6',
                        borderWidth: 2,
                        borderRadius: 6,
                        hoverBackgroundColor: 'rgba(139, 92, 246, 0.8)',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const elementIndex = elements[0].index;
                            const year = yearlyChartInstance.data.labels[elementIndex];
                            if (year) {
                                window.location.href = 'publications_search.php?year=' + encodeURIComponent(year);
                            }
                        }
                    },
                    onHover: (event, elements) => {
                        event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            grid: { color: theme.grid },
                            ticks: { color: theme.text, font: { family: 'Sarabun' } }
                        },
                        y: {
                            grid: { color: theme.grid },
                            ticks: { color: theme.text, precision: 0 }
                        }
                    }
                }
            });
        }
        
        // 2. Citations Trend (line)
        const ctxCitations = document.getElementById('citationsChart');
        if (ctxCitations) {
            if (citationsChartInstance) citationsChartInstance.destroy();
            citationsChartInstance = new Chart(ctxCitations, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_years); ?>,
                    datasets: [{
                        label: 'การอ้างอิงสะสมรายปี',
                        data: <?php echo json_encode($chart_citations); ?>,
                        borderColor: '#06b6d4',
                        backgroundColor: 'rgba(6, 182, 212, 0.15)',
                        borderWidth: 2,
                        pointBackgroundColor: '#06b6d4',
                        tension: 0.3,
                        fill: true,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const elementIndex = elements[0].index;
                            const year = citationsChartInstance.data.labels[elementIndex];
                            if (year) {
                                window.location.href = 'publications_search.php?year=' + encodeURIComponent(year);
                            }
                        }
                    },
                    onHover: (event, elements) => {
                        event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            grid: { color: theme.grid },
                            ticks: { color: theme.text, font: { family: 'Sarabun' } }
                        },
                        y: {
                            grid: { color: theme.grid },
                            ticks: { color: theme.text, precision: 0 }
                        }
                    }
                }
            });
        }

        // 3. Average H-Index Trend (line)
        const ctxHIndex = document.getElementById('hindexChart');
        if (ctxHIndex) {
            if (hindexChartInstance) hindexChartInstance.destroy();
            hindexChartInstance = new Chart(ctxHIndex, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_years); ?>,
                    datasets: [{
                        label: 'H-Index เฉลี่ยสะสม',
                        data: <?php echo json_encode($chart_avg_hindex); ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.15)',
                        borderWidth: 2,
                        pointBackgroundColor: '#10b981',
                        tension: 0.3,
                        fill: true,
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
                            grid: { color: theme.grid },
                            ticks: { color: theme.text, font: { family: 'Sarabun' } }
                        },
                        y: {
                            grid: { color: theme.grid },
                            ticks: { color: theme.text }
                        }
                    }
                }
            });
        }
    };
    
    // Initial Render
    window.renderDashboardChart();
});
</script>

<?php
include_once __DIR__ . '/includes/footer.php';
?>
