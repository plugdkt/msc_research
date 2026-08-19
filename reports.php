<?php
// reports.php
// Analytical Summary Report Page with dynamic filtering and multi-dimensional tabs

require_once __DIR__ . '/includes/functions.php';

$current_page = 'reports';
$page_title = 'รายงานสรุป';

// Fetch department list for selector
$depts_stmt = $pdo->query("SELECT DISTINCT department FROM researchers WHERE department != '' ORDER BY department ASC");
$depts = $depts_stmt->fetchAll(PDO::FETCH_COLUMN);

// Filter variables
$selected_dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$selected_year = isset($_GET['year']) ? trim($_GET['year']) : '';
$selected_type = isset($_GET['rtype']) ? trim($_GET['rtype']) : '';

$type_clause_named = $selected_type !== '' ? " AND r.researcher_type = :rtype" : "";
$type_clause_q     = $selected_type !== '' ? " AND r.researcher_type = ?" : "";

// Faculty-wide baselines
$total_faculty_researchers = get_total_researchers($pdo);
$total_faculty_pubs = get_total_publications($pdo);

// Query unique years in the database for the dropdown
$years_stmt = $pdo->query("SELECT DISTINCT publish_year FROM publications WHERE publish_year IS NOT NULL AND publish_year > 0 ORDER BY publish_year DESC");
$years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// 1. Fetch Key Metrics based on selection (Dept & Year)
// Researchers count is independent of year
// 1. Fetch base researchers count (Total researchers in the department or faculty)
if ($selected_dept !== '') {
    $stmtDeptRes = $pdo->prepare("SELECT COUNT(*) FROM researchers WHERE department = ?" . ($selected_type !== '' ? " AND researcher_type = ?" : ""));
    $p = [$selected_dept];
    if ($selected_type !== '') $p[] = $selected_type;
    $stmtDeptRes->execute($p);
    $base_researchers = $stmtDeptRes->fetchColumn();
} elseif ($selected_type !== '') {
    $stmtTypeRes = $pdo->prepare("SELECT COUNT(*) FROM researchers WHERE researcher_type = ?");
    $stmtTypeRes->execute([$selected_type]);
    $base_researchers = $stmtTypeRes->fetchColumn();
} else {
    $base_researchers = $total_faculty_researchers;
}

// 2. Fetch base publications count (Total publications in the selected year or faculty overall)
if ($selected_year !== '') {
    $stmtBasePubs = $pdo->prepare("SELECT COUNT(*) FROM publications WHERE publish_year = ?");
    $stmtBasePubs->execute([(int)$selected_year]);
    $base_pubs = $stmtBasePubs->fetchColumn();
} else {
    $base_pubs = $total_faculty_pubs;
}

// 3. Fetch displayed researchers count
if ($selected_year !== '') {
    // Number of researchers in selection (dept/faculty/type) who have publications in the selected year
    $res_conditions = [];
    $res_params = ['year' => (int)$selected_year];
    
    if ($selected_dept !== '') {
        $res_conditions[] = "r.department = :dept";
        $res_params['dept'] = $selected_dept;
    }
    if ($selected_type !== '') {
        $res_conditions[] = "r.researcher_type = :rtype";
        $res_params['rtype'] = $selected_type;
    }
    
    $res_where = !empty($res_conditions) ? " AND " . implode(" AND ", $res_conditions) : "";
    
    $stmtResYear = $pdo->prepare("
        SELECT COUNT(DISTINCT rp.researcher_id) 
        FROM researcher_publications rp
        JOIN researchers r ON rp.researcher_id = r.id
        JOIN publications p ON rp.publication_id = p.id
        WHERE p.publish_year = :year
        $res_where
    ");
    $stmtResYear->execute($res_params);
    $metrics_researchers = $stmtResYear->fetchColumn();
} else {
    // If no year is selected, displayed researchers count is just the base researchers count
    $metrics_researchers = $base_researchers;
}

// Publications count (dependent on selected department and year)
$pub_conditions = [];
$pub_params = [];
if ($selected_dept !== '') {
    $pub_conditions[] = "r.department = :dept";
    $pub_params['dept'] = $selected_dept;
}
if ($selected_type !== '') {
    $pub_conditions[] = "r.researcher_type = :rtype";
    $pub_params['rtype'] = $selected_type;
}
if ($selected_year !== '') {
    $pub_conditions[] = "p.publish_year = :year";
    $pub_params['year'] = (int)$selected_year;
}
$pub_where = !empty($pub_conditions) ? "WHERE " . implode(" AND ", $pub_conditions) : "";

$stmtPubs = $pdo->prepare("
    SELECT COUNT(DISTINCT rp.publication_id) 
    FROM researcher_publications rp 
    JOIN researchers r ON rp.researcher_id = r.id 
    JOIN publications p ON rp.publication_id = p.id
    $pub_where
");
$stmtPubs->execute($pub_params);
$metrics_pubs = $stmtPubs->fetchColumn();

// Total citations count (dependent on selected department and year)
$cit_conditions = [];
$cit_params = [];
if ($selected_dept !== '') {
    $cit_conditions[] = "r.department = :dept";
    $cit_params['dept'] = $selected_dept;
}
if ($selected_type !== '') {
    $cit_conditions[] = "r.researcher_type = :rtype";
    $cit_params['rtype'] = $selected_type;
}
if ($selected_year !== '') {
    $cit_conditions[] = "p.publish_year = :year";
    $cit_params['year'] = (int)$selected_year;
}
$cit_where = !empty($cit_conditions) ? "WHERE " . implode(" AND ", $cit_conditions) : "";

$stmtCitations = $pdo->prepare("
    SELECT COALESCE(SUM(p.citation_count), 0) 
    FROM publications p 
    WHERE p.id IN (
        SELECT DISTINCT rp.publication_id 
        FROM researcher_publications rp 
        JOIN researchers r ON rp.researcher_id = r.id 
        $cit_where
    )
");
$stmtCitations->execute($cit_params);
$metrics_citations = $stmtCitations->fetchColumn();

$avg_citations = $metrics_pubs > 0 ? round($metrics_citations / $metrics_pubs, 2) : 0;

// 2. Fetch Yearly Publications Chart Data (dependent on department selection but independent of selected year to show historical trend)
$yearly_chart_conditions = ["p.publish_year IS NOT NULL", "p.publish_year > 0"];
$yearly_chart_params = [];
if ($selected_dept !== '') {
    $yearly_chart_conditions[] = "r.department = :dept";
    $yearly_chart_params['dept'] = $selected_dept;
}
if ($selected_type !== '') {
    $yearly_chart_conditions[] = "r.researcher_type = :rtype";
    $yearly_chart_params['rtype'] = $selected_type;
}
$yearly_chart_where = "WHERE " . implode(" AND ", $yearly_chart_conditions);

$stmtYearly = $pdo->prepare("
    SELECT p.publish_year as year, COUNT(DISTINCT p.id) as count, COALESCE(SUM(p.citation_count), 0) as citations
    FROM publications p
    LEFT JOIN researcher_publications rp ON p.id = rp.publication_id
    LEFT JOIN researchers r ON rp.researcher_id = r.id
    $yearly_chart_where
    GROUP BY p.publish_year
    ORDER BY p.publish_year ASC
");
$stmtYearly->execute($yearly_chart_params);
$yearly_chart_data = $stmtYearly->fetchAll();

$chart_years = array_column($yearly_chart_data, 'year');
$chart_counts = array_column($yearly_chart_data, 'count');
$chart_citations = array_column($yearly_chart_data, 'citations');

// 3. Fetch Source Pie Chart Data (dependent on department and year)
$stmtSource = $pdo->prepare("
    SELECT p.source, COUNT(DISTINCT p.id) as count
    FROM publications p
    JOIN researcher_publications rp ON p.id = rp.publication_id
    JOIN researchers r ON rp.researcher_id = r.id
    $pub_where
    GROUP BY p.source
");
$stmtSource->execute($pub_params);
$source_chart_data = $stmtSource->fetchAll();

$chart_sources = array_map('strtoupper', array_column($source_chart_data, 'source'));
$chart_source_counts = array_column($source_chart_data, 'count');

// 4. Fetch Author Roles Chart Data (dependent on department and year)
$stmtFirst = $pdo->prepare("
    SELECT COUNT(DISTINCT p.id)
    FROM publications p
    JOIN researcher_publications rp ON p.id = rp.publication_id
    JOIN researchers r ON rp.researcher_id = r.id
    $pub_where
      AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.authors, ',', 1), ';', 1)) LIKE CONCAT('%', LOWER(r.last_name_en), '%')
      AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.authors, ',', 1), ';', 1)) LIKE CONCAT('%', LOWER(SUBSTRING(r.first_name_en, 1, 1)), '%')
");
$stmtFirst->execute($pub_params);
$first_author_count = $stmtFirst->fetchColumn();
$co_author_count = max(0, $metrics_pubs - $first_author_count);

// 5. Summary Table: Department Metrics (filtered by selected year)
$dept_year_clause1 = "";
$dept_year_clause2 = "";
$dept_params = [];
if ($selected_year !== '') {
    $dept_year_clause1 = " AND p2.publish_year = :year1";
    $dept_year_clause2 = " AND p2.publish_year = :year2";
    $dept_params['year1'] = (int)$selected_year;
    $dept_params['year2'] = (int)$selected_year;
}

$dept_summary_sql = "
    SELECT r.department, 
           COUNT(DISTINCT r.id) as researcher_count,
           (
               SELECT COUNT(DISTINCT p2.id) 
               FROM researcher_publications rp2 
               JOIN researchers r2 ON rp2.researcher_id = r2.id 
               JOIN publications p2 ON rp2.publication_id = p2.id
               WHERE r2.department = r.department $dept_year_clause1
           ) as pub_count,
           (
               SELECT COALESCE(SUM(p2.citation_count), 0) 
               FROM publications p2 
               JOIN researcher_publications rp2 ON p2.id = rp2.publication_id
               JOIN researchers r2 ON rp2.researcher_id = r2.id
               WHERE r2.department = r.department $dept_year_clause2
           ) as total_citations
    FROM researchers r
    WHERE r.department != ''
    GROUP BY r.department
    ORDER BY pub_count DESC
";
$stmtDeptSum = $pdo->prepare($dept_summary_sql);
$stmtDeptSum->execute($dept_params);
$dept_summary = $stmtDeptSum->fetchAll();

$chart_depts = array_column($dept_summary, 'department');
$chart_dept_pubs = array_column($dept_summary, 'pub_count');
$chart_dept_citations = array_column($dept_summary, 'total_citations');

// 6. Summary Table: Yearly Trend (derived from the same filtered yearly chart data but reversed for descending table display)
$yearly_summary = array_reverse($yearly_chart_data);
// Remap chart columns to match expected summary keys: 'count' -> 'pub_count', 'citations' -> 'total_citations'
foreach ($yearly_summary as &$ys) {
    $ys['pub_count'] = $ys['count'];
    $ys['total_citations'] = $ys['citations'];
}
unset($ys);

// 7. Summary Table: Researchers Leaderboard (dependent on department and year)
$leaderboard_conditions = [];
$leader_params = [];
if ($selected_dept !== '') {
    $leaderboard_conditions[] = "r.department = :dept";
    $leader_params['dept'] = $selected_dept;
}
if ($selected_type !== '') {
    $leaderboard_conditions[] = "r.researcher_type = :rtype";
    $leader_params['rtype'] = $selected_type;
}
$leaderboard_where = !empty($leaderboard_conditions) ? "WHERE " . implode(" AND ", $leaderboard_conditions) : "";

$leaderboard_sql = "
    SELECT r.id, r.title_th, r.first_name_th, r.last_name_th, r.department,
           r.h_index_peak,
           COUNT(DISTINCT p.id) as pub_count,
           COALESCE(SUM(p.citation_count), 0) as total_citations
    FROM researchers r
    LEFT JOIN researcher_publications rp ON r.id = rp.researcher_id
    LEFT JOIN publications p ON rp.publication_id = p.id " . ($selected_year !== '' ? "AND p.publish_year = :year" : "") . "
    $leaderboard_where
    GROUP BY r.id
    ORDER BY total_citations DESC, pub_count DESC
    LIMIT 50
";
if ($selected_year !== '') $leader_params['year'] = (int)$selected_year;
$stmtLeaderboard = $pdo->prepare($leaderboard_sql);
$stmtLeaderboard->execute($leader_params);
$leaderboard = $stmtLeaderboard->fetchAll();

// Calculate active researchers (dependent on department and year)
$active_conditions = [];
$active_params = [];
if ($selected_dept !== '') {
    $active_conditions[] = "r.department = :dept";
    $active_params['dept'] = $selected_dept;
}
if ($selected_type !== '') {
    $active_conditions[] = "r.researcher_type = :rtype";
    $active_params['rtype'] = $selected_type;
}
if ($selected_year !== '') {
    $active_conditions[] = "p.publish_year = :year";
    $active_params['year'] = (int)$selected_year;
}
$active_where = !empty($active_conditions) ? "WHERE " . implode(" AND ", $active_conditions) : "";

$stmtActive = $pdo->prepare("
    SELECT COUNT(DISTINCT r.id) 
    FROM researchers r
    JOIN researcher_publications rp ON r.id = rp.researcher_id
    JOIN publications p ON rp.publication_id = p.id
    $active_where
");
$stmtActive->execute($active_params);
$active_researchers_count = $stmtActive->fetchColumn() ?: 0;
$active_percentage = $base_researchers > 0 ? round(($active_researchers_count / $base_researchers) * 100, 1) : 0;
$avg_pubs_per_researcher = $base_researchers > 0 ? round($metrics_pubs / $base_researchers, 2) : 0.00;

// Percentage calculations for selected metrics vs base scope
$researcher_percentage = $base_researchers > 0 ? round(($metrics_researchers / $base_researchers) * 100, 1) : 0;
$pubs_percentage = $base_pubs > 0 ? round(($metrics_pubs / $base_pubs) * 100, 1) : 0;
$pubs_per_researcher_percent = $base_researchers > 0 ? round(($metrics_pubs / $base_researchers) * 100, 1) : 0;

$res_scope_label = $selected_dept !== '' ? 'ของสาขา' : 'ของคณะ';
$pubs_scope_label = $selected_year !== '' ? 'ของปีนี้' : 'ของคณะสะสม';

/**
 * Calculate filtered h-index for a researcher based on selected year
 */
function calculate_researcher_h_index_filtered($pdo, $researcher_id, $selected_year) {
    try {
        $sql = "
            SELECT p.citation_count 
            FROM `publications` p
            JOIN `researcher_publications` rp ON p.id = rp.publication_id
            WHERE rp.researcher_id = :researcher_id
        ";
        $params = ['researcher_id' => $researcher_id];
        if ($selected_year !== '') {
            $sql .= " AND p.publish_year <= :year";
            $params['year'] = (int)$selected_year;
        }
        $sql .= " ORDER BY p.citation_count DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $citations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $h_index = 0;
        foreach ($citations as $index => $citation) {
            $rank = $index + 1;
            if ($citation >= $rank) {
                $h_index = $rank;
            } else {
                break;
            }
        }
        return $h_index;
    } catch (PDOException $e) {
        return 0;
    }
}

// Fetch all publications matching the selected filters with their quartile details
$quartile_pubs_params = [];
$quartile_pub_conditions = [];
if ($selected_dept !== '') {
    $quartile_pub_conditions[] = "r.department = :dept";
    $quartile_pubs_params['dept'] = $selected_dept;
}
if ($selected_type !== '') {
    $quartile_pub_conditions[] = "r.researcher_type = :rtype";
    $quartile_pubs_params['rtype'] = $selected_type;
}
if ($selected_year !== '') {
    $quartile_pub_conditions[] = "p.publish_year = :year";
    $quartile_pubs_params['year'] = (int)$selected_year;
}
$quartile_where = !empty($quartile_pub_conditions) ? "WHERE " . implode(" AND ", $quartile_pub_conditions) : "";

$stmtAllPubs = $pdo->prepare("
    SELECT p.id, p.title, p.authors, p.journal_name, p.publish_year, p.doi, p.url, p.quartile, p.source, p.citation_count, p.countries, p.funding_sponsor, p.funding_no, p.funding_amount, p.sdg_primary, p.sdg_secondary, p.sdg_rationale,
           GROUP_CONCAT(DISTINCT CONCAT(r2.title_th, ' ', r2.first_name_th, ' ', r2.last_name_th, '||', r2.first_name_en, '||', r2.last_name_en) SEPARATOR '|||') AS linked_researchers
    FROM researcher_publications rp
    JOIN researchers r ON rp.researcher_id = r.id
    JOIN publications p ON rp.publication_id = p.id
    LEFT JOIN researcher_publications rp2 ON p.id = rp2.publication_id
    LEFT JOIN researchers r2 ON rp2.researcher_id = r2.id
    $quartile_where
    GROUP BY p.id
    ORDER BY p.publish_year DESC, p.title ASC
");
$stmtAllPubs->execute($quartile_pubs_params);
$filtered_publications = $stmtAllPubs->fetchAll();

// Group publications by quartile
$quartile_groups = [
    'Q1' => [],
    'Q2' => [],
    'Q3' => [],
    'Q4' => [],
    'N/A' => []
];

foreach ($filtered_publications as $pub) {
    $q = strtoupper(trim($pub['quartile'] ?? ''));
    if (in_array($q, ['Q1', 'Q2', 'Q3', 'Q4'])) {
        $quartile_groups[$q][] = $pub;
    } else {
        $quartile_groups['N/A'][] = $pub;
    }
}

// Group publications by country (excluding Thailand)
$country_groups = [];
foreach ($filtered_publications as $pub) {
    if (empty($pub['countries'])) continue;
    $parts = preg_split('/\s*[,;]\s*/', $pub['countries']);
    foreach ($parts as $country) {
        $country = trim($country);
        if (empty($country)) continue;
        
        $country = ucwords(strtolower($country));
        
        if ($country === 'Thailand') continue;
        
        if (!isset($country_groups[$country])) {
            $country_groups[$country] = [];
        }
        $country_groups[$country][] = $pub;
    }
}

// Sort countries by count descending
uksort($country_groups, function($a, $b) use ($country_groups) {
    return count($country_groups[$b]) <=> count($country_groups[$a]);
});

// Group publications by funding sponsor
$funding_groups = [];
$total_funding_amount = 0;
foreach ($filtered_publications as $pub) {
    if (empty($pub['funding_sponsor'])) continue;
    
    if (!empty($pub['funding_amount'])) {
        $total_funding_amount += $pub['funding_amount'];
    }
    
    $parts = preg_split('/\s*;\s*/', $pub['funding_sponsor']);
    foreach ($parts as $sponsor) {
        $sponsor = trim($sponsor);
        if (empty($sponsor)) continue;
        
        // Clean and normalize
        $sponsor = ucwords(strtolower($sponsor));
        
        if (!isset($funding_groups[$sponsor])) {
            $funding_groups[$sponsor] = [
                'publications' => [],
                'total_amount' => 0
            ];
        }
        $funding_groups[$sponsor]['publications'][] = $pub;
        if (!empty($pub['funding_amount'])) {
            $sponsor_count = count($parts);
            $funding_groups[$sponsor]['total_amount'] += ($pub['funding_amount'] / $sponsor_count);
        }
    }
}

// Sort funding groups by count descending
uksort($funding_groups, function($a, $b) use ($funding_groups) {
    return count($funding_groups[$b]['publications']) <=> count($funding_groups[$a]['publications']);
});

// Group publications by SDG (always show SDG 1 - SDG 17, and count in both primary and secondary if a paper has both)
$sdg_groups = [];
for ($i = 1; $i <= 17; $i++) {
    $sdg_groups["SDG " . $i] = [];
}

foreach ($filtered_publications as $pub) {
    $sdgs = [];
    if (!empty($pub['sdg_primary'])) {
        $sdgs[] = trim($pub['sdg_primary']);
    }
    if (!empty($pub['sdg_secondary'])) {
        $sdgs[] = trim($pub['sdg_secondary']);
    }
    
    $sdgs = array_unique($sdgs);
    foreach ($sdgs as $sdg) {
        $num = (int)preg_replace('/[^0-9]/', '', $sdg);
        if ($num >= 1 && $num <= 17) {
            $sdg_key = "SDG " . $num;
            $sdg_groups[$sdg_key][] = $pub;
        }
    }
}

include_once __DIR__ . '/includes/header.php';
?>

<style>
    .report-tab-btn {
        flex: 1; 
        padding: 12px 18px; 
        border-radius: 8px; 
        border: none; 
        background: transparent; 
        color: var(--color-text-muted); 
        font-size: 0.9rem; 
        font-weight: 600; 
        cursor: pointer; 
        transition: all 0.2s; 
        display: flex; 
        align-items: center; 
        justify-content: center; 
        gap: 6px;
    }
    .report-tab-btn:hover {
        background: rgba(255, 255, 255, 0.03);
        color: var(--color-text-main);
    }
    body.light-mode .report-tab-btn:hover {
        background: rgba(0, 0, 0, 0.03);
    }
    .report-tab-btn.active {
        background: linear-gradient(135deg, var(--color-primary), var(--color-secondary)) !important;
        color: white !important;
        box-shadow: 0 4px 15px rgba(var(--color-primary-rgb), 0.2);
    }
    .report-section {
        animation: fadeIn 0.4s ease;
    }
    .quartile-card {
        transform: translateY(0);
        opacity: 0.75;
    }
    .quartile-card:hover {
        transform: translateY(-4px);
        opacity: 1;
    }
    .quartile-card.active {
        background: rgba(255, 255, 255, 0.08) !important;
        border-color: rgba(255, 255, 255, 0.25) !important;
        box-shadow: var(--shadow-glass-glow) !important;
        opacity: 1 !important;
        transform: scale(1.03) !important;
    }
    body.light-mode .quartile-card.active {
        background: rgba(0, 0, 0, 0.04) !important;
        border-color: rgba(0, 0, 0, 0.15) !important;
    .country-card {
        transform: translateY(0);
        opacity: 0.75;
        transition: var(--transition-smooth);
    }
    .country-card:hover {
        transform: translateY(-3px);
        opacity: 1;
    }
    .country-card.active {
        background: rgba(255, 255, 255, 0.08) !important;
        border-color: var(--color-accent) !important;
        box-shadow: var(--shadow-glass-glow) !important;
        opacity: 1 !important;
    }
    body.light-mode .country-card.active {
        background: rgba(0, 0, 0, 0.04) !important;
        border-color: var(--color-accent) !important;
    }
    .funding-card {
        transform: translateY(0);
        opacity: 0.75;
        transition: var(--transition-smooth);
    }
    .funding-card:hover {
        transform: translateY(-3px);
        opacity: 1;
    }
    .funding-card.active {
        background: rgba(255, 255, 255, 0.08) !important;
        border-color: #10b981 !important;
        box-shadow: var(--shadow-glass-glow) !important;
        opacity: 1 !important;
    }
    body.light-mode .funding-card.active {
        background: rgba(0, 0, 0, 0.04) !important;
        border-color: #10b981 !important;
    }
</style>

<!-- Reports Header Hero -->
<div class="hero glass-panel animate-fade-in" style="padding: 40px 20px; margin-bottom: 30px;">
    <h2>รายงานสรุป <br/>(Executive Summary & Analytical Reports)</h2>
    <p>วิเคราะห์ข้อมูลการตีพิมพ์งานวิจัย สัดส่วนการอ้างอิง และตัวชี้วัดประสิทธิภาพของนักวิจัยระดับบุคคลและระดับภาควิชา</p>
   
    
    <!-- Filter Form (Department & Year) -->
    <form method="GET" action="reports.php" style="margin-top: 20px; display: inline-flex; align-items: center; gap: 15px; background: rgba(255,255,255,0.05); padding: 8px 20px; border-radius: 30px; border: 1px solid var(--border-glass); flex-wrap: wrap;">
        <!-- Department Selector -->
        <div style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 0.85rem; color: var(--color-text-muted); font-weight: 500;">
                <i class="fa-solid fa-building-columns" style="color: var(--color-primary);"></i> ภาควิชา:
            </span>
            <select name="dept" onchange="this.form.submit()" style="background: transparent; border: none; color: var(--color-text-main); font-size: 0.85rem; font-weight: 600; outline: none; cursor: pointer;">
                <option value="" style="background: #0f172a;">ทั้งหมด (ภาพรวมคณะ)</option>
                <?php foreach ($depts as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $selected_dept === $dept ? 'selected' : ''; ?> style="background: #0f172a;">
                        <?php echo htmlspecialchars($dept); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <span style="color: var(--border-glass);">|</span>

        <!-- Year Selector -->
        <div style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 0.85rem; color: var(--color-text-muted); font-weight: 500;">
                <i class="fa-solid fa-calendar" style="color: var(--color-accent);"></i> ปีที่ตีพิมพ์:
            </span>
            <select name="year" onchange="this.form.submit()" style="background: transparent; border: none; color: var(--color-text-main); font-size: 0.85rem; font-weight: 600; outline: none; cursor: pointer; min-width: 90px; padding: 2px 8px;">
                <option value="" style="background: #0f172a;">ทุกปี</option>
                <?php foreach ($years as $yr): ?>
                    <option value="<?php echo $yr; ?>" <?php echo $selected_year === (string)$yr ? 'selected' : ''; ?> style="background: #0f172a;">
                        <?php echo $yr; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <span style="color: var(--border-glass);">|</span>

        <!-- Researcher Type Selector -->
        <div style="display: flex; align-items: center; gap: 6px;">
            <span style="font-size: 0.85rem; color: var(--color-text-muted); font-weight: 500;">
                <i class="fa-solid fa-user-tag" style="color: #ec4899;"></i> ประเภท:
            </span>
            <select name="rtype" onchange="this.form.submit()" style="background: transparent; border: none; color: var(--color-text-main); font-size: 0.85rem; font-weight: 600; outline: none; cursor: pointer;">
                <option value="" style="background: #0f172a;">ทั้งหมด</option>
                <option value="สายวิชาการ"  <?php echo $selected_type === 'สายวิชาการ'  ? 'selected' : ''; ?> style="background: #0f172a;">สายวิชาการ</option>
                <option value="สายสนับสนุน" <?php echo $selected_type === 'สายสนับสนุน' ? 'selected' : ''; ?> style="background: #0f172a;">สายสนับสนุน</option>
            </select>
        </div>
    </form>
</div>

<!-- Key Performance Metrics Grid -->
<div class="stats-grid animate-fade-in" style="animation-delay: 0.1s;">
    <div class="stat-card glass-panel" style="border-top: 3px solid var(--color-primary);">
        <div class="stat-number" style="color: var(--color-primary);">
            <?php echo number_format($metrics_researchers); ?> <span style="font-size: 1.2rem; font-weight: 400; opacity: 0.5;">/</span> <?php echo number_format($base_researchers); ?>
        </div>
        <div class="stat-label">นักวิจัยที่แสดง <br/>(<?php echo $researcher_percentage; ?>% <?php echo $res_scope_label; ?>)</div>
    </div>
    <div class="stat-card glass-panel" style="border-top: 3px solid var(--color-secondary);">
        <div class="stat-number" style="color: var(--color-secondary);">
            <?php echo number_format($metrics_pubs); ?> <span style="font-size: 1.2rem; font-weight: 400; opacity: 0.5;">/</span> <?php echo number_format($base_pubs); ?>
        </div>
        <div class="stat-label">จำนวนผลงานตีพิมพ์ <br/>(<?php echo $pubs_percentage; ?>% | งานวิจัยรวมของคณะ)</div>
    </div>
    <div class="stat-card glass-panel" style="border-top: 3px solid var(--color-accent);">
        <div class="stat-number" style="color: var(--color-accent);"><?php echo number_format($metrics_citations); ?></div>
        <div class="stat-label">การอ้างอิงทั้งหมด (Citations)</div>
    </div>
    <div class="stat-card glass-panel" style="border-top: 3px solid #f59e0b;">
        <div class="stat-number" style="color: #f59e0b;"><?php echo number_format($avg_pubs_per_researcher, 2); ?></div>
        <div class="stat-label">เฉลี่ยผลงานต่อคน<br/> (Active: <?php echo $active_percentage; ?>%)</div>
    </div>
</div>

<!-- Report Dimension Tab Selector -->
<div class="animate-fade-in" style="display: flex; gap: 10px; margin: 30px 0; flex-wrap: wrap; justify-content: center; background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 12px; padding: 6px; animation-delay: 0.12s;">
    <button id="tab-overview" class="report-tab-btn active" onclick="switchReportTab('overview')">
        <i class="fa-solid fa-gauge"></i> ภาพรวมแนวโน้ม
    </button>
    <button id="tab-department" class="report-tab-btn" onclick="switchReportTab('department')">
        <i class="fa-solid fa-building"></i> สรุปแยกภาควิชา
    </button>

    <button id="tab-quartiles" class="report-tab-btn" onclick="switchReportTab('quartiles')">
        <i class="fa-solid fa-chart-bar"></i> สรุปตาม Quartiles
    </button>
    <button id="tab-countries" class="report-tab-btn" onclick="switchReportTab('countries')">
        <i class="fa-solid fa-earth-americas"></i> ความร่วมมือระหว่างประเทศ
    </button>
    <button id="tab-funding" class="report-tab-btn" onclick="switchReportTab('funding')">
        <i class="fa-solid fa-hand-holding-dollar"></i> แหล่งทุนวิจัย
    </button>
    <button id="tab-sdgs" class="report-tab-btn" onclick="switchReportTab('sdgs')">
        <i class="fa-solid fa-leaf"></i> สถิติตาม SDGs
    </button>
    <button id="tab-leaderboard" class="report-tab-btn" onclick="switchReportTab('leaderboard')">
        <i class="fa-solid fa-trophy"></i> จัดอันดับนักวิจัย
    </button>
</div>

<!-- 1. SECTION: OVERVIEW -->
<div id="section-overview" class="report-section">
    <div class="glass-panel" style="padding: 30px; margin-bottom: 30px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-chart-line" style="color: var(--color-primary);"></i> สรุปจำนวนการเผยแพร่ & Citations สะสมรายปี
        </h3>
        <div style="position: relative; height: 350px; width: 100%;">
            <canvas id="yearlyMixedChart"></canvas>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 30px; margin-bottom: 30px;">
        <!-- Source Chart -->
        <div class="glass-panel" style="padding: 24px;">
            <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-chart-pie" style="color: var(--color-primary);"></i> สัดส่วนฐานข้อมูลแหล่งข้อมูลเผยแพร่ (Sources)
            </h3>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="sourceChart"></canvas>
            </div>
        </div>

        <!-- Role Chart -->
        <div class="glass-panel" style="padding: 24px;">
            <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-user-tag" style="color: var(--color-accent);"></i> บทบาทผู้เขียนผลงานตีพิมพ์สะสม (Author Roles)
            </h3>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="roleChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Publication Trends & Cumulative Stats Table (Moved from Yearly tab) -->
    <div class="glass-panel" style="padding: 30px; margin-top: 30px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px;">
            <i class="fa-solid fa-calendar-check" style="color: var(--color-primary);"></i> แนวโน้มสิ่งตีพิมพ์และสถิติสะสมรายปี
        </h3>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.95rem;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted); font-weight: 600;">
                        <th style="padding: 12px 10px; text-align: center;">ปีที่เผยแพร่ (ค.ศ.)</th>
                        <th style="padding: 12px 10px; text-align: center;">จำนวนผลงานตีพิมพ์</th>
                        <th style="padding: 12px 10px; text-align: center;">Citations รวม</th>
                        <th style="padding: 12px 10px; text-align: center;">เฉลี่ยผลงานต่อคน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($yearly_summary as $ys): 
                        $ys_avg = $base_researchers > 0 ? round($ys['pub_count'] / $base_researchers, 2) : 0.00;
                    ?>
                        <tr style="border-bottom: 1px solid var(--border-glass); transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 14px 10px; text-align: center; font-family: var(--font-eng); font-weight: 700;">
                                <a href="publications_search.php?year=<?php echo $ys['year']; ?>" style="text-decoration: none; color: inherit; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                    <?php echo htmlspecialchars($ys['year']); ?>
                                </a>
                            </td>
                            <td style="padding: 14px 10px; text-align: center; font-family: var(--font-eng); font-weight: 600; color: var(--color-primary);"><?php echo number_format($ys['pub_count']); ?></td>
                            <td style="padding: 14px 10px; text-align: center; font-family: var(--font-eng); font-weight: 600; color: var(--color-accent);"><?php echo number_format($ys['total_citations']); ?></td>
                            <td style="padding: 14px 10px; text-align: center; font-family: var(--font-eng); color: #10b981; font-weight: 600;"><?php echo number_format($ys_avg, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 2. SECTION: DEPARTMENT -->
<div id="section-department" class="report-section" style="display: none;">
    <!-- Separated Comparison Charts -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;" class="main-grid">
        <!-- Publications Chart -->
        <div class="glass-panel" style="padding: 24px;">
            <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-book" style="color: var(--color-primary);"></i> จำนวนผลงานตีพิมพ์แยกตามภาควิชา
            </h3>
            <div style="position: relative; height: 320px; width: 100%;">
                <canvas id="deptPubsChart"></canvas>
            </div>
        </div>
        <!-- Citations Chart -->
        <div class="glass-panel" style="padding: 24px;">
            <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-quote-right" style="color: var(--color-accent);"></i> Citations สะสมแยกตามภาควิชา
            </h3>
            <div style="position: relative; height: 320px; width: 100%;">
                <canvas id="deptCitationsChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Table -->
        <div class="glass-panel" style="padding: 30px;">
            <h3 style="margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px;">
                <i class="fa-solid fa-table-list" style="color: var(--color-accent);"></i> ตารางสถิติแยกตามภาควิชา
            </h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted);">
                            <th style="padding: 10px 5px;">ภาควิชา</th>
                            <th style="padding: 10px 5px; text-align: center;">นักวิจัย (คน)</th>
                            <th style="padding: 10px 5px; text-align: center;">ผลงานตีพิมพ์</th>
                            <th style="padding: 10px 5px; text-align: center;">เฉลี่ยต่อคน (% คณะ)</th>
                            <th style="padding: 10px 5px; text-align: center;">Citations รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dept_summary as $ds): ?>
                            <tr style="border-bottom: 1px solid var(--border-glass); transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                                <td style="padding: 12px 5px; font-weight: 600;">
                                    <a href="?dept=<?php echo urlencode($ds['department']); ?>" style="text-decoration: none; color: inherit; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                        <?php echo htmlspecialchars($ds['department']); ?>
                                    </a>
                                </td>
                                <td style="padding: 12px 5px; text-align: center; font-family: var(--font-eng);"><?php echo number_format($ds['researcher_count']); ?></td>
                                <td style="padding: 12px 5px; text-align: center; font-family: var(--font-eng); font-weight: 600; color: var(--color-primary);"><?php echo number_format($ds['pub_count']); ?></td>
                                <td style="padding: 12px 5px; text-align: center; font-family: var(--font-eng); font-size: 0.85rem;">
                                    <?php 
                                    $avg_per_person = $ds['researcher_count'] > 0 ? round($ds['pub_count'] / $ds['researcher_count'], 2) : 0.00;
                                    $total_fac_pubs = get_total_publications($pdo);
                                    $percent_of_total = $total_fac_pubs > 0 ? round(($ds['pub_count'] / $total_fac_pubs) * 100, 1) : 0;
                                    echo "<strong>" . number_format($avg_per_person, 2) . "</strong> เรื่อง <span style='font-size: 0.75rem; color: var(--color-text-muted); font-weight: 500;'>(" . $percent_of_total . "%)</span>";
                                    ?>
                                </td>
                                <td style="padding: 12px 5px; text-align: center; font-family: var(--font-eng); font-weight: 600; color: var(--color-accent);"><?php echo number_format($ds['total_citations']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>



<!-- 5. SECTION: LEADERBOARD -->
<div id="section-leaderboard" class="report-section" style="display: none;">
    <div class="glass-panel" style="padding: 30px; margin-bottom: 30px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
            <h3 style="margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-trophy" style="color: #fbbf24;"></i> จัดอันดับประสิทธิภาพสะสมรายบุคคล (Top Researchers)
            </h3>
            <!-- Client-side Leaderboard sort and search -->
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="font-size: 0.85rem; color: var(--color-text-muted); font-weight: 500;">
                        <i class="fa-solid fa-sort" style="color: var(--color-primary);"></i> เรียงตาม:
                    </span>
                    <select id="leaderboard-sort" onchange="sortLeaderboard(this.value)" style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); border-radius: 8px; color: var(--color-text-main); font-size: 0.85rem; font-weight: 600; padding: 6px 12px; outline: none; cursor: pointer; transition: all 0.2s;" onfocus="this.style.borderColor='var(--color-primary)'" onblur="this.style.borderColor='var(--border-glass)'">
                        <option value="citations" style="background: #0f172a;">Citations รวม</option>
                        <option value="pubs" style="background: #0f172a;">ผลงานตีพิมพ์</option>
                        <option value="hindex" style="background: #0f172a;">h-index</option>
                    </select>
                </div>
                <div style="position: relative; width: 220px;">
                    <input type="text" id="leaderboard-search" class="search-input" placeholder="พิมพ์ชื่อเพื่อค้นหาอันดับ..." style="padding-left: 35px; width: 100%; font-size: 0.85rem;">
                    <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.8rem;"></i>
                </div>
            </div>
        </div>
        
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted);">
                        <th style="padding: 12px 10px; width: 60px; text-align: center;">อันดับ</th>
                        <th style="padding: 12px 10px;">ชื่อ - นามสกุลนักวิจัย</th>
                        <th style="padding: 12px 10px;">ภาควิชา</th>
                        <th style="padding: 12px 10px; text-align: center;">ผลงานตีพิมพ์</th>
                        <th style="padding: 12px 10px; text-align: center;">Citations รวม</th>
                        <th style="padding: 12px 10px; text-align: center;">h-index</th>
                    </tr>
                </thead>
                <tbody id="leaderboard-body">
                    <?php 
                    $rank = 1;
                    foreach ($leaderboard as $lb): 
                        // ถ้าไม่ได้ filter ปี → ใช้ค่า peak สะสม (ไม่ถดถอย)
                        // ถ้า filter ปีใดปีหนึ่ง → คำนวณ h-index เฉพาะปีนั้น
                        if ($selected_year === '') {
                            $h_index = (int)($lb['h_index_peak'] ?? 0);
                        } else {
                            $h_index = calculate_researcher_h_index_filtered($pdo, $lb['id'], $selected_year);
                        }
                    ?>
                        <tr style="border-bottom: 1px solid var(--border-glass); transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                            <td class="rank-cell" style="padding: 14px 10px; text-align: center; font-weight: 700; font-family: var(--font-eng);">
                                <?php if ($rank === 1): ?>
                                    <span style="background: rgba(251,191,36,0.15); color: #fbbf24; padding: 4px 8px; border-radius: 4px;">1</span>
                                <?php elseif ($rank === 2): ?>
                                    <span style="background: rgba(148,163,184,0.15); color: #cbd5e1; padding: 4px 8px; border-radius: 4px;">2</span>
                                <?php elseif ($rank === 3): ?>
                                    <span style="background: rgba(180,83,9,0.15); color: #d97706; padding: 4px 8px; border-radius: 4px;">3</span>
                                <?php else: ?>
                                    <?php echo $rank; ?>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 14px 10px; font-weight: 700;">
                                <a href="profile.php?id=<?php echo $lb['id']; ?>" style="text-decoration: none; color: inherit; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                    <?php echo htmlspecialchars(($lb['title_th'] ? $lb['title_th'] . ' ' : '') . $lb['first_name_th'] . ' ' . $lb['last_name_th']); ?>
                                </a>
                            </td>
                            <td style="padding: 14px 10px; color: var(--color-text-muted);"><?php echo htmlspecialchars($lb['department']); ?></td>
                            <td class="pubs-cell" style="padding: 14px 10px; text-align: center; font-family: var(--font-eng); font-weight: 600; color: var(--color-primary);"><?php echo number_format($lb['pub_count']); ?></td>
                            <td class="citations-cell" style="padding: 14px 10px; text-align: center; font-family: var(--font-eng); font-weight: 600; color: var(--color-accent);"><?php echo number_format($lb['total_citations']); ?></td>
                            <td class="hindex-cell" style="padding: 14px 10px; text-align: center; font-family: var(--font-eng); font-weight: 700; color: #10b981;"><?php echo $h_index; ?></td>
                        </tr>
                    <?php 
                        $rank++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 6. SECTION: QUARTILES -->
<div id="section-quartiles" class="report-section" style="display: none;">
    <!-- Summary Cards Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <!-- Q1 Card -->
        <div class="glass-panel quartile-card active" onclick="selectQuartileFilter('Q1')" id="q-card-Q1" style="padding: 20px; text-align: center; cursor: pointer; border-bottom: 4px solid #10b981; transition: var(--transition-smooth); position: relative;">
            <div style="font-size: 2.2rem; font-weight: 700; color: #10b981; font-family: var(--font-eng); line-height: 1.2;">
                <?php echo count($quartile_groups['Q1']); ?>
            </div>
            <div style="font-size: 0.9rem; font-weight: 600; margin: 8px 0 4px 0; color: var(--color-text-main);">Scopus Q1</div>
            <div style="font-size: 0.72rem; color: var(--color-text-muted);">
                คิดเป็น <?php echo $metrics_pubs > 0 ? round(count($quartile_groups['Q1']) / $metrics_pubs * 100, 1) : 0; ?>% ของผลงานทั้งหมด
            </div>
        </div>
        <!-- Q2 Card -->
        <div class="glass-panel quartile-card" onclick="selectQuartileFilter('Q2')" id="q-card-Q2" style="padding: 20px; text-align: center; cursor: pointer; border-bottom: 4px solid #3b82f6; transition: var(--transition-smooth); position: relative;">
            <div style="font-size: 2.2rem; font-weight: 700; color: #3b82f6; font-family: var(--font-eng); line-height: 1.2;">
                <?php echo count($quartile_groups['Q2']); ?>
            </div>
            <div style="font-size: 0.9rem; font-weight: 600; margin: 8px 0 4px 0; color: var(--color-text-main);">Scopus Q2</div>
            <div style="font-size: 0.72rem; color: var(--color-text-muted);">
                คิดเป็น <?php echo $metrics_pubs > 0 ? round(count($quartile_groups['Q2']) / $metrics_pubs * 100, 1) : 0; ?>% ของผลงานทั้งหมด
            </div>
        </div>
        <!-- Q3 Card -->
        <div class="glass-panel quartile-card" onclick="selectQuartileFilter('Q3')" id="q-card-Q3" style="padding: 20px; text-align: center; cursor: pointer; border-bottom: 4px solid #f59e0b; transition: var(--transition-smooth); position: relative;">
            <div style="font-size: 2.2rem; font-weight: 700; color: #f59e0b; font-family: var(--font-eng); line-height: 1.2;">
                <?php echo count($quartile_groups['Q3']); ?>
            </div>
            <div style="font-size: 0.9rem; font-weight: 600; margin: 8px 0 4px 0; color: var(--color-text-main);">Scopus Q3</div>
            <div style="font-size: 0.72rem; color: var(--color-text-muted);">
                คิดเป็น <?php echo $metrics_pubs > 0 ? round(count($quartile_groups['Q3']) / $metrics_pubs * 100, 1) : 0; ?>% ของผลงานทั้งหมด
            </div>
        </div>
        <!-- Q4 Card -->
        <div class="glass-panel quartile-card" onclick="selectQuartileFilter('Q4')" id="q-card-Q4" style="padding: 20px; text-align: center; cursor: pointer; border-bottom: 4px solid #ef4444; transition: var(--transition-smooth); position: relative;">
            <div style="font-size: 2.2rem; font-weight: 700; color: #ef4444; font-family: var(--font-eng); line-height: 1.2;">
                <?php echo count($quartile_groups['Q4']); ?>
            </div>
            <div style="font-size: 0.9rem; font-weight: 600; margin: 8px 0 4px 0; color: var(--color-text-main);">Scopus Q4</div>
            <div style="font-size: 0.72rem; color: var(--color-text-muted);">
                คิดเป็น <?php echo $metrics_pubs > 0 ? round(count($quartile_groups['Q4']) / $metrics_pubs * 100, 1) : 0; ?>% ของผลงานทั้งหมด
            </div>
        </div>
        <!-- N/A Card -->
        <div class="glass-panel quartile-card" onclick="selectQuartileFilter('NA')" id="q-card-NA" style="padding: 20px; text-align: center; cursor: pointer; border-bottom: 4px solid var(--color-text-muted); transition: var(--transition-smooth); position: relative;">
            <div style="font-size: 2.2rem; font-weight: 700; color: var(--color-text-muted); font-family: var(--font-eng); line-height: 1.2;">
                <?php echo count($quartile_groups['N/A']); ?>
            </div>
            <div style="font-size: 0.9rem; font-weight: 600; margin: 8px 0 4px 0; color: var(--color-text-main);">Unclassified / อื่นๆ</div>
            <div style="font-size: 0.72rem; color: var(--color-text-muted);">
                คิดเป็น <?php echo $metrics_pubs > 0 ? round(count($quartile_groups['N/A']) / $metrics_pubs * 100, 1) : 0; ?>% ของผลงานทั้งหมด
            </div>
        </div>
    </div>

    <!-- Publications List panel -->
    <div class="glass-panel" style="padding: 25px;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
            <h3 style="margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-list-check" style="color: var(--color-primary);"></i>
                <span>รายชื่อผลงานตีพิมพ์ในกลุ่ม </span>
                <span id="active-quartile-title" style="color: #10b981;">Scopus Q1</span>
            </h3>
            
            <div style="position: relative; width: 240px;">
                <input type="text" id="quartile-pub-search" class="search-input" placeholder="ค้นหาชื่องานวิจัย, วารสาร..." style="padding-left: 35px; width: 100%; font-size: 0.85rem;">
                <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.8rem;"></i>
            </div>
        </div>

        <!-- Group lists -->
        <?php foreach (['Q1', 'Q2', 'Q3', 'Q4', 'N/A'] as $q_key): 
            $display_key = $q_key === 'N/A' ? 'NA' : $q_key;
            $pubs = $quartile_groups[$q_key];
            $badge_colors = [
                'Q1' => ['bg' => 'rgba(16, 185, 129, 0.15)', 'border' => 'rgba(16, 185, 129, 0.3)', 'text' => '#10b981'],
                'Q2' => ['bg' => 'rgba(59, 130, 246, 0.15)', 'border' => 'rgba(59, 130, 246, 0.3)', 'text' => '#3b82f6'],
                'Q3' => ['bg' => 'rgba(245, 158, 11, 0.15)', 'border' => 'rgba(245, 158, 11, 0.3)', 'text' => '#f59e0b'],
                'Q4' => ['bg' => 'rgba(239, 68, 68, 0.15)', 'border' => 'rgba(239, 68, 68, 0.3)', 'text' => '#ef4444'],
                'N/A' => ['bg' => 'rgba(255, 255, 255, 0.05)', 'border' => 'rgba(255, 255, 255, 0.1)', 'text' => 'var(--color-text-muted)']
            ];
            $current_color = $badge_colors[$q_key];
        ?>
            <div class="quartile-pub-list" id="quartile-list-<?php echo $display_key; ?>" style="display: <?php echo $display_key === 'Q1' ? 'flex' : 'none'; ?>; flex-direction: column; gap: 16px;">
                <?php if (empty($pubs)): ?>
                    <div style="text-align: center; padding: 40px 20px; color: var(--color-text-muted); font-size: 0.9rem;">
                        <i class="fa-regular fa-folder-open" style="font-size: 2.5rem; margin-bottom: 12px; opacity: 0.5; display: block;"></i>
                        ไม่พบผลงานวิจัยตีพิมพ์ในกลุ่มนี้
                    </div>
                <?php else: ?>
                    <?php foreach ($pubs as $pub): ?>
                        <div class="pub-row-item" data-search-text="<?php echo htmlspecialchars(strtolower($pub['title'] . ' ' . $pub['journal_name'] . ' ' . $pub['authors'] . ' ' . ($pub['linked_researchers'] ?? ''))); ?>" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 12px; padding: 18px; transition: var(--transition-smooth);">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 8px;">
                                <h4 style="margin: 0; font-size: 0.95rem; font-weight: 600; line-height: 1.4;">
                                    <?php if (!empty($pub['url'])): ?>
                                        <a href="<?php echo htmlspecialchars($pub['url']); ?>" target="_blank" style="text-decoration: none; color: inherit; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                            <?php echo htmlspecialchars($pub['title']); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.75rem; margin-left: 4px; opacity: 0.6;"></i>
                                        </a>
                                    <?php elseif (!empty($pub['doi'])): ?>
                                        <a href="https://doi.org/<?php echo htmlspecialchars($pub['doi']); ?>" target="_blank" style="text-decoration: none; color: inherit; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                            <?php echo htmlspecialchars($pub['title']); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.75rem; margin-left: 4px; opacity: 0.6;"></i>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($pub['title']); ?>
                                    <?php endif; ?>
                                </h4>
                                <span style="font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; font-weight: 700; background: <?php echo $current_color['bg']; ?>; border: 1px solid <?php echo $current_color['border']; ?>; color: <?php echo $current_color['text']; ?>; font-family: var(--font-eng);">
                                    <?php echo $q_key === 'N/A' ? 'Unclassified' : $q_key; ?>
                                </span>
                            </div>
                            
                            <div style="font-size: 0.82rem; color: var(--color-text-muted); margin-bottom: 6px;">
                                <i class="fa-solid fa-pen-nib" style="font-size: 0.75rem; margin-right: 4px;"></i> <?php echo htmlspecialchars($pub['authors']); ?>
                            </div>
                            
                            <div style="font-size: 0.82rem; font-weight: 500; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                <span><i class="fa-solid fa-book" style="font-size: 0.75rem; margin-right: 4px; color: var(--color-secondary);"></i> <?php echo htmlspecialchars($pub['journal_name'] ?: 'N/A'); ?></span>
                                <span style="color: var(--border-glass);">|</span>
                                <span>ปีตีพิมพ์: <strong style="font-family: var(--font-eng);"><?php echo $pub['publish_year']; ?></strong></span>
                                <?php if ($pub['citation_count'] > 0): ?>
                                    <span style="color: var(--border-glass);">|</span>
                                    <span style="color: var(--color-accent); font-weight: 600;"><i class="fa-solid fa-quote-right" style="font-size: 0.7rem; margin-right: 4px;"></i> Cited: <?php echo $pub['citation_count']; ?></span>
                                <?php endif; ?>
                                <span style="color: var(--border-glass);">|</span>
                                <span style="font-family: var(--font-eng); font-size: 0.72rem; background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px;">Source: <?php echo htmlspecialchars(strtoupper($pub['source'])); ?></span>
                            </div>

                            <!-- Linked MSC Researchers -->
                            <?php if (!empty($pub['linked_researchers'])): ?>
                                <div style="border-top: 1px dashed var(--border-glass); margin-top: 10px; padding-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                                    <span style="font-size: 0.72rem; color: var(--color-text-muted); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fa-solid fa-user-tag" style="color: var(--color-accent);"></i> นักวิจัยคณะ:
                                    </span>
                                    <?php 
                                    $linked = explode('|||', $pub['linked_researchers']);
                                    $parsed_researchers = [];
                                    foreach ($linked as $lnk) {
                                        $parts = explode('||', $lnk);
                                        $fullname_th = $parts[0] ?? '';
                                        $first_name_en = $parts[1] ?? '';
                                        $last_name_en = $parts[2] ?? '';
                                        
                                        $role = determine_author_role($pub['authors'], $first_name_en, $last_name_en);
                                        $parsed_researchers[] = [
                                            'fullname_th' => $fullname_th,
                                            'role' => $role
                                        ];
                                    }
                                    
                                    // Sort: First Author first, then Co-Author
                                    usort($parsed_researchers, function($a, $b) {
                                        if ($a['role'] === $b['role']) return 0;
                                        return ($a['role'] === 'First Author') ? -1 : 1;
                                    });
                                    
                                    foreach ($parsed_researchers as $res):
                                        $role = $res['role'];
                                        $role_color = ($role === 'First Author') ? 'var(--color-primary)' : 'var(--color-accent)';
                                        $role_bg = ($role === 'First Author') ? 'rgba(139,92,246,0.1)' : 'rgba(236,72,153,0.1)';
                                    ?>
                                        <div class="badge" style="font-size: 0.7rem; padding: 2px 8px; border-radius: 4px; background: <?php echo $role_bg; ?>; border-color: rgba(255,255,255,0.05); color: var(--color-text-main); font-weight: 500;">
                                            <strong><?php echo htmlspecialchars($res['fullname_th']); ?></strong>
                                            <span style="font-size: 0.6rem; color: <?php echo $role_color; ?>; margin-left: 4px; font-weight: 700; text-transform: uppercase;">
                                                <?php echo htmlspecialchars($role); ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <!-- Pagination Bar -->
                <div class="quartile-pagination" id="quartile-pagination-<?php echo $display_key; ?>" style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; align-items: center; flex-wrap: wrap;"></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 7. SECTION: COUNTRIES -->
<div id="section-countries" class="report-section" style="display: none;">
    <!-- Countries Grid -->
    <div style="margin-bottom: 25px;">
        <h3 style="margin-bottom: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-earth-americas" style="color: var(--color-accent);"></i>
            <span>ประเทศที่มีความร่วมมือวิจัยวิชาการร่วมกัน (พบทั้งหมด <?php echo count($country_groups); ?> ประเทศ)</span>
        </h3>
        <p style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 20px;">
            แสดงรายชื่อประเทศที่นักวิจัยในคณะฯ ได้ทำการตีพิมพ์ผลงานร่วมกับสถาบันหรือนักวิจัยในประเทศนั้น ๆ (ไม่รวมประเทศไทย) คลิกที่การ์ดประเทศเพื่อดูผลงานตีพิมพ์ทั้งหมด
        </p>
    </div>

    <?php if (empty($country_groups)): ?>
        <div class="glass-panel" style="padding: 60px; text-align: center; color: var(--color-text-muted);">
            <i class="fa-solid fa-earth-europe" style="font-size: 3.5rem; margin-bottom: 20px; color: rgba(255,255,255,0.1); display: block;"></i>
            <h4 style="font-size: 1.1rem; font-weight: 600; color: var(--color-text-main); margin-bottom: 8px;">ไม่พบข้อมูลความร่วมมือระหว่างประเทศ</h4>
            <p style="font-size: 0.85rem; max-width: 400px; margin: 0 auto; line-height: 1.6;">ในขอบเขตตัวกรองข้อมูลที่เลือก ไม่มีรายงานประเทศร่วมวิจัยอื่นนอกเหนือจากประเทศไทย</p>
        </div>
    <?php else: ?>
        <!-- Country Select Cards Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px;">
            <?php 
            $first_country = '';
            $c_idx = 0;
            foreach ($country_groups as $country_name => $c_pubs): 
                if ($c_idx === 0) $first_country = $country_name;
                $active_class = ($c_idx === 0) ? 'active' : '';
                $clean_id = preg_replace('/[^a-zA-Z0-9]/', '', $country_name);
            ?>
                <div class="glass-panel country-card <?php echo $active_class; ?>" onclick="selectCountryFilter('<?php echo htmlspecialchars($country_name); ?>', '<?php echo $clean_id; ?>')" id="c-card-<?php echo $clean_id; ?>" style="padding: 15px; text-align: center; cursor: pointer; border-bottom: 3px solid var(--color-secondary); transition: var(--transition-smooth); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;">
                    <!-- Country Flag Image -->
                    <?php 
                    $flag_url = get_country_flag_url($country_name);
                    if ($flag_url): 
                    ?>
                        <img src="<?php echo $flag_url; ?>" alt="<?php echo htmlspecialchars($country_name); ?> Flag" style="width: 32px; height: 22px; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.15); object-fit: cover; border: 1px solid rgba(255,255,255,0.1); margin-bottom: 2px;">
                    <?php else: ?>
                        <i class="fa-solid fa-earth-asia" style="font-size: 1.6rem; color: var(--color-primary); opacity: 0.8;"></i>
                    <?php endif; ?>
                    <div style="font-size: 0.9rem; font-weight: 700; color: var(--color-text-main); text-align: center; word-break: break-word;">
                        <?php echo htmlspecialchars($country_name); ?>
                    </div>
                    <div style="font-size: 0.75rem; background: rgba(var(--color-secondary-rgb), 0.1); color: var(--color-secondary); padding: 2px 8px; border-radius: 4px; font-weight: 600; font-family: var(--font-eng);">
                        <?php echo count($c_pubs); ?> ผลงาน
                    </div>
                </div>
            <?php 
                $c_idx++;
            endforeach; 
            ?>
        </div>

        <!-- Country Publications List Panel -->
        <div class="glass-panel" id="country-publications-panel" style="padding: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h3 style="margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-folder-open" style="color: var(--color-primary);"></i>
                    <span>ผลงานวิจัยร่วมกับประเทศ: </span>
                    <span id="active-country-title" style="color: var(--color-secondary);"><?php echo htmlspecialchars($first_country); ?></span>
                </h3>
                
                <div style="position: relative; width: 240px;">
                    <input type="text" id="country-pub-search" class="search-input" placeholder="ค้นหาชื่องานวิจัย, วารสาร..." style="padding-left: 35px; width: 100%; font-size: 0.85rem;">
                    <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.8rem;"></i>
                </div>
            </div>

            <!-- Group lists by country -->
            <?php foreach ($country_groups as $country_name => $c_pubs): 
                $clean_id = preg_replace('/[^a-zA-Z0-9]/', '', $country_name);
            ?>
                <div class="country-pub-list" id="country-list-<?php echo $clean_id; ?>" style="display: <?php echo $country_name === $first_country ? 'flex' : 'none'; ?>; flex-direction: column; gap: 16px;">
                    <?php foreach ($c_pubs as $pub): ?>
                        <div class="pub-row-item" data-search-text="<?php echo htmlspecialchars(strtolower($pub['title'] . ' ' . $pub['journal_name'] . ' ' . $pub['authors'] . ' ' . ($pub['linked_researchers'] ?? ''))); ?>" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 12px; padding: 18px; transition: var(--transition-smooth);">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 8px;">
                                <h4 style="margin: 0; font-size: 0.95rem; font-weight: 600; line-height: 1.4;">
                                    <?php if (!empty($pub['url'])): ?>
                                        <a href="<?php echo htmlspecialchars($pub['url']); ?>" target="_blank" style="text-decoration: none; color: inherit; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                            <?php echo htmlspecialchars($pub['title']); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.8rem; margin-left: 4px; opacity: 0.6;"></i>
                                        </a>
                                    <?php elseif (!empty($pub['doi'])): ?>
                                        <a href="https://doi.org/<?php echo htmlspecialchars($pub['doi']); ?>" target="_blank" style="text-decoration: none; color: inherit; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                            <?php echo htmlspecialchars($pub['title']); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.8rem; margin-left: 4px; opacity: 0.6;"></i>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($pub['title']); ?>
                                    <?php endif; ?>
                                </h4>
                                
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <!-- Quartile Badge -->
                                    <?php if (!empty($pub['quartile'])): 
                                        $q_color = '#ef4444';
                                        $q_bg = 'rgba(239, 68, 68, 0.15)';
                                        if ($pub['quartile'] === 'Q1') { $q_color = '#10b981'; $q_bg = 'rgba(16, 185, 129, 0.15)'; }
                                        elseif ($pub['quartile'] === 'Q2') { $q_color = '#3b82f6'; $q_bg = 'rgba(59, 130, 246, 0.15)'; }
                                        elseif ($pub['quartile'] === 'Q3') { $q_color = '#f59e0b'; $q_bg = 'rgba(245, 158, 11, 0.15)'; }
                                    ?>
                                        <span class="badge" style="background: <?php echo $q_bg; ?>; color: <?php echo $q_color; ?>; border: 1px solid <?php echo $q_color; ?>44; font-weight: 700; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px;">
                                            <?php echo $pub['quartile']; ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="pub-source-badge <?php echo 'source-' . htmlspecialchars($pub['source']); ?>" style="font-size: 0.65rem; padding: 2px 6px;">
                                        <?php echo htmlspecialchars(strtoupper($pub['source'])); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div style="font-size: 0.82rem; color: var(--color-text-muted); margin-bottom: 6px;">
                                <i class="fa-solid fa-pen-nib" style="font-size: 0.75rem; margin-right: 4px;"></i> <?php echo htmlspecialchars($pub['authors']); ?>
                            </div>
                            
                            <div style="font-size: 0.82rem; font-weight: 500; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                <span><i class="fa-solid fa-book" style="font-size: 0.75rem; margin-right: 4px; color: var(--color-secondary);"></i> <?php echo htmlspecialchars($pub['journal_name'] ?: 'N/A'); ?></span>
                                <span style="color: var(--border-glass);">|</span>
                                <span>ปีตีพิมพ์: <strong style="font-family: var(--font-eng);"><?php echo $pub['publish_year']; ?></strong></span>
                                <?php if ($pub['citation_count'] > 0): ?>
                                    <span style="color: var(--border-glass);">|</span>
                                    <span style="color: var(--color-accent); font-weight: 600;"><i class="fa-solid fa-quote-right" style="font-size: 0.7rem; margin-right: 4px;"></i> Cited: <?php echo $pub['citation_count']; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($pub['countries'])): ?>
                                    <span style="color: var(--border-glass);">|</span>
                                    <span style="color: #60a5fa; display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                                        <i class="fa-solid fa-earth-americas" style="font-size: 0.7rem; color: #60a5fa;"></i> 
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
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Linked MSC Researchers -->
                            <?php if (!empty($pub['linked_researchers'])): ?>
                                <div style="border-top: 1px dashed var(--border-glass); margin-top: 10px; padding-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                                    <span style="font-size: 0.72rem; color: var(--color-text-muted); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fa-solid fa-user-tag" style="color: var(--color-accent);"></i> นักวิจัยคณะ:
                                    </span>
                                    <?php 
                                    $linked = explode('|||', $pub['linked_researchers']);
                                    $parsed_researchers = [];
                                    foreach ($linked as $lnk) {
                                        $parts = explode('||', $lnk);
                                        $fullname_th = $parts[0] ?? '';
                                        $first_name_en = $parts[1] ?? '';
                                        $last_name_en = $parts[2] ?? '';
                                        
                                        $role = determine_author_role($pub['authors'], $first_name_en, $last_name_en);
                                        $parsed_researchers[] = [
                                            'fullname_th' => $fullname_th,
                                            'role' => $role
                                        ];
                                    }
                                    
                                    // Sort: First Author first, then Co-Author
                                    usort($parsed_researchers, function($a, $b) {
                                        if ($a['role'] === $b['role']) return 0;
                                        return ($a['role'] === 'First Author') ? -1 : 1;
                                    });
                                    
                                    foreach ($parsed_researchers as $res):
                                        $role = $res['role'];
                                        $role_color = ($role === 'First Author') ? 'var(--color-primary)' : 'var(--color-accent)';
                                        $role_bg = ($role === 'First Author') ? 'rgba(139,92,246,0.1)' : 'rgba(236,72,153,0.1)';
                                    ?>
                                        <div class="badge" style="font-size: 0.7rem; padding: 2px 8px; border-radius: 4px; background: <?php echo $role_bg; ?>; border-color: rgba(255,255,255,0.05); color: var(--color-text-main); font-weight: 500;">
                                            <strong><?php echo htmlspecialchars($res['fullname_th']); ?></strong>
                                            <span style="font-size: 0.6rem; color: <?php echo $role_color; ?>; margin-left: 4px; font-weight: 700; text-transform: uppercase;">
                                                <?php echo htmlspecialchars($role); ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <!-- Pagination Bar -->
                    <div class="country-pagination" id="country-pagination-<?php echo $clean_id; ?>" style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; align-items: center; flex-wrap: wrap;"></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</div>

<!-- 8. SECTION: FUNDING -->
<div id="section-funding" class="report-section" style="display: none;">
    <!-- Funding Overview Card -->
    <div class="glass-panel" style="padding: 25px; margin-bottom: 25px; border-left: 4px solid #10b981;">
        <h3 style="margin-bottom: 10px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-hand-holding-dollar" style="color: #10b981;"></i>
            <span>สรุปงบประมาณและแหล่งทุนวิจัย</span>
        </h3>
        <p style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 20px; line-height: 1.5;">
            วิเคราะห์ผลงานวิจัยที่ได้รับการสนับสนุนทุนวิจัย จำแนกตามหน่วยงานผู้มอบทุนวิจัย (Funding Sponsor) ทั้งหมดที่พบในฐานข้อมูลคณะ
        </p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 5px;">แหล่งทุนทั้งหมดที่พบ</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: #10b981; font-family: var(--font-eng);"><?php echo number_format(count($funding_groups)); ?> แหล่งทุน</div>
            </div>
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 15px; border-radius: 10px; text-align: center;">
                <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 5px;">มูลค่าเงินทุนรวมสะสม (ที่มีการกรอกในระบบ)</div>
                <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-accent); font-family: var(--font-eng);"><?php echo number_format($total_funding_amount, 2); ?> บาท</div>
            </div>
        </div>
    </div>

    <?php if (empty($funding_groups)): ?>
        <div class="glass-panel" style="padding: 60px; text-align: center; color: var(--color-text-muted);">
            <i class="fa-solid fa-hand-holding-dollar" style="font-size: 3.5rem; margin-bottom: 20px; color: rgba(255,255,255,0.1); display: block;"></i>
            <h4 style="font-size: 1.1rem; font-weight: 600; color: var(--color-text-main); margin-bottom: 8px;">ไม่พบข้อมูลแหล่งทุนวิจัย</h4>
            <p style="font-size: 0.85rem; max-width: 400px; margin: 0 auto; line-height: 1.6;">ในขอบเขตตัวกรองข้อมูลที่เลือก ไม่มีรายงานข้อมูลผู้มอบทุนวิจัยระบุอยู่</p>
        </div>
    <?php else: ?>
        <!-- Funding Select Cards Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; margin-bottom: 30px;">
            <?php 
            $first_sponsor = '';
            $f_idx = 0;
            foreach ($funding_groups as $sponsor_name => $f_data): 
                if ($f_idx === 0) $first_sponsor = $sponsor_name;
                $active_class = ($f_idx === 0) ? 'active' : '';
                $clean_id = preg_replace('/[^a-zA-Z0-9]/', '', $sponsor_name);
            ?>
                <div class="glass-panel funding-card <?php echo $active_class; ?>" onclick="selectFundingFilter('<?php echo htmlspecialchars($sponsor_name); ?>', '<?php echo $clean_id; ?>')" id="f-card-<?php echo $clean_id; ?>" style="padding: 15px; text-align: center; cursor: pointer; border-bottom: 3px solid #10b981; transition: var(--transition-smooth); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fa-solid fa-building-columns" style="font-size: 1.6rem; color: #10b981; opacity: 0.8;"></i>
                    <div style="font-size: 0.85rem; font-weight: 700; color: var(--color-text-main); text-align: center; word-break: break-word; line-height: 1.3; min-height: 2.6rem; display: flex; align-items: center; justify-content: center;">
                        <?php echo htmlspecialchars($sponsor_name); ?>
                    </div>
                    <div style="display: flex; gap: 5px; flex-wrap: wrap; justify-content: center; width: 100%;">
                        <span style="font-size: 0.7rem; background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 2px 6px; border-radius: 4px; font-weight: 600; font-family: var(--font-eng);">
                            <?php echo count($f_data['publications']); ?> ผลงาน
                        </span>
                        <?php if ($f_data['total_amount'] > 0): ?>
                            <span style="font-size: 0.7rem; background: rgba(236, 72, 153, 0.1); color: var(--color-accent); padding: 2px 6px; border-radius: 4px; font-weight: 600; font-family: var(--font-eng);">
                                ฿<?php echo number_format($f_data['total_amount']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php 
                $f_idx++;
            endforeach; 
            ?>
        </div>

        <!-- Funding Publications List Panel -->
        <div class="glass-panel" id="funding-publications-panel" style="padding: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h3 style="margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-folder-open" style="color: #10b981;"></i>
                    <span>ผลงานวิจัยสนับสนุนโดยแหล่งทุน: </span>
                    <span id="active-funding-title" style="color: #10b981;"><?php echo htmlspecialchars($first_sponsor); ?></span>
                </h3>
                
                <div style="position: relative; width: 240px;">
                    <input type="text" id="funding-pub-search" class="search-input" placeholder="ค้นหาชื่องานวิจัย, วารสาร..." style="padding-left: 35px; width: 100%; font-size: 0.85rem;">
                    <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.8rem;"></i>
                </div>
            </div>

            <!-- Group lists by funding sponsor -->
            <?php foreach ($funding_groups as $sponsor_name => $f_data): 
                $clean_id = preg_replace('/[^a-zA-Z0-9]/', '', $sponsor_name);
            ?>
                <div class="funding-pub-list" id="funding-list-<?php echo $clean_id; ?>" style="display: <?php echo $sponsor_name === $first_sponsor ? 'flex' : 'none'; ?>; flex-direction: column; gap: 16px;">
                    <?php foreach ($f_data['publications'] as $pub): ?>
                        <div class="pub-row-item" data-search-text="<?php echo htmlspecialchars(strtolower($pub['title'] . ' ' . $pub['journal_name'] . ' ' . $pub['authors'] . ' ' . ($pub['linked_researchers'] ?? ''))); ?>" style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 12px; padding: 18px; transition: var(--transition-smooth);">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 8px;">
                                <h4 style="margin: 0; font-size: 0.95rem; font-weight: 600; line-height: 1.4;">
                                    <?php if (!empty($pub['url'])): ?>
                                        <a href="<?php echo htmlspecialchars($pub['url']); ?>" target="_blank" style="text-decoration: none; color: inherit; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                            <?php echo htmlspecialchars($pub['title']); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.8rem; margin-left: 4px; opacity: 0.6;"></i>
                                        </a>
                                    <?php elseif (!empty($pub['doi'])): ?>
                                        <a href="https://doi.org/<?php echo htmlspecialchars($pub['doi']); ?>" target="_blank" style="text-decoration: none; color: inherit; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                            <?php echo htmlspecialchars($pub['title']); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.8rem; margin-left: 4px; opacity: 0.6;"></i>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($pub['title']); ?>
                                    <?php endif; ?>
                                </h4>
                                
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <!-- Quartile Badge -->
                                    <?php if (!empty($pub['quartile'])): 
                                        $q_color = '#ef4444';
                                        $q_bg = 'rgba(239, 68, 68, 0.15)';
                                        if ($pub['quartile'] === 'Q1') { $q_color = '#10b981'; $q_bg = 'rgba(16, 185, 129, 0.15)'; }
                                        elseif ($pub['quartile'] === 'Q2') { $q_color = '#3b82f6'; $q_bg = 'rgba(59, 130, 246, 0.15)'; }
                                        elseif ($pub['quartile'] === 'Q3') { $q_color = '#f59e0b'; $q_bg = 'rgba(245, 158, 11, 0.15)'; }
                                    ?>
                                        <span class="badge" style="background: <?php echo $q_bg; ?>; color: <?php echo $q_color; ?>; border: 1px solid <?php echo $q_color; ?>44; font-weight: 700; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px;">
                                            <?php echo $pub['quartile']; ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="pub-source-badge <?php echo 'source-' . htmlspecialchars($pub['source']); ?>" style="font-size: 0.65rem; padding: 2px 6px;">
                                        <?php echo htmlspecialchars(strtoupper($pub['source'])); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div style="font-size: 0.82rem; color: var(--color-text-muted); margin-bottom: 6px;">
                                <i class="fa-solid fa-pen-nib" style="font-size: 0.75rem; margin-right: 4px;"></i> <?php echo htmlspecialchars($pub['authors']); ?>
                            </div>
                            
                            <div style="font-size: 0.82rem; font-weight: 500; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                <span><i class="fa-solid fa-book" style="font-size: 0.75rem; margin-right: 4px; color: var(--color-secondary);"></i> <?php echo htmlspecialchars($pub['journal_name'] ?: 'N/A'); ?></span>
                                <span style="color: var(--border-glass);">|</span>
                                <span>ปีตีพิมพ์: <strong style="font-family: var(--font-eng);"><?php echo $pub['publish_year']; ?></strong></span>
                                <?php if ($pub['citation_count'] > 0): ?>
                                    <span style="color: var(--border-glass);">|</span>
                                    <span style="color: var(--color-accent); font-weight: 600;"><i class="fa-solid fa-quote-right" style="font-size: 0.7rem; margin-right: 4px;"></i> Cited: <?php echo $pub['citation_count']; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($pub['funding_no'])): ?>
                                    <span style="color: var(--border-glass);">|</span>
                                    <span style="color: #10b981;"><i class="fa-solid fa-tag" style="font-size: 0.7rem; margin-right: 4px;"></i> สัญญา: <?php echo htmlspecialchars($pub['funding_no']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($pub['funding_amount'])): ?>
                                    <span style="color: var(--border-glass);">|</span>
                                    <span style="color: #ec4899; font-weight: 600;"><i class="fa-solid fa-coins" style="font-size: 0.7rem; margin-right: 4px;"></i> งบประมาณ: <?php echo number_format($pub['funding_amount'], 2); ?> บาท</span>
                                <?php endif; ?>
                            </div>

                            <!-- Linked MSC Researchers -->
                            <?php if (!empty($pub['linked_researchers'])): ?>
                                <div style="border-top: 1px dashed var(--border-glass); margin-top: 10px; padding-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                                    <span style="font-size: 0.72rem; color: var(--color-text-muted); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fa-solid fa-user-tag" style="color: var(--color-accent);"></i> นักวิจัยคณะ:
                                    </span>
                                    <?php 
                                    $linked = explode('|||', $pub['linked_researchers']);
                                    $parsed_researchers = [];
                                    foreach ($linked as $lnk) {
                                        $parts = explode('||', $lnk);
                                        $fullname_th = $parts[0] ?? '';
                                        $first_name_en = $parts[1] ?? '';
                                        $last_name_en = $parts[2] ?? '';
                                        
                                        $role = determine_author_role($pub['authors'], $first_name_en, $last_name_en);
                                        $parsed_researchers[] = [
                                            'fullname_th' => $fullname_th,
                                            'role' => $role
                                        ];
                                    }
                                    
                                    // Sort: First Author first, then Co-Author
                                    usort($parsed_researchers, function($a, $b) {
                                        if ($a['role'] === $b['role']) return 0;
                                        return ($a['role'] === 'First Author') ? -1 : 1;
                                    });
                                    
                                    foreach ($parsed_researchers as $res):
                                        $role = $res['role'];
                                        $role_color = ($role === 'First Author') ? 'var(--color-primary)' : 'var(--color-accent)';
                                        $role_bg = ($role === 'First Author') ? 'rgba(139,92,246,0.1)' : 'rgba(236,72,153,0.1)';
                                    ?>
                                        <div class="badge" style="font-size: 0.7rem; padding: 2px 8px; border-radius: 4px; background: <?php echo $role_bg; ?>; border-color: rgba(255,255,255,0.05); color: var(--color-text-main); font-weight: 500;">
                                            <strong><?php echo htmlspecialchars($res['fullname_th']); ?></strong>
                                            <span style="font-size: 0.6rem; color: <?php echo $role_color; ?>; margin-left: 4px; font-weight: 700; text-transform: uppercase;">
                                                <?php echo htmlspecialchars($role); ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <!-- Pagination Bar -->
                    <div class="funding-pagination" id="funding-pagination-<?php echo $clean_id; ?>" style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; align-items: center; flex-wrap: wrap;"></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="section-sdgs" class="report-section" style="display: none;">
    <!-- SDGs Grid -->
    <div style="margin-bottom: 25px;">
        <h3 style="margin-bottom: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-leaf" style="color: #10b981;"></i>
            <span>ความสอดคล้องกับเป้าหมายการพัฒนาที่ยั่งยืน (SDGs)</span>
        </h3>
        <p style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 20px;">
            แสดงจำนวนผลงานวิจัยจำแนกตามเป้าหมาย SDGs ของสหประชาชาติ คลิกที่การ์ดเป้าหมายเพื่อแสดงรายชื่อผลงานตีพิมพ์ทั้งหมดที่เกี่ยวข้อง
        </p>
    </div>

    <?php if (empty($sdg_groups)): ?>
        <div class="glass-panel" style="padding: 60px; text-align: center; color: var(--color-text-muted);">
            <i class="fa-solid fa-leaf" style="font-size: 3.5rem; margin-bottom: 20px; color: rgba(255,255,255,0.1); display: block;"></i>
            <h4 style="font-size: 1.1rem; font-weight: 600; color: var(--color-text-main); margin-bottom: 8px;">ไม่พบข้อมูลเป้าหมาย SDGs ในบทความ</h4>
            <p style="font-size: 0.85rem; max-width: 400px; margin: 0 auto; line-height: 1.6;">ยังไม่มีการวิเคราะห์ระบุเป้าหมาย SDGs ให้กับบทความวิจัยในขอบเขตที่เลือก</p>
        </div>
    <?php else: ?>
        <!-- SDGs Select Cards Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; margin-bottom: 30px;">
            <?php 
            $first_sdg = '';
            $s_idx = 0;
            foreach ($sdg_groups as $sdg_code => $s_pubs): 
                if ($s_idx === 0) $first_sdg = $sdg_code;
                $active_class = ($s_idx === 0) ? 'active' : '';
                $clean_id = preg_replace('/[^a-zA-Z0-9]/', '', $sdg_code);
                
                $sdg_info = get_sdg_badge_details($sdg_code);
                $sdg_color = $sdg_info['color'] ?? '#10b981';
                $sdg_th_name = $sdg_info['th_name'] ?? '';
                
                $percent = count($filtered_publications) > 0 ? (count($s_pubs) / count($filtered_publications) * 100) : 0;
            ?>
                <div class="glass-panel sdg-card <?php echo $active_class; ?>" onclick="selectSdgFilter('<?php echo htmlspecialchars($sdg_code); ?>', '<?php echo $clean_id; ?>')" id="sdg-card-<?php echo $clean_id; ?>" style="padding: 20px 15px; text-align: center; cursor: pointer; border-bottom: 4px solid <?php echo $sdg_color; ?>; transition: var(--transition-smooth); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; min-height: 120px;">
                    <div style="width: 36px; height: 36px; border-radius: 6px; background: <?php echo $sdg_color; ?>; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-family: var(--font-eng); font-size: 1.1rem; box-shadow: 0 4px 10px <?php echo $sdg_color; ?>44; flex-shrink: 0;">
                        <?php echo (int)preg_replace('/[^0-9]/', '', $sdg_code); ?>
                    </div>
                    <div style="font-size: 0.85rem; font-weight: 600; color: var(--color-text-main); margin-top: 4px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; line-height: 1.3; height: 2.6em;">
                        <?php echo htmlspecialchars($sdg_th_name ?: $sdg_code); ?>
                    </div>
                    <div style="font-size: 1.3rem; font-weight: 700; color: <?php echo $sdg_color; ?>; font-family: var(--font-eng);">
                        <?php echo count($s_pubs); ?> <span style="font-size: 0.8rem; font-weight: 500; color: var(--color-text-muted);">ผลงาน (<?php echo number_format($percent, 1); ?>%)</span>
                    </div>
                </div>
            <?php 
                $s_idx++;
            endforeach; 
            ?>
        </div>

        <!-- SDG Publications List Panel -->
        <div class="glass-panel" id="sdg-publications-panel" style="padding: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h3 style="margin: 0; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-folder-open" style="color: #10b981;"></i>
                    <span>ผลงานวิจัยตามเป้าหมาย: </span>
                    <span id="active-sdg-title" style="color: var(--color-primary); font-weight: 700;"><?php echo htmlspecialchars($first_sdg); ?></span>
                </h3>
                
                <!-- Instant search in selected SDG -->
                <div style="position: relative; width: 280px;">
                    <input type="text" id="sdg-pub-search" class="search-input" placeholder="ค้นหาตามชื่อเรื่อง/ผู้แต่ง..." style="padding: 8px 12px 8px 35px; font-size: 0.85rem;">
                    <i class="fa-solid fa-magnifying-glass" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.8rem;"></i>
                </div>
            </div>

            <!-- Preloaded lists per SDG -->
            <?php foreach ($sdg_groups as $sdg_code => $s_pubs): 
                $clean_id = preg_replace('/[^a-zA-Z0-9]/', '', $sdg_code);
                $is_first = ($sdg_code === $first_sdg);
                $display_style = $is_first ? 'display: block;' : 'display: none;';
                
                $sdg_info = get_sdg_badge_details($sdg_code);
                $sdg_color = $sdg_info['color'] ?? '#10b981';
                $sdg_th_name = $sdg_info['th_name'] ?? '';
            ?>
                <div class="sdg-pub-sublist" id="sdg-list-<?php echo $clean_id; ?>" style="<?php echo $display_style; ?>">
                    <?php if (empty($s_pubs)): ?>
                        <div style="padding: 60px 40px; text-align: center; color: var(--color-text-muted);">
                            <i class="fa-solid fa-folder-open" style="font-size: 2.5rem; opacity: 0.15; margin-bottom: 15px; display: block;"></i>
                            <span>ยังไม่มีผลงานตีพิมพ์ที่สอดคล้องกับเป้าหมายนี้ในตัวกรองปัจจุบัน</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($s_pubs as $p_idx => $pub): ?>
                            <div class="publication-item searchable-pub-item" data-index="<?php echo $p_idx; ?>" style="padding: 15px 0; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <!-- Title & URL/DOI -->
                                <div style="font-weight: 500; font-size: 0.95rem; margin-bottom: 6px; line-height: 1.4;">
                                    <?php if (!empty($pub['url'])): ?>
                                        <a href="<?php echo htmlspecialchars($pub['url']); ?>" target="_blank" style="color: var(--color-text-main); text-decoration: none; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='var(--color-text-main)'">
                                            <?php echo htmlspecialchars($pub['title']); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.75rem; opacity: 0.5;"></i>
                                        </a>
                                    <?php elseif (!empty($pub['doi'])): ?>
                                        <a href="https://doi.org/<?php echo htmlspecialchars($pub['doi']); ?>" target="_blank" style="color: var(--color-text-main); text-decoration: none; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='var(--color-text-main)'">
                                            <?php echo htmlspecialchars($pub['title']); ?> <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.75rem; opacity: 0.5;"></i>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($pub['title']); ?>
                                    <?php endif; ?>
                                </div>

                                <!-- Authors -->
                                <div style="font-size: 0.82rem; color: var(--color-text-muted); margin-bottom: 8px;">
                                    <?php echo htmlspecialchars($pub['authors']); ?>
                                </div>

                                <!-- Journal, Year, Citations, Source, Quartile, SDGs -->
                                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 0.78rem; color: var(--color-text-muted);">
                                    <span><i class="fa-solid fa-journal-whills"></i> <?php echo htmlspecialchars($pub['journal_name'] ?? 'ไม่ระบุวารสาร'); ?></span>
                                    <span>•</span>
                                    <span>ปีพิมพ์: <?php echo $pub['publish_year']; ?></span>
                                    <span>•</span>
                                    <span style="color: var(--color-accent); font-weight: 500;"><i class="fa-solid fa-quote-right"></i> Citations: <?php echo $pub['citation_count']; ?></span>
                                    <span>•</span>
                                    <span class="pub-source-badge <?php echo 'source-' . strtolower($pub['source']); ?>" style="padding: 1px 5px; font-size: 0.65rem; border-radius: 3px;">
                                        <?php echo htmlspecialchars($pub['source']); ?>
                                    </span>
                                    
                                    <?php if (!empty($pub['quartile'])): 
                                        $q_color = '#ef4444';
                                        if ($pub['quartile'] === 'Q1') $q_color = '#10b981';
                                        elseif ($pub['quartile'] === 'Q2') $q_color = '#3b82f6';
                                        elseif ($pub['quartile'] === 'Q3') $q_color = '#f59e0b';
                                    ?>
                                        <span style="font-size: 0.68rem; padding: 1px 5px; border-radius: 3px; border: 1px solid <?php echo $q_color; ?>; color: <?php echo $q_color; ?>; font-weight: 600;">
                                            <?php echo $pub['quartile']; ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($pub['sdg_primary'])): ?>
                                        <?php echo render_sdg_badge($pub['sdg_primary'], true, '0.68rem'); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($pub['sdg_secondary'])): ?>
                                        <?php echo render_sdg_badge($pub['sdg_secondary'], false, '0.68rem'); ?>
                                    <?php endif; ?>
                                </div>

                                <!-- SDG Rationale -->
                                <?php if (!empty($pub['sdg_rationale'])): ?>
                                    <div style="margin-top: 8px; font-size: 0.78rem; color: #10b981; background: rgba(16, 185, 129, 0.05); border: 1px dashed rgba(16, 185, 129, 0.2); padding: 6px 12px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span><strong>เกณฑ์ประเมิน:</strong> <?php echo htmlspecialchars($pub['sdg_rationale']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <!-- Linked MSC Researchers -->
                                <?php if (!empty($pub['linked_researchers'])): ?>
                                    <div style="border-top: 1px dashed var(--border-glass); margin-top: 10px; padding-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                                        <span style="font-size: 0.72rem; color: var(--color-text-muted); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fa-solid fa-user-tag" style="color: var(--color-accent);"></i> นักวิจัยคณะ:
                                        </span>
                                        <?php 
                                        $linked = explode('|||', $pub['linked_researchers']);
                                        $parsed_researchers = [];
                                        foreach ($linked as $lnk) {
                                            $parts = explode('||', $lnk);
                                            $fullname_th = $parts[0] ?? '';
                                            $first_name_en = $parts[1] ?? '';
                                            $last_name_en = $parts[2] ?? '';
                                            
                                            $role = determine_author_role($pub['authors'], $first_name_en, $last_name_en);
                                            $parsed_researchers[] = [
                                                'fullname_th' => $fullname_th,
                                                'role' => $role
                                            ];
                                        }
                                        
                                        usort($parsed_researchers, function($a, $b) {
                                            if ($a['role'] === $b['role']) return 0;
                                            return ($a['role'] === 'First Author') ? -1 : 1;
                                        });
                                        
                                        foreach ($parsed_researchers as $res):
                                            $role = $res['role'];
                                            $role_color = ($role === 'First Author') ? 'var(--color-primary)' : 'var(--color-accent)';
                                            $role_bg = ($role === 'First Author') ? 'rgba(139,92,246,0.1)' : 'rgba(236,72,153,0.1)';
                                        ?>
                                            <div class="badge" style="font-size: 0.7rem; padding: 2px 8px; border-radius: 4px; background: <?php echo $role_bg; ?>; border-color: rgba(255,255,255,0.05); color: var(--color-text-main); font-weight: 500;">
                                                <strong><?php echo htmlspecialchars($res['fullname_th']); ?></strong>
                                                <span style="font-size: 0.6rem; color: <?php echo $role_color; ?>; margin-left: 4px; font-weight: 700; text-transform: uppercase;">
                                                    <?php echo htmlspecialchars($role); ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <!-- Pagination Bar -->
                        <div class="sdg-pagination" id="sdg-pagination-<?php echo $clean_id; ?>" style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; align-items: center; flex-wrap: wrap;"></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Tab and Chart Control Scripts -->
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Client-Side Tab Switching Logic
    window.switchReportTab = function(tabId) {
        // Hide all sections
        document.querySelectorAll('.report-section').forEach(sec => {
            sec.style.display = 'none';
        });
        
        // Deactivate all tab buttons
        document.querySelectorAll('.report-tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Show target section
        const targetSec = document.getElementById('section-' + tabId);
        if (targetSec) {
            targetSec.style.display = 'block';
        }
        
        // Activate clicked button
        const targetBtn = document.getElementById('tab-' + tabId);
        if (targetBtn) {
            targetBtn.classList.add('active');
        }

        // Trigger resize event to make hidden charts render correctly when they become visible
        window.dispatchEvent(new Event('resize'));
    };

    // Keep track of active pages per country
    window.countryPages = {};
    
    // Client-Side Country Pagination and Filter Logic
    window.paginateCountryList = function(countryId, pageNum = 1) {
        if (!window.countryPages[countryId]) {
            window.countryPages[countryId] = 1;
        }
        window.countryPages[countryId] = pageNum;
        
        const listContainer = document.getElementById('country-list-' + countryId);
        if (!listContainer) return;
        
        const searchInput = document.getElementById('country-pub-search');
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        const allItems = Array.from(listContainer.querySelectorAll('.pub-row-item'));
        
        // Filter items first by search
        const matchedItems = allItems.filter(item => {
            const text = item.getAttribute('data-search-text') || '';
            const matches = text.includes(query);
            if (!matches) {
                item.style.display = 'none';
            }
            return matches;
        });
        
        const pageSize = 10;
        const totalItems = matchedItems.length;
        const totalPages = Math.ceil(totalItems / pageSize);
        
        if (pageNum > totalPages && totalPages > 0) {
            pageNum = totalPages;
            window.countryPages[countryId] = pageNum;
        }
        if (pageNum < 1) {
            pageNum = 1;
            window.countryPages[countryId] = pageNum;
        }
        
        // Show/hide based on page
        matchedItems.forEach((item, index) => {
            const itemPage = Math.floor(index / pageSize) + 1;
            if (itemPage === pageNum) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
        
        // Render pagination controls
        const pagContainer = document.getElementById('country-pagination-' + countryId);
        if (!pagContainer) return;
        
        pagContainer.innerHTML = '';
        
        if (totalPages <= 1) return;
        
        const createBtn = (label, targetPage, isActive = false, isDisabled = false) => {
            const btn = document.createElement('button');
            btn.innerHTML = label;
            btn.className = 'btn';
            btn.style.padding = '6px 12px';
            btn.style.fontSize = '0.8rem';
            btn.style.margin = '0 3px';
            btn.style.minWidth = '35px';
            btn.style.display = 'inline-flex';
            btn.style.alignItems = 'center';
            btn.style.justifyContent = 'center';
            btn.style.borderRadius = '6px';
            
            if (isActive) {
                btn.style.background = 'linear-gradient(135deg, var(--color-primary), var(--color-secondary))';
                btn.style.color = 'white';
                btn.style.borderColor = 'transparent';
            } else {
                btn.style.background = 'rgba(255,255,255,0.03)';
                btn.style.color = 'var(--color-text-main)';
                btn.style.border = '1px solid var(--border-glass)';
            }
            
            if (isDisabled) {
                btn.style.opacity = '0.4';
                btn.style.cursor = 'not-allowed';
                btn.disabled = true;
            } else {
                btn.style.cursor = 'pointer';
                btn.onclick = (e) => {
                    e.preventDefault();
                    window.paginateCountryList(countryId, targetPage);
                };
            }
            return btn;
        };
        
        // Prev
        pagContainer.appendChild(createBtn('<i class="fa-solid fa-chevron-left"></i>', pageNum - 1, false, pageNum === 1));
        
        // Numbers
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= pageNum - 2 && i <= pageNum + 2)) {
                pagContainer.appendChild(createBtn(i, i, i === pageNum));
            } else if (i === pageNum - 3 || i === pageNum + 3) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                dots.style.color = 'var(--color-text-muted)';
                dots.style.padding = '0 5px';
                pagContainer.appendChild(dots);
            }
        }
        
        // Next
        pagContainer.appendChild(createBtn('<i class="fa-solid fa-chevron-right"></i>', pageNum + 1, false, pageNum === totalPages));
    };

    window.selectCountryFilter = function(countryName, countryId) {
        // Deactivate all cards
        document.querySelectorAll('.country-card').forEach(card => {
            card.classList.remove('active');
        });
        
        // Activate clicked card
        const targetCard = document.getElementById('c-card-' + countryId);
        if (targetCard) targetCard.classList.add('active');
        
        // Hide all lists
        document.querySelectorAll('.country-pub-list').forEach(list => {
            list.style.display = 'none';
        });
        
        // Show target list
        const targetList = document.getElementById('country-list-' + countryId);
        if (targetList) targetList.style.display = 'flex';
        
        // Update Title
        const titleSpan = document.getElementById('active-country-title');
        if (titleSpan) {
            titleSpan.textContent = countryName;
        }
        
        // Reset search input and run pagination on new active list
        const searchInput = document.getElementById('country-pub-search');
        if (searchInput) {
            searchInput.value = '';
        }
        window.paginateCountryList(countryId, 1);
        
        // Scroll smoothly to the publications list
        document.getElementById('country-publications-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    document.getElementById('country-pub-search')?.addEventListener('input', function(e) {
        // Find which card is currently active
        const activeCard = document.querySelector('.country-card.active');
        if (activeCard) {
            const countryId = activeCard.id.replace('c-card-', '');
            window.paginateCountryList(countryId, 1);
        }
    });

    // Initialize pagination for all countries
    document.querySelectorAll('.country-card').forEach(card => {
        const countryId = card.id.replace('c-card-', '');
        window.paginateCountryList(countryId, 1);
    });

    // Keep track of active pages per funding sponsor
    window.fundingPages = {};
    
    // Client-Side Funding Pagination and Filter Logic
    window.paginateFundingList = function(fundingId, pageNum = 1) {
        if (!window.fundingPages[fundingId]) {
            window.fundingPages[fundingId] = 1;
        }
        window.fundingPages[fundingId] = pageNum;
        
        const listContainer = document.getElementById('funding-list-' + fundingId);
        if (!listContainer) return;
        
        const searchInput = document.getElementById('funding-pub-search');
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        const allItems = Array.from(listContainer.querySelectorAll('.pub-row-item'));
        
        // Filter items first by search
        const matchedItems = allItems.filter(item => {
            const text = item.getAttribute('data-search-text') || '';
            const matches = text.includes(query);
            if (!matches) {
                item.style.display = 'none';
            }
            return matches;
        });
        
        const pageSize = 10;
        const totalItems = matchedItems.length;
        const totalPages = Math.ceil(totalItems / pageSize);
        
        if (pageNum > totalPages && totalPages > 0) {
            pageNum = totalPages;
            window.fundingPages[fundingId] = pageNum;
        }
        if (pageNum < 1) {
            pageNum = 1;
            window.fundingPages[fundingId] = pageNum;
        }
        
        // Show/hide based on page
        matchedItems.forEach((item, index) => {
            const itemPage = Math.floor(index / pageSize) + 1;
            if (itemPage === pageNum) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
        
        // Render pagination controls
        const pagContainer = document.getElementById('funding-pagination-' + fundingId);
        if (!pagContainer) return;
        
        pagContainer.innerHTML = '';
        
        if (totalPages <= 1) return;
        
        const createBtn = (label, targetPage, isActive = false, isDisabled = false) => {
            const btn = document.createElement('button');
            btn.innerHTML = label;
            btn.className = 'btn';
            btn.style.padding = '6px 12px';
            btn.style.fontSize = '0.8rem';
            btn.style.margin = '0 3px';
            btn.style.minWidth = '35px';
            btn.style.display = 'inline-flex';
            btn.style.alignItems = 'center';
            btn.style.justifyContent = 'center';
            btn.style.borderRadius = '6px';
            
            if (isActive) {
                btn.style.background = 'linear-gradient(135deg, var(--color-primary), var(--color-secondary))';
                btn.style.color = 'white';
                btn.style.borderColor = 'transparent';
            } else {
                btn.style.background = 'rgba(255,255,255,0.03)';
                btn.style.color = 'var(--color-text-main)';
                btn.style.border = '1px solid var(--border-glass)';
            }
            
            if (isDisabled) {
                btn.style.opacity = '0.4';
                btn.style.cursor = 'not-allowed';
                btn.disabled = true;
            } else {
                btn.style.cursor = 'pointer';
                btn.onclick = (e) => {
                    e.preventDefault();
                    window.paginateFundingList(fundingId, targetPage);
                };
            }
            return btn;
        };
        
        // Prev
        pagContainer.appendChild(createBtn('<i class="fa-solid fa-chevron-left"></i>', pageNum - 1, false, pageNum === 1));
        
        // Numbers
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= pageNum - 2 && i <= pageNum + 2)) {
                pagContainer.appendChild(createBtn(i, i, i === pageNum));
            } else if (i === pageNum - 3 || i === pageNum + 3) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                dots.style.color = 'var(--color-text-muted)';
                dots.style.padding = '0 5px';
                pagContainer.appendChild(dots);
            }
        }
        
        // Next
        pagContainer.appendChild(createBtn('<i class="fa-solid fa-chevron-right"></i>', pageNum + 1, false, pageNum === totalPages));
    };

    window.selectFundingFilter = function(sponsorName, fundingId) {
        // Deactivate all cards
        document.querySelectorAll('.funding-card').forEach(card => {
            card.classList.remove('active');
        });
        
        // Activate clicked card
        const targetCard = document.getElementById('f-card-' + fundingId);
        if (targetCard) targetCard.classList.add('active');
        
        // Hide all lists
        document.querySelectorAll('.funding-pub-list').forEach(list => {
            list.style.display = 'none';
        });
        
        // Show target list
        const targetList = document.getElementById('funding-list-' + fundingId);
        if (targetList) targetList.style.display = 'flex';
        
        // Update Title
        const titleSpan = document.getElementById('active-funding-title');
        if (titleSpan) {
            titleSpan.textContent = sponsorName;
        }
        
        // Reset search input and run pagination on new active list
        const searchInput = document.getElementById('funding-pub-search');
        if (searchInput) {
            searchInput.value = '';
        }
        window.paginateFundingList(fundingId, 1);
        
        // Scroll smoothly to the publications list
        document.getElementById('funding-publications-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    document.getElementById('funding-pub-search')?.addEventListener('input', function(e) {
        // Find which card is currently active
        const activeCard = document.querySelector('.funding-card.active');
        if (activeCard) {
            const fundingId = activeCard.id.replace('f-card-', '');
            window.paginateFundingList(fundingId, 1);
        }
    });

    // Initialize pagination for all funding sponsors
    document.querySelectorAll('.funding-card').forEach(card => {
        const fundingId = card.id.replace('f-card-', '');
        window.paginateFundingList(fundingId, 1);
    });

    // Keep track of active pages per SDG
    window.sdgPages = {};
    
    // Client-Side SDGs Pagination and Filter Logic
    window.paginateSdgList = function(sdgId, pageNum = 1) {
        if (!window.sdgPages[sdgId]) {
            window.sdgPages[sdgId] = 1;
        }
        window.sdgPages[sdgId] = pageNum;
        
        const listContainer = document.getElementById('sdg-list-' + sdgId);
        if (!listContainer) return;
        
        const searchInput = document.getElementById('sdg-pub-search');
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        // Filter items first
        const allItems = listContainer.querySelectorAll('.searchable-pub-item');
        let visibleItems = [];
        
        allItems.forEach(item => {
            const title = item.querySelector('a')?.textContent.toLowerCase() || '';
            const authors = item.querySelector('div:nth-child(2)')?.textContent.toLowerCase() || '';
            const journal = item.querySelector('div:nth-child(3)')?.textContent.toLowerCase() || '';
            
            if (title.indexOf(query) !== -1 || authors.indexOf(query) !== -1 || journal.indexOf(query) !== -1) {
                visibleItems.push(item);
            } else {
                item.style.display = 'none';
            }
        });
        
        // Paginate filtered items
        const pageSize = 10;
        const totalItems = visibleItems.length;
        const totalPages = Math.ceil(totalItems / pageSize);
        
        if (pageNum > totalPages && totalPages > 0) {
            pageNum = totalPages;
            window.sdgPages[sdgId] = pageNum;
        }
        
        visibleItems.forEach((item, index) => {
            const startIdx = (pageNum - 1) * pageSize;
            const endIdx = startIdx + pageSize;
            if (index >= startIdx && index < endIdx) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
        
        // Render pagination buttons
        const pagContainer = document.getElementById('sdg-pagination-' + sdgId);
        if (!pagContainer) return;
        pagContainer.innerHTML = '';
        
        if (totalPages <= 1) return;
        
        const createBtn = (label, targetPage, isActive = false, isDisabled = false) => {
            const btn = document.createElement('button');
            btn.innerHTML = label;
            btn.className = 'report-tab-btn';
            btn.style.padding = '4px 10px';
            btn.style.fontSize = '0.75rem';
            btn.style.borderRadius = '6px';
            btn.style.background = isActive ? 'var(--color-primary)' : (isDisabled ? 'rgba(255,255,255,0.02)' : 'rgba(255,255,255,0.05)');
            btn.style.borderColor = isActive ? 'var(--color-primary)' : 'var(--border-glass)';
            btn.style.color = isDisabled ? 'rgba(255,255,255,0.2)' : 'var(--color-text-main)';
            btn.style.cursor = isDisabled ? 'not-allowed' : 'pointer';
            
            if (!isDisabled && !isActive) {
                btn.addEventListener('click', () => {
                    window.paginateSdgList(sdgId, targetPage);
                    document.getElementById('sdg-publications-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
            return btn;
        };
        
        // Prev
        pagContainer.appendChild(createBtn('<i class="fa-solid fa-chevron-left"></i>', pageNum - 1, false, pageNum === 1));
        
        // Pages
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= pageNum - 2 && i <= pageNum + 2)) {
                pagContainer.appendChild(createBtn(i, i, i === pageNum));
            } else if (i === pageNum - 3 || i === pageNum + 3) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                dots.style.color = 'var(--color-text-muted)';
                dots.style.fontSize = '0.8rem';
                pagContainer.appendChild(dots);
            }
        }
        
        // Next
        pagContainer.appendChild(createBtn('<i class="fa-solid fa-chevron-right"></i>', pageNum + 1, false, pageNum === totalPages));
    };

    window.selectSdgFilter = function(sdgName, sdgId) {
        // Deactivate all cards
        document.querySelectorAll('.sdg-card').forEach(card => {
            card.classList.remove('active');
        });
        
        // Activate clicked card
        const targetCard = document.getElementById('sdg-card-' + sdgId);
        if (targetCard) targetCard.classList.add('active');
        
        // Hide all lists
        document.querySelectorAll('.sdg-pub-sublist').forEach(list => {
            list.style.display = 'none';
        });
        
        // Show target list
        const targetList = document.getElementById('sdg-list-' + sdgId);
        if (targetList) targetList.style.display = 'block';
        
        // Update Title
        const titleSpan = document.getElementById('active-sdg-title');
        if (titleSpan) {
            titleSpan.textContent = sdgName;
        }
        
        // Reset search input and run pagination on new active list
        const searchInput = document.getElementById('sdg-pub-search');
        if (searchInput) {
            searchInput.value = '';
        }
        window.paginateSdgList(sdgId, 1);
        
        // Scroll smoothly to the publications list
        document.getElementById('sdg-publications-panel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    document.getElementById('sdg-pub-search')?.addEventListener('input', function(e) {
        const activeCard = document.querySelector('.sdg-card.active');
        if (activeCard) {
            const sdgId = activeCard.id.replace('sdg-card-', '');
            window.paginateSdgList(sdgId, 1);
        }
    });

    // Initialize pagination for all SDGs
    document.querySelectorAll('.sdg-card').forEach(card => {
        const sdgId = card.id.replace('sdg-card-', '');
        window.paginateSdgList(sdgId, 1);
    });

    // Keep track of active pages per quartile
    window.quartilePages = {
        'Q1': 1,
        'Q2': 1,
        'Q3': 1,
        'Q4': 1,
        'NA': 1
    };

    // Client-Side Quartiles Pagination and Filter Logic
    window.paginateQuartileList = function(quartile, pageNum = 1) {
        window.quartilePages[quartile] = pageNum;
        const listContainer = document.getElementById('quartile-list-' + quartile);
        if (!listContainer) return;
        
        const searchInput = document.getElementById('quartile-pub-search');
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        
        const allItems = Array.from(listContainer.querySelectorAll('.pub-row-item'));
        
        // Filter items first by search
        const matchedItems = allItems.filter(item => {
            const text = item.getAttribute('data-search-text') || '';
            const matches = text.includes(query);
            if (!matches) {
                item.style.display = 'none';
            }
            return matches;
        });
        
        const pageSize = 10;
        const totalItems = matchedItems.length;
        const totalPages = Math.ceil(totalItems / pageSize);
        
        if (pageNum > totalPages && totalPages > 0) {
            pageNum = totalPages;
            window.quartilePages[quartile] = pageNum;
        }
        if (pageNum < 1) {
            pageNum = 1;
            window.quartilePages[quartile] = pageNum;
        }
        
        // Show/hide based on page
        matchedItems.forEach((item, index) => {
            const itemPage = Math.floor(index / pageSize) + 1;
            if (itemPage === pageNum) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
        
        // Render pagination controls
        const pagContainer = document.getElementById('quartile-pagination-' + quartile);
        if (!pagContainer) return;
        
        pagContainer.innerHTML = '';
        
        if (totalPages <= 1) return;
        
        const createBtn = (label, targetPage, isActive = false, isDisabled = false) => {
            const btn = document.createElement('button');
            btn.innerHTML = label;
            btn.className = 'btn';
            btn.style.padding = '6px 12px';
            btn.style.fontSize = '0.8rem';
            btn.style.margin = '0 3px';
            btn.style.minWidth = '35px';
            btn.style.display = 'inline-flex';
            btn.style.alignItems = 'center';
            btn.style.justifyContent = 'center';
            btn.style.borderRadius = '6px';
            
            if (isActive) {
                btn.style.background = 'linear-gradient(135deg, var(--color-primary), var(--color-secondary))';
                btn.style.color = 'white';
                btn.style.borderColor = 'transparent';
            } else {
                btn.style.background = 'rgba(255,255,255,0.03)';
                btn.style.color = 'var(--color-text-main)';
                btn.style.border = '1px solid var(--border-glass)';
            }
            
            if (isDisabled) {
                btn.style.opacity = '0.4';
                btn.style.cursor = 'not-allowed';
                btn.disabled = true;
            } else {
                btn.style.cursor = 'pointer';
                btn.onclick = (e) => {
                    e.preventDefault();
                    window.paginateQuartileList(quartile, targetPage);
                };
            }
            return btn;
        };
        
        // Prev
        pagContainer.appendChild(createBtn('<i class="fa-solid fa-chevron-left"></i>', pageNum - 1, false, pageNum === 1));
        
        // Numbers
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= pageNum - 2 && i <= pageNum + 2)) {
                pagContainer.appendChild(createBtn(i, i, i === pageNum));
            } else if (i === pageNum - 3 || i === pageNum + 3) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                dots.style.color = 'var(--color-text-muted)';
                dots.style.padding = '0 5px';
                pagContainer.appendChild(dots);
            }
        }
        
        // Next
        pagContainer.appendChild(createBtn('<i class="fa-solid fa-chevron-right"></i>', pageNum + 1, false, pageNum === totalPages));
    };

    window.selectQuartileFilter = function(quartile) {
        // Deactivate all cards
        document.querySelectorAll('.quartile-card').forEach(card => {
            card.classList.remove('active');
        });
        
        // Activate clicked card
        document.getElementById('q-card-' + quartile).classList.add('active');
        
        // Hide all lists
        document.querySelectorAll('.quartile-pub-list').forEach(list => {
            list.style.display = 'none';
        });
        
        // Show target list
        document.getElementById('quartile-list-' + quartile).style.display = 'flex';
        
        // Update Title
        const titleSpan = document.getElementById('active-quartile-title');
        const colors = {
            'Q1': '#10b981',
            'Q2': '#3b82f6',
            'Q3': '#f59e0b',
            'Q4': '#ef4444',
            'NA': 'var(--color-text-muted)'
        };
        const labels = {
            'Q1': 'Scopus Q1',
            'Q2': 'Scopus Q2',
            'Q3': 'Scopus Q3',
            'Q4': 'Scopus Q4',
            'NA': 'Unclassified / อื่นๆ'
        };
        if (titleSpan) {
            titleSpan.textContent = labels[quartile];
            titleSpan.style.color = colors[quartile];
        }
        
        // Reset search input and run pagination on new active list
        const searchInput = document.getElementById('quartile-pub-search');
        if (searchInput) {
            searchInput.value = '';
        }
        window.paginateQuartileList(quartile, 1);
    };

    document.getElementById('quartile-pub-search')?.addEventListener('input', function(e) {
        // Find which list is currently visible
        const activeCard = document.querySelector('.quartile-card.active');
        if (activeCard) {
            const quartile = activeCard.id.replace('q-card-', '');
            window.paginateQuartileList(quartile, 1);
        }
    });

    // Initialize pagination for all groups
    ['Q1', 'Q2', 'Q3', 'Q4', 'NA'].forEach(q => window.paginateQuartileList(q, 1));

    // 2. Client-Side Leaderboard Search Filter
    document.getElementById('leaderboard-search')?.addEventListener('input', function(e) {
        const query = e.target.value.toLowerCase().trim();
        document.querySelectorAll('#leaderboard-body tr').forEach(row => {
            const nameCell = row.cells[1]?.textContent.toLowerCase();
            const deptCell = row.cells[2]?.textContent.toLowerCase();
            if (nameCell.includes(query) || deptCell.includes(query)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

    // 2.1 Client-Side Leaderboard Sorting Logic
    window.sortLeaderboard = function(criteria) {
        const tbody = document.getElementById('leaderboard-body');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort((a, b) => {
            let valA, valB;
            if (criteria === 'pubs') {
                valA = parseInt(a.querySelector('.pubs-cell')?.textContent.replace(/,/g, '')) || 0;
                valB = parseInt(b.querySelector('.pubs-cell')?.textContent.replace(/,/g, '')) || 0;
            } else if (criteria === 'citations') {
                valA = parseInt(a.querySelector('.citations-cell')?.textContent.replace(/,/g, '')) || 0;
                valB = parseInt(b.querySelector('.citations-cell')?.textContent.replace(/,/g, '')) || 0;
            } else if (criteria === 'hindex') {
                valA = parseInt(a.querySelector('.hindex-cell')?.textContent.replace(/,/g, '')) || 0;
                valB = parseInt(b.querySelector('.hindex-cell')?.textContent.replace(/,/g, '')) || 0;
            }

            // Descending sort
            if (valB !== valA) return valB - valA;
            
            // Secondary sort: default to citations if tie
            const citA = parseInt(a.querySelector('.citations-cell')?.textContent.replace(/,/g, '')) || 0;
            const citB = parseInt(b.querySelector('.citations-cell')?.textContent.replace(/,/g, '')) || 0;
            return citB - citA;
        });

        // Re-append sorted rows and recalculate rank badges
        rows.forEach((row, index) => {
            tbody.appendChild(row);
            const rank = index + 1;
            const rankCell = row.querySelector('.rank-cell');
            if (rankCell) {
                if (rank === 1) {
                    rankCell.innerHTML = '<span style="background: rgba(251,191,36,0.15); color: #fbbf24; padding: 4px 8px; border-radius: 4px;">1</span>';
                } else if (rank === 2) {
                    rankCell.innerHTML = '<span style="background: rgba(148,163,184,0.15); color: #cbd5e1; padding: 4px 8px; border-radius: 4px;">2</span>';
                } else if (rank === 3) {
                    rankCell.innerHTML = '<span style="background: rgba(180,83,9,0.15); color: #d97706; padding: 4px 8px; border-radius: 4px;">3</span>';
                } else {
                    rankCell.textContent = rank;
                }
            }
        });
    };

    // 3. Chart.js Themes
    function getChartThemeColors() {
        const isLight = document.body.classList.contains('light-mode');
        return {
            text: isLight ? '#475569' : '#94a3b8',
            grid: isLight ? 'rgba(0, 0, 0, 0.05)' : 'rgba(255, 255, 255, 0.05)'
        };
    }

    let yearlyChartInstance = null;
    let deptPubsChartInstance = null;
    let deptCitationsChartInstance = null;
    let sourceChartInstance = null;
    let roleChartInstance = null;

    window.renderDashboardChart = function() {
        const theme = getChartThemeColors();
        
        // 1. Mixed Bar/Line Chart (Yearly trend)
        const ctxYear = document.getElementById('yearlyMixedChart');
        if (ctxYear) {
            if (yearlyChartInstance) yearlyChartInstance.destroy();
            yearlyChartInstance = new Chart(ctxYear, {
                data: {
                    labels: <?php echo json_encode($chart_years); ?>,
                    datasets: [
                        {
                            type: 'bar',
                            label: 'จำนวนผลงานตีพิมพ์',
                            data: <?php echo json_encode($chart_counts); ?>,
                            backgroundColor: 'rgba(139, 92, 246, 0.5)',
                            borderColor: '#8b5cf6',
                            borderWidth: 2,
                            borderRadius: 6,
                            yAxisID: 'y'
                        },
                        {
                            type: 'line',
                            label: 'จำนวน Citations',
                            data: <?php echo json_encode($chart_citations); ?>,
                            borderColor: '#ec4899',
                            backgroundColor: 'transparent',
                            borderWidth: 3,
                            pointBackgroundColor: '#ec4899',
                            tension: 0.3,
                            yAxisID: 'y1'
                        }
                    ]
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
                        legend: {
                            labels: { color: theme.text, font: { family: 'Sarabun' } }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: theme.grid },
                            ticks: { color: theme.text, font: { family: 'Sarabun' } }
                        },
                        y: {
                            position: 'left',
                            grid: { color: theme.grid },
                            ticks: { color: theme.text, precision: 0 }
                        },
                        y1: {
                            position: 'right',
                            grid: { drawOnChartArea: false },
                            ticks: { color: theme.text, precision: 0 }
                        }
                    }
                }
            });
        }

        // 2a. Department Publications Chart
        const ctxDeptPubs = document.getElementById('deptPubsChart');
        if (ctxDeptPubs) {
            if (deptPubsChartInstance) deptPubsChartInstance.destroy();
            deptPubsChartInstance = new Chart(ctxDeptPubs, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_depts); ?>,
                    datasets: [
                        {
                            label: 'จำนวนผลงานตีพิมพ์',
                            data: <?php echo json_encode($chart_dept_pubs); ?>,
                            backgroundColor: 'rgba(139, 92, 246, 0.6)',
                            borderColor: '#8b5cf6',
                            borderWidth: 2,
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: { color: theme.text, font: { family: 'Sarabun' } }
                        }
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

        // 2b. Department Citations Chart
        const ctxDeptCitations = document.getElementById('deptCitationsChart');
        if (ctxDeptCitations) {
            if (deptCitationsChartInstance) deptCitationsChartInstance.destroy();
            deptCitationsChartInstance = new Chart(ctxDeptCitations, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_depts); ?>,
                    datasets: [
                        {
                            label: 'จำนวน Citations รวม',
                            data: <?php echo json_encode($chart_dept_citations); ?>,
                            backgroundColor: 'rgba(236, 72, 153, 0.6)',
                            borderColor: '#ec4899',
                            borderWidth: 2,
                            borderRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: { color: theme.text, font: { family: 'Sarabun' } }
                        }
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
        
        // 3. Source Pie Chart
        const ctxSource = document.getElementById('sourceChart');
        if (ctxSource) {
            if (sourceChartInstance) sourceChartInstance.destroy();
            sourceChartInstance = new Chart(ctxSource, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($chart_sources); ?>,
                    datasets: [{
                        data: <?php echo json_encode($chart_source_counts); ?>,
                        backgroundColor: [
                            'rgba(16, 185, 129, 0.6)', // ORCID (Green)
                            'rgba(239, 68, 68, 0.6)',  // PUBMED (Red)
                            'rgba(245, 158, 11, 0.6)', // SCOPUS (Yellow)
                            'rgba(59, 130, 246, 0.6)', // SCHOLAR (Blue)
                            'rgba(107, 114, 128, 0.6)' // MANUAL (Grey)
                        ],
                        borderColor: 'transparent',
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const elementIndex = elements[0].index;
                            const source = sourceChartInstance.data.labels[elementIndex];
                            if (source) {
                                window.location.href = 'publications_search.php?source=' + encodeURIComponent(source.toLowerCase()) + '<?php echo $selected_year ? "&year=" . urlencode($selected_year) : ""; ?>';
                            }
                        }
                    },
                    onHover: (event, elements) => {
                        event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: theme.text,
                                font: { family: 'Sarabun', size: 11 }
                            }
                        }
                    }
                }
            });
        }

        // 4. Author Roles Pie Chart
        const ctxRole = document.getElementById('roleChart');
        if (ctxRole) {
            if (roleChartInstance) roleChartInstance.destroy();
            roleChartInstance = new Chart(ctxRole, {
                type: 'pie',
                data: {
                    labels: ['First Author', 'Co-Author'],
                    datasets: [{
                        data: [<?php echo $first_author_count; ?>, <?php echo $co_author_count; ?>],
                        backgroundColor: [
                            'rgba(139, 92, 246, 0.6)', // First (Purple)
                            'rgba(236, 72, 153, 0.6)'  // Co (Pink)
                        ],
                        borderColor: 'transparent',
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: (event, elements) => {
                        if (elements.length > 0) {
                            const elementIndex = elements[0].index;
                            const role = roleChartInstance.data.labels[elementIndex];
                            if (role) {
                                window.location.href = 'publications_search.php?role=' + encodeURIComponent(role) + '<?php echo $selected_dept ? "&dept=" . urlencode($selected_dept) : ""; ?><?php echo $selected_year ? "&year=" . urlencode($selected_year) : ""; ?>';
                            }
                        }
                    },
                    onHover: (event, elements) => {
                        event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: theme.text,
                                font: { family: 'Sarabun', size: 11 }
                            }
                        }
                    }
                }
            });
        }
    };
    
    // Switch to tab if requested in URL
    const urlParams = new URLSearchParams(window.location.search);
    const initialTab = urlParams.get('tab');
    if (initialTab) {
        window.switchReportTab(initialTab);
    }
    
    // Initial Render
    window.renderDashboardChart();
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
