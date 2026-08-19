<?php
// publications_search.php
// Publications directory search with advanced filters & pagination

require_once __DIR__ . '/includes/functions.php';

$current_page = 'publications_search';
$page_title = 'ค้นหาผลงานวิจัย';

// Pagination variables
// SEARCH-03: 20 results per page
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Filter variables
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$selected_dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$selected_source = isset($_GET['source']) ? trim($_GET['source']) : '';
$selected_year = isset($_GET['year']) ? trim($_GET['year']) : '';
$selected_role = isset($_GET['role']) ? trim($_GET['role']) : '';
$selected_quartile = isset($_GET['quartile']) ? trim($_GET['quartile']) : '';
$selected_sdg = isset($_GET['sdg']) ? trim($_GET['sdg']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';

// Dynamic filters population
$years_stmt = $pdo->query("SELECT DISTINCT publish_year FROM publications WHERE publish_year IS NOT NULL AND publish_year > 0 ORDER BY publish_year DESC");
$years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

$depts_stmt = $pdo->query("SELECT DISTINCT department FROM researchers WHERE department != '' ORDER BY department ASC");
$depts = $depts_stmt->fetchAll(PDO::FETCH_COLUMN);

// Build SQL conditions
$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = "(p.title LIKE :search1 OR p.authors LIKE :search2 OR p.journal_name LIKE :search3)";
    $params['search1'] = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
    $params['search3'] = '%' . $search . '%';
}

if ($selected_dept !== '') {
    $conditions[] = "p.id IN (
        SELECT rp_sub.publication_id 
        FROM researcher_publications rp_sub
        JOIN researchers r_sub ON rp_sub.researcher_id = r_sub.id
        WHERE r_sub.department = :dept
    )";
    $params['dept'] = $selected_dept;
}

if ($selected_source !== '') {
    $conditions[] = "p.source = :source";
    $params['source'] = $selected_source;
}

if ($selected_year !== '') {
    $conditions[] = "p.publish_year = :year";
    $params['year'] = (int)$selected_year;
}

if ($selected_quartile !== '') {
    if ($selected_quartile === 'NA' || $selected_quartile === 'N/A') {
        $conditions[] = "(p.quartile IS NULL OR p.quartile = '' OR p.quartile NOT IN ('Q1', 'Q2', 'Q3', 'Q4'))";
    } else {
        $conditions[] = "p.quartile = :quartile";
        $params['quartile'] = $selected_quartile;
    }
}

if ($selected_sdg !== '') {
    $conditions[] = "(p.sdg_primary = :sdg_filter OR p.sdg_secondary = :sdg_filter)";
    $params['sdg_filter'] = $selected_sdg;
}

if ($selected_role === 'First Author') {
    $conditions[] = "EXISTS (
        SELECT 1 
        FROM researcher_publications rp_sub
        JOIN researchers r_sub ON rp_sub.researcher_id = r_sub.id
        WHERE rp_sub.publication_id = p.id
          AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.authors, ',', 1), ';', 1)) LIKE CONCAT('%', LOWER(r_sub.last_name_en), '%')
          AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.authors, ',', 1), ';', 1)) LIKE CONCAT('%', LOWER(SUBSTRING(r_sub.first_name_en, 1, 1)), '%')
    )";
} elseif ($selected_role === 'Co-Author') {
    $conditions[] = "EXISTS (
        SELECT 1 
        FROM researcher_publications rp_sub
        JOIN researchers r_sub ON rp_sub.researcher_id = r_sub.id
        WHERE rp_sub.publication_id = p.id
          AND NOT (
              LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.authors, ',', 1), ';', 1)) LIKE CONCAT('%', LOWER(r_sub.last_name_en), '%')
              AND LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(p.authors, ',', 1), ';', 1)) LIKE CONCAT('%', LOWER(SUBSTRING(r_sub.first_name_en, 1, 1)), '%')
          )
    )";
}

$where_clause = '';
if (!empty($conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $conditions);
}

// Order by clause
$order_by = "ORDER BY p.publish_year DESC, p.publish_date DESC, p.id DESC"; // default newest
if ($sort === 'oldest') {
    $order_by = "ORDER BY p.publish_year ASC, p.publish_date ASC, p.id ASC";
} elseif ($sort === 'citations') {
    $order_by = "ORDER BY p.citation_count DESC, p.publish_year DESC";
} elseif ($sort === 'title') {
    $order_by = "ORDER BY p.title ASC";
}

// Count total results for pagination
$count_sql = "
    SELECT COUNT(DISTINCT p.id) 
    FROM publications p
    LEFT JOIN researcher_publications rp ON p.id = rp.publication_id
    LEFT JOIN researchers r ON rp.researcher_id = r.id
    $where_clause
";
$stmt_count = $pdo->prepare($count_sql);
foreach ($params as $key => $val) {
    $stmt_count->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt_count->execute();
$total_rows = $stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// Fetch page results
$sql = "
    SELECT p.*, 
           GROUP_CONCAT(DISTINCT CONCAT(r.title_th, ' ', r.first_name_th, ' ', r.last_name_th, '||', r.first_name_en, '||', r.last_name_en) SEPARATOR '|||') AS linked_researchers,
           GROUP_CONCAT(DISTINCT r.department SEPARATOR '||') AS departments,
           CONCAT(ca.title_th, ca.first_name_th, ' ', ca.last_name_th) as corresponding_author_name
    FROM publications p
    LEFT JOIN researcher_publications rp ON p.id = rp.publication_id
    LEFT JOIN researchers r ON rp.researcher_id = r.id
    LEFT JOIN researchers ca ON p.corresponding_author_id = ca.id
    $where_clause
    GROUP BY p.id
    $order_by
    LIMIT :offset, :limit
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue(':' . $key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$publications = $stmt->fetchAll();

include_once __DIR__ . '/includes/header.php';
?>

<!-- Search Hero Section -->
<div class="hero glass-panel animate-fade-in" style="padding: 40px 20px; margin-bottom: 30px;">
    <h2>ค้นหาผลงานตีพิมพ์ทางวิชาการ (Search Publications)</h2>
    <p>สืบค้นข้อมูลรายงานวิจัย สิ่งตีพิมพ์ทางวิชาการ และการอ้างอิง (Citations) ของบุคลากรในคณะวิทยาศาสตร์การแพทย์ทั้งหมด</p>
</div>

<div class="main-grid animate-fade-in" style="grid-template-columns: 320px 1fr; gap: 30px; animation-delay: 0.1s;">
    <!-- Filters Panel -->
    <div>
        <form method="GET" action="publications_search.php" class="glass-panel" style="padding: 24px; position: sticky; top: 110px; display: flex; flex-direction: column; gap: 20px;">
            <h3 style="margin: 0 0 10px 0; font-weight: 600; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px;">
                <i class="fa-solid fa-filter" style="color: var(--color-primary);"></i> ตัวกรองข้อมูล
            </h3>
            
            <!-- Search Text -->
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 8px; font-weight: 500;">คำค้นหา (ชื่อบทความ, ผู้แต่ง, วารสาร)</label>
                <div style="position: relative;">
                    <input type="text" name="q" class="search-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="พิมพ์คำค้นหา..." style="padding-left: 35px; width: 100%;">
                    <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.85rem;"></i>
                </div>
            </div>

            <!-- Department Filter -->
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 8px; font-weight: 500;">ภาควิชา</label>
                <select name="dept" class="search-input" style="width: 100%; cursor: pointer;">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($depts as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $selected_dept === $dept ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Source Database Filter -->
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 8px; font-weight: 500;">ฐานข้อมูล (Source)</label>
                <select name="source" class="search-input" style="width: 100%; cursor: pointer;">
                    <option value="">ทั้งหมด</option>
                    <option value="scopus" <?php echo $selected_source === 'scopus' ? 'selected' : ''; ?>>Scopus</option>
                    <option value="scholar" <?php echo $selected_source === 'scholar' ? 'selected' : ''; ?>>Google Scholar</option>
                    <option value="orcid" <?php echo $selected_source === 'orcid' ? 'selected' : ''; ?>>ORCID</option>
                    <option value="manual" <?php echo $selected_source === 'manual' ? 'selected' : ''; ?>>Manual Input</option>
                </select>
            </div>

            <!-- Publish Year Filter -->
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 8px; font-weight: 500;">ปีที่ตีพิมพ์ (ค.ศ.)</label>
                <select name="year" class="search-input" style="width: 100%; cursor: pointer;">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($years as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo $selected_year === (string)$yr ? 'selected' : ''; ?>>
                            <?php echo $yr; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Author Role Filter -->
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 8px; font-weight: 500;">บทบาทผู้แต่ง</label>
                <select name="role" class="search-input" style="width: 100%; cursor: pointer;">
                    <option value="">ทั้งหมด</option>
                    <option value="First Author" <?php echo $selected_role === 'First Author' ? 'selected' : ''; ?>>First Author</option>
                    <option value="Co-Author" <?php echo $selected_role === 'Co-Author' ? 'selected' : ''; ?>>Co-Author</option>
                </select>
            </div>

            <!-- Scopus Quartile Filter -->
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 8px; font-weight: 500;">ระดับ Quartile (Scopus)</label>
                <select name="quartile" class="search-input" style="width: 100%; cursor: pointer;">
                    <option value="">ทั้งหมด</option>
                    <option value="Q1" <?php echo $selected_quartile === 'Q1' ? 'selected' : ''; ?>>Scopus Q1</option>
                    <option value="Q2" <?php echo $selected_quartile === 'Q2' ? 'selected' : ''; ?>>Scopus Q2</option>
                    <option value="Q3" <?php echo $selected_quartile === 'Q3' ? 'selected' : ''; ?>>Scopus Q3</option>
                    <option value="Q4" <?php echo $selected_quartile === 'Q4' ? 'selected' : ''; ?>>Scopus Q4</option>
                    <option value="NA" <?php echo $selected_quartile === 'NA' ? 'selected' : ''; ?>>Unclassified / อื่นๆ</option>
                </select>
            </div>

            <!-- SDGs Filter -->
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 8px; font-weight: 500;">เป้าหมายความยั่งยืน (SDGs)</label>
                <select name="sdg" class="search-input" style="width: 100%; cursor: pointer; height: auto;">
                    <option value="">ทั้งหมด</option>
                    <?php foreach (get_all_sdgs() as $code => $details): ?>
                        <option value="<?php echo $code; ?>" <?php echo $selected_sdg === $code ? 'selected' : ''; ?>>
                            <?php echo $code . ': ' . htmlspecialchars($details['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Sorting Filter -->
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted); display: block; margin-bottom: 8px; font-weight: 500;">เรียงลำดับตาม</label>
                <select name="sort" class="search-input" style="width: 100%; cursor: pointer;">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>ปีตีพิมพ์ใหม่สุด</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>ปีตีพิมพ์เก่าสุด</option>
                    <option value="citations" <?php echo $sort === 'citations' ? 'selected' : ''; ?>>จำนวนการอ้างอิง (Citations)</option>
                    <option value="title" <?php echo $sort === 'title' ? 'selected' : ''; ?>>ชื่อบทความ (A-Z)</option>
                </select>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 10px;">
                <button type="submit" class="btn-premium" style="flex: 1; padding: 12px; justify-content: center; font-size: 0.9rem;">
                    <i class="fa-solid fa-search"></i> ค้นหา
                </button>
                <a href="publications_search.php" class="btn-premium" style="padding: 12px; text-decoration: none; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--color-text-main); font-size: 0.9rem;" title="รีเซ็ตทั้งหมด">
                    <i class="fa-solid fa-arrow-rotate-left"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Publications List Grid -->
    <div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div style="font-size: 0.9rem; color: var(--color-text-muted);">
                พบทั้งหมด <strong><?php echo number_format($total_rows); ?></strong> รายการงานวิจัย
                <?php if ($total_pages > 1): ?>
                    (หน้า <?php echo $page; ?> จาก <?php echo $total_pages; ?>)
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($publications)): ?>
            <div class="glass-panel" style="padding: 60px; text-align: center; color: var(--color-text-muted);">
                <i class="fa-solid fa-book-open" style="font-size: 3.5rem; margin-bottom: 20px; color: rgba(255,255,255,0.1); display: block;"></i>
                <h4 style="font-size: 1.1rem; font-weight: 600; color: var(--color-text-main); margin-bottom: 8px;">ไม่พบข้อมูลสิ่งตีพิมพ์วิชาการ</h4>
                <p style="font-size: 0.85rem; max-width: 400px; margin: 0 auto; line-height: 1.6;">ลองปรับคีย์เวิร์ดในการพิมพ์ค้นหา หรือปลดการตั้งค่าตัวกรองข้อมูลบางตัวออก</p>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 16px;">
                <?php foreach ($publications as $pub): ?>
                    <div class="publication-card glass-panel" style="position: relative;">
                        <!-- Top header source tags -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 12px; flex-wrap: wrap;">
                            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                <span class="pub-source-badge <?php echo 'source-' . htmlspecialchars($pub['source']); ?>">
                                    <?php echo htmlspecialchars(strtoupper($pub['source'])); ?>
                                </span>
                                <?php if (!empty($pub['quartile'])): 
                                    $q_color = '#ef4444';
                                    $q_bg = 'rgba(239, 68, 68, 0.15)';
                                    if ($pub['quartile'] === 'Q1') { $q_color = '#10b981'; $q_bg = 'rgba(16, 185, 129, 0.15)'; }
                                    elseif ($pub['quartile'] === 'Q2') { $q_color = '#3b82f6'; $q_bg = 'rgba(59, 130, 246, 0.15)'; }
                                    elseif ($pub['quartile'] === 'Q3') { $q_color = '#f59e0b'; $q_bg = 'rgba(245, 158, 11, 0.15)'; }
                                ?>
                                    <span class="badge" style="background: <?php echo $q_bg; ?>; color: <?php echo $q_color; ?>; border: 1px solid <?php echo $q_color; ?>44; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; display: inline-flex; align-items: center;">
                                        <?php echo $pub['quartile']; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($pub['sdg_primary'])): ?>
                                    <?php echo render_sdg_badge($pub['sdg_primary'], true, '0.72rem'); ?>
                                <?php endif; ?>
                                <?php if (!empty($pub['sdg_secondary'])): ?>
                                    <?php echo render_sdg_badge($pub['sdg_secondary'], false, '0.72rem'); ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($pub['citation_count'] > 0): ?>
                                <span class="pub-citations">
                                    <i class="fa-solid fa-quote-right" style="font-size: 0.75rem;"></i> Cited: <strong><?php echo number_format($pub['citation_count']); ?></strong>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Title -->
                        <h4 class="pub-title" style="margin-bottom: 10px; font-weight: 600;">
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

                        <!-- Authors List (From Database Text) -->
                        <div class="pub-authors" style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 8px;">
                            <i class="fa-solid fa-pen-nib" style="margin-right: 4px; font-size: 0.8rem;"></i> <?php echo htmlspecialchars($pub['authors']); ?>
                        </div>

                        <!-- Journal Info -->
                        <div class="pub-journal" style="font-size: 0.85rem; font-weight: 500; margin-bottom: 12px;">
                            <i class="fa-solid fa-book" style="margin-right: 4px; font-size: 0.8rem;"></i> <?php echo htmlspecialchars($pub['journal_name'] ?: 'N/A'); ?>
                            <?php if ($pub['publish_year']): ?>
                                &bull; <strong style="font-family: var(--font-eng);"><?php echo $pub['publish_year']; ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($pub['doi'])): ?>
                                &bull; <span style="font-family: var(--font-eng); font-size: 0.75rem; color: var(--color-text-muted);">DOI: <?php echo htmlspecialchars($pub['doi']); ?></span>
                            <?php endif; ?>
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

                        <!-- Linked MSC Researchers and Departments -->
                        <?php if (!empty($pub['linked_researchers'])): ?>
                            <div style="border-top: 1px dashed var(--border-glass); padding-top: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                                <span style="font-size: 0.75rem; color: var(--color-text-muted); font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
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
                                    <div class="badge" style="font-size: 0.75rem; padding: 4px 10px; border-radius: 6px; background: <?php echo $role_bg; ?>; border-color: rgba(255,255,255,0.05); color: var(--color-text-main); font-weight: 500;">
                                        <strong><?php echo htmlspecialchars($res['fullname_th']); ?></strong>
                                        <span style="font-size: 0.65rem; color: <?php echo $role_color; ?>; margin-left: 5px; font-weight: 700; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($role); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Bar -->
            <?php if ($total_pages > 1): ?>
                <div style="display: flex; justify-content: center; gap: 8px; margin-top: 30px; align-items: center; flex-wrap: wrap;">
                    <?php 
                    // Build query string variables for pagination links
                    $query_params = $_GET;
                    
                    // Prev Page Link
                    if ($page > 1): 
                        $query_params['page'] = $page - 1;
                        $prev_url = '?' . http_build_query($query_params);
                    ?>
                        <a href="<?php echo $prev_url; ?>" class="btn-premium" style="padding: 8px 14px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--color-text-main); box-shadow: none; font-size: 0.85rem;">
                            <i class="fa-solid fa-chevron-left"></i> ย้อนกลับ
                        </a>
                    <?php endif; ?>

                    <?php 
                    // Pagination numbers logic (show max 5 numbers)
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++): 
                        $query_params['page'] = $i;
                        $page_url = '?' . http_build_query($query_params);
                        $is_active = ($i === $page);
                    ?>
                        <a href="<?php echo $page_url; ?>" class="btn-premium" style="padding: 8px 14px; font-size: 0.85rem; font-family: var(--font-eng); font-weight: 600; box-shadow: none; <?php echo $is_active ? 'background: linear-gradient(135deg, var(--color-primary), var(--color-secondary)); border: none;' : 'background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--color-text-main);'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php 
                    // Next Page Link
                    if ($page < $total_pages): 
                        $query_params['page'] = $page + 1;
                        $next_url = '?' . http_build_query($query_params);
                    ?>
                        <a href="<?php echo $next_url; ?>" class="btn-premium" style="padding: 8px 14px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--color-text-main); box-shadow: none; font-size: 0.85rem;">
                            ถัดไป <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
