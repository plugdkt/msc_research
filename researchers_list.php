<?php
// researchers_list.php
// Listing all researchers with department filters and search

$current_page = 'researchers';
$page_title = 'ทำเนียบนักวิจัย - MSC Research';

require_once __DIR__ . '/includes/functions.php';

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'pubs';
$researchers = get_all_researchers($pdo);
$departments = get_departments_summary($pdo);

// Populate h-index on all researchers for sorting and display
foreach ($researchers as &$r) {
    $r['h_index'] = calculate_researcher_h_index($pdo, $r['id']);
}
unset($r);

// Sort researchers array
usort($researchers, function($a, $b) use ($sort) {
    if ($sort === 'citations') {
        return (int)$b['total_citations'] <=> (int)$a['total_citations'];
    } elseif ($sort === 'hindex') {
        return (int)$b['h_index'] <=> (int)$a['h_index'];
    } else { // default 'pubs'
        return (int)$b['pub_count'] <=> (int)$a['pub_count'];
    }
});

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
        
        <!-- Search Input -->
        <div class="filter-group">
            <label class="filter-label">ค้นหานักวิจัย</label>
            <div class="search-box">
                <i class="fa-solid fa-user-search search-icon" style="top: 50%;"></i>
                <input type="text" id="search-input" class="search-input" placeholder="พิมพ์ชื่อภาษาไทย หรือ อังกฤษ...">
            </div>
        </div>
        
        <!-- Department Filters -->
        <div class="filter-group">
            <label class="filter-label">ภาควิชา</label>
            <ul class="dept-list">
                <li class="dept-item">
                    <button class="dept-btn active" data-dept="all">
                        <span>ทั้งหมด</span>
                        <span class="dept-count"><?php echo count($researchers); ?></span>
                    </button>
                </li>
                <?php foreach ($departments as $dept): ?>
                    <li class="dept-item">
                        <button class="dept-btn" data-dept="<?php echo htmlspecialchars($dept['department']); ?>">
                            <span><?php echo htmlspecialchars($dept['department']); ?></span>
                            <span class="dept-count"><?php echo $dept['count']; ?></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Researcher Type Filters -->
        <div class="filter-group" style="margin-top: 25px; border-top: 1px solid var(--border-glass); padding-top: 15px;">
            <label class="filter-label">ประเภทบุคลากร</label>
            <ul class="type-list" style="list-style: none;">
                <li class="type-item" style="margin-bottom: 8px;">
                    <button class="dept-btn type-btn active" data-type="all" style="width: 100%; text-align: left; background: none; border: none; color: var(--color-text-muted); padding: 8px 12px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: var(--transition-smooth);">
                        <span>ทั้งหมด</span>
                    </button>
                </li>
                <li class="type-item" style="margin-bottom: 8px;">
                    <button class="dept-btn type-btn" data-type="สายวิชาการ" style="width: 100%; text-align: left; background: none; border: none; color: var(--color-text-muted); padding: 8px 12px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: var(--transition-smooth);">
                        <span>สายวิชาการ</span>
                    </button>
                </li>
                <li class="type-item" style="margin-bottom: 8px;">
                    <button class="dept-btn type-btn" data-type="สายสนับสนุน" style="width: 100%; text-align: left; background: none; border: none; color: var(--color-text-muted); padding: 8px 12px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: var(--transition-smooth);">
                        <span>สายสนับสนุน</span>
                    </button>
                </li>
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
            </h3>
            <div style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem;">
                <span style="color: var(--color-text-muted);">เรียงตาม:</span>
                <div style="display: flex; background: rgba(120, 120, 120, 0.1); border: 1px solid var(--border-glass); border-radius: 8px; padding: 2px;">
                    <a href="?sort=pubs" style="padding: 6px 12px; border-radius: 6px; text-decoration: none; color: <?php echo $sort === 'pubs' ? 'var(--color-text-main)' : 'var(--color-text-muted)'; ?>; background: <?php echo $sort === 'pubs' ? 'var(--border-glass)' : 'transparent'; ?>; font-weight: <?php echo $sort === 'pubs' ? '600' : 'normal'; ?>; transition: all 0.2s;">ผลงานตีพิมพ์</a>
                    <a href="?sort=citations" style="padding: 6px 12px; border-radius: 6px; text-decoration: none; color: <?php echo $sort === 'citations' ? 'var(--color-text-main)' : 'var(--color-text-muted)'; ?>; background: <?php echo $sort === 'citations' ? 'var(--border-glass)' : 'transparent'; ?>; font-weight: <?php echo $sort === 'citations' ? '600' : 'normal'; ?>; transition: all 0.2s;">Citations</a>
                    <a href="?sort=hindex" style="padding: 6px 12px; border-radius: 6px; text-decoration: none; color: <?php echo $sort === 'hindex' ? 'var(--color-text-main)' : 'var(--color-text-muted)'; ?>; background: <?php echo $sort === 'hindex' ? 'var(--border-glass)' : 'transparent'; ?>; font-weight: <?php echo $sort === 'hindex' ? '600' : 'normal'; ?>; transition: all 0.2s;">h-index</a>
                </div>
            </div>
        </div>

        <div class="researchers-container">
            <?php if (empty($researchers)): ?>
                <div class="glass-panel" style="padding: 40px; text-align: center; color: var(--color-text-muted);">
                    <i class="fa-solid fa-users-slash" style="font-size: 3rem; margin-bottom: 16px; display: block;"></i>
                    ยังไม่มีรายชื่อนักวิจัยในระบบกรุณาเพิ่มข้อมูลนักวิจัยผ่านระบบ Admin
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
                    <?php foreach ($researchers as $r): ?>
                        <a href="profile.php?id=<?php echo $r['id']; ?>" class="glass-panel researcher-card searchable-item" data-dept="<?php echo htmlspecialchars($r['department']); ?>" data-type="<?php echo htmlspecialchars($r['researcher_type'] ?? 'สายวิชาการ'); ?>">
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
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const typeButtons = document.querySelectorAll('.type-btn');
    const items = document.querySelectorAll('.searchable-item');
    
    // Store current active department and type
    let activeDept = 'all';
    let activeType = 'all';
    
    function applyFilters() {
        items.forEach(item => {
            const itemDept = item.getAttribute('data-dept');
            const itemType = item.getAttribute('data-type');
            
            const matchDept = (activeDept === 'all' || itemDept === activeDept);
            const matchType = (activeType === 'all' || itemType === activeType);
            
            if (matchDept && matchType) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    }
    
    // Override department buttons click behavior to combine with type filter
    const deptButtons = document.querySelectorAll('.dept-btn:not(.type-btn)');
    deptButtons.forEach(btn => {
        // Clone and replace to remove default main.js listener
        const newBtn = btn.cloneNode(true);
        btn.parentNode.replaceChild(newBtn, btn);
        
        newBtn.addEventListener('click', () => {
            document.querySelectorAll('.dept-btn:not(.type-btn)').forEach(b => b.classList.remove('active'));
            newBtn.classList.add('active');
            activeDept = newBtn.getAttribute('data-dept');
            applyFilters();
        });
    });
    
    // Setup type buttons click behavior
    typeButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            typeButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeType = btn.getAttribute('data-type');
            applyFilters();
        });
    });
});
</script>

<?php
include_once __DIR__ . '/includes/footer.php';
?>
