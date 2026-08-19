<?php
// researchers_list.php
// Public researcher directory - filterable, sortable, paginated

$current_page = 'researchers';
$page_title = 'ทำเนียบนักวิจัย - MSC Research';

require_once __DIR__ . '/includes/functions.php';

$selected_dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$selected_type = isset($_GET['type']) ? trim($_GET['type']) : '';
$sort = in_array($_GET['sort'] ?? '', ['pubs', 'citations', 'hindex']) ? $_GET['sort'] : 'pubs';
// RESEARCHER-02: descending by default, ascending available via toggle
$order = ($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

// RESEARCHER-03: paginate at 20 per page
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$departments = get_departments_summary($pdo);

// RESEARCHER-05: get_all_researchers() defaults to active-only, which is
// exactly what the public directory needs.
$researchers = get_all_researchers($pdo);

// Populate h-index (calculate_researcher_h_index also refreshes the stored
// h_index_peak - this page load is the mechanism that keeps it current).
foreach ($researchers as &$r) {
    $r['h_index'] = calculate_researcher_h_index($pdo, $r['id']);
}
unset($r);

// RESEARCHER-01: filter by department and staff type
if ($selected_dept !== '' || $selected_type !== '') {
    $researchers = array_values(array_filter($researchers, function ($r) use ($selected_dept, $selected_type) {
        if ($selected_dept !== '' && $r['department'] !== $selected_dept) return false;
        if ($selected_type !== '' && ($r['researcher_type'] ?? 'สายวิชาการ') !== $selected_type) return false;
        return true;
    }));
}

// RESEARCHER-02: sort by pub count / citations / h-index, toggle asc/desc
$sort_key = $sort === 'citations' ? 'total_citations' : ($sort === 'hindex' ? 'h_index' : 'pub_count');
usort($researchers, function ($a, $b) use ($sort_key, $order) {
    $diff = (int)$a[$sort_key] <=> (int)$b[$sort_key];
    return $order === 'asc' ? $diff : -$diff;
});

// RESEARCHER-03: paginate
$total_rows = count($researchers);
$total_pages = max(1, (int)ceil($total_rows / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;
$page_researchers = array_slice($researchers, $offset, $limit);

// Helper to build a query string with one or more params overridden
function researchers_list_url($overrides) {
    $params = array_merge($_GET, $overrides);
    // Changing a filter/sort resets pagination
    if (!isset($overrides['page'])) {
        unset($params['page']);
    }
    return '?' . http_build_query($params);
}

include_once __DIR__ . '/includes/header.php';
?>

<div class="hero glass-panel animate-fade-in" style="padding: 40px 20px;">
    <h2>ทำเนียบนักวิจัย</h2>
    <p>คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา ค้นหารายชื่อบุคลากร ผลงานตีพิมพ์วิชาการ และดัชนีการอ้างอิง</p>
</div>

<!-- Main Layout Grid -->
<div class="main-grid animate-fade-in" style="animation-delay: 0.1s;">
    <!-- Sidebar Filters -->
    <div class="sidebar glass-panel">
        <div class="sidebar-title">
            <span>ตัวกรองข้อมูล</span>
            <i class="fa-solid fa-filter" style="color: var(--color-primary);"></i>
        </div>

        <!-- Department Filters -->
        <div class="filter-group">
            <label class="filter-label">ภาควิชา</label>
            <ul class="dept-list">
                <li class="dept-item">
                    <a href="<?php echo researchers_list_url(['dept' => '']); ?>" class="dept-btn <?php echo $selected_dept === '' ? 'active' : ''; ?>">
                        <span>ทั้งหมด</span>
                    </a>
                </li>
                <?php foreach ($departments as $dept): ?>
                    <li class="dept-item">
                        <a href="<?php echo researchers_list_url(['dept' => $dept['department']]); ?>" class="dept-btn <?php echo $selected_dept === $dept['department'] ? 'active' : ''; ?>">
                            <span><?php echo htmlspecialchars($dept['department']); ?></span>
                            <span class="dept-count"><?php echo $dept['count']; ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Researcher Type Filters -->
        <div class="filter-group" style="margin-top: 25px; border-top: 1px solid var(--border-glass); padding-top: 15px;">
            <label class="filter-label">ประเภทบุคลากร</label>
            <ul class="type-list" style="list-style: none;">
                <li class="type-item" style="margin-bottom: 8px;">
                    <a href="<?php echo researchers_list_url(['type' => '']); ?>" class="dept-btn" style="width: 100%; text-align: left; background: <?php echo $selected_type === '' ? 'var(--border-glass)' : 'none'; ?>; border: none; color: var(--color-text-main); padding: 8px 12px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; text-decoration: none;">
                        <span>ทั้งหมด</span>
                    </a>
                </li>
                <?php foreach (['สายวิชาการ', 'สายสนับสนุน'] as $t): ?>
                <li class="type-item" style="margin-bottom: 8px;">
                    <a href="<?php echo researchers_list_url(['type' => $t]); ?>" class="dept-btn" style="width: 100%; text-align: left; background: <?php echo $selected_type === $t ? 'var(--border-glass)' : 'none'; ?>; border: none; color: var(--color-text-main); padding: 8px 12px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; text-decoration: none;">
                        <span><?php echo $t; ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Researchers Directory -->
    <div>
        <!-- Sort & Title Toolbar -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
            <h3 style="margin: 0; font-weight: 600;">
                <i class="fa-solid fa-users" style="color: var(--color-accent); margin-right: 8px;"></i>
                รายชื่อนักวิจัย
                <span style="font-size: 0.8rem; font-weight: 400; color: var(--color-text-muted);">(<?php echo number_format($total_rows); ?> คน)</span>
            </h3>
            <div style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem; flex-wrap: wrap;">
                <span style="color: var(--color-text-muted);">เรียงตาม:</span>
                <div style="display: flex; background: rgba(120, 120, 120, 0.1); border: 1px solid var(--border-glass); border-radius: 8px; padding: 2px;">
                    <?php foreach (['pubs' => 'ผลงานตีพิมพ์', 'citations' => 'Citations', 'hindex' => 'h-index'] as $key => $label): ?>
                        <a href="<?php echo researchers_list_url(['sort' => $key]); ?>" style="padding: 6px 12px; border-radius: 6px; text-decoration: none; color: <?php echo $sort === $key ? 'var(--color-text-main)' : 'var(--color-text-muted)'; ?>; background: <?php echo $sort === $key ? 'var(--border-glass)' : 'transparent'; ?>; font-weight: <?php echo $sort === $key ? '600' : 'normal'; ?>;"><?php echo $label; ?></a>
                    <?php endforeach; ?>
                </div>
                <!-- RESEARCHER-02: ascending/descending toggle -->
                <a href="<?php echo researchers_list_url(['order' => $order === 'asc' ? 'desc' : 'asc']); ?>" class="btn-premium" style="padding: 6px 12px; font-size: 0.8rem; box-shadow: none; text-decoration: none;" title="สลับลำดับ">
                    <i class="fa-solid <?php echo $order === 'asc' ? 'fa-arrow-up-wide-short' : 'fa-arrow-down-wide-short'; ?>"></i>
                    <?php echo $order === 'asc' ? 'น้อย → มาก' : 'มาก → น้อย'; ?>
                </a>
            </div>
        </div>

        <div class="researchers-container">
            <?php if (empty($page_researchers)): ?>
                <div class="glass-panel" style="padding: 40px; text-align: center; color: var(--color-text-muted);">
                    <i class="fa-solid fa-users-slash" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
                    ไม่พบนักวิจัยตามเงื่อนไขที่เลือก
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
                    <?php foreach ($page_researchers as $r): ?>
                        <a href="profile.php?id=<?php echo $r['id']; ?>" class="glass-panel researcher-card">
                            <div class="researcher-avatar">
                                <?php if (!empty($r['avatar_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($r['avatar_url']); ?>" alt="avatar">
                                <?php else: ?>
                                    <i class="fa-solid fa-user-tie"></i>
                                <?php endif; ?>
                            </div>

                            <div class="researcher-info">
                                <div class="researcher-name" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <span><?php echo htmlspecialchars(($r['title_th'] ?? '') . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']); ?></span>
                                    <?php if (($r['researcher_type'] ?? 'สายวิชาการ') === 'สายสนับสนุน'): ?>
                                        <span class="badge" style="background: rgba(236, 72, 153, 0.15); color: #ec4899; border: 1px solid rgba(236,72,153,0.3); padding: 2px 8px; font-size: 0.7rem; font-weight: 600; text-shadow: none;">สายสนับสนุน</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: rgba(139, 92, 246, 0.15); color: #a78bfa; border: 1px solid rgba(139,92,246,0.3); padding: 2px 8px; font-size: 0.7rem; font-weight: 600; text-shadow: none;">สายวิชาการ</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 0.9rem; color: var(--color-text-muted); margin-bottom: 4px; font-family: var(--font-eng);">
                                    <?php echo htmlspecialchars(($r['title_en'] ?? '') . ' ' . $r['first_name_en'] . ' ' . $r['last_name_en']); ?>
                                </div>
                                <div class="researcher-dept">
                                    <i class="fa-solid fa-building-columns"></i> <?php echo htmlspecialchars($r['department']); ?>
                                </div>

                                <div class="researcher-meta">
                                    <?php if (!empty($r['orcid_id'])): ?>
                                        <span class="badge badge-orcid">
                                            <i class="fa-brands fa-orcid"></i> ORCID: <?php echo htmlspecialchars($r['orcid_id']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($r['scopus_author_id'])): ?>
                                        <span class="badge badge-scopus">
                                            <i class="fa-solid fa-graduation-cap"></i> Scopus ID: <?php echo htmlspecialchars($r['scopus_author_id']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($r['google_scholar_id'])): ?>
                                        <span class="badge badge-scholar">
                                            <i class="fa-solid fa-graduation-cap"></i> Scholar ID: <?php echo htmlspecialchars($r['google_scholar_id']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="text-align: right; display: flex; flex-direction: column; justify-content: center; gap: 8px; border-left: 1px solid var(--border-glass); padding-left: 20px; min-width: 140px;">
                                <div>
                                    <div style="font-size: 1.5rem; font-weight: 700; color: var(--color-primary); font-family: var(--font-eng); line-height: 1;">
                                        <?php echo (int)$r['pub_count']; ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">ผลงานตีพิมพ์</div>
                                </div>
                                <div>
                                    <div style="font-size: 1.1rem; font-weight: 700; color: var(--color-accent); font-family: var(--font-eng); line-height: 1;">
                                        <?php echo (int)$r['total_citations']; ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">Citations รวม</div>
                                </div>
                                <div>
                                    <div style="font-size: 1.1rem; font-weight: 700; color: #10b981; font-family: var(--font-eng); line-height: 1;">
                                        <?php echo (int)$r['h_index']; ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">h-index</div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination Bar -->
                <?php if ($total_pages > 1): ?>
                    <div style="display: flex; justify-content: center; gap: 8px; margin-top: 30px; align-items: center; flex-wrap: wrap;">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo researchers_list_url(['page' => $page - 1]); ?>" class="btn-premium" style="padding: 8px 14px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--color-text-main); box-shadow: none; font-size: 0.85rem; text-decoration: none;">
                                <i class="fa-solid fa-chevron-left"></i> ย้อนกลับ
                            </a>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                            $is_active = ($i === $page);
                        ?>
                            <a href="<?php echo researchers_list_url(['page' => $i]); ?>" class="btn-premium" style="padding: 8px 14px; font-size: 0.85rem; font-family: var(--font-eng); font-weight: 600; box-shadow: none; text-decoration: none; <?php echo $is_active ? 'background: linear-gradient(135deg, var(--color-primary), var(--color-secondary)); border: none;' : 'background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--color-text-main);'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo researchers_list_url(['page' => $page + 1]); ?>" class="btn-premium" style="padding: 8px 14px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); color: var(--color-text-main); box-shadow: none; font-size: 0.85rem; text-decoration: none;">
                                ถัดไป <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
include_once __DIR__ . '/includes/footer.php';
?>
