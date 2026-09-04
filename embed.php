<?php
// embed.php
// Standalone lightweight embeddable widget endpoint for MSC Research
// Allows external websites (faculty portal, department sites, personal pages) to embed research data.

// Ensure iframe embedding is allowed across domains
header_remove('X-Frame-Options');
header("Content-Security-Policy: frame-ancestors *;");

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Sanitize query parameters
$type = trim($_GET['type'] ?? 'recent');
if (!in_array($type, ['recent', 'stats', 'researcher', 'department'])) {
    $type = 'recent';
}

$theme = trim($_GET['theme'] ?? 'light');
if (!in_array($theme, ['light', 'dark', 'transparent'])) {
    $theme = 'light';
}

$limit = isset($_GET['limit']) ? max(1, min(20, (int)$_GET['limit'])) : 5;
$quartile_filter = trim($_GET['quartile'] ?? '');
$show_header = isset($_GET['header']) ? (bool)(int)$_GET['header'] : true;
$show_badge = isset($_GET['badge']) ? (bool)(int)$_GET['badge'] : true;

// Data Fetching depending on type
$data = [];
$extra_info = [];

if ($type === 'stats') {
    $total_pubs = get_total_publications($pdo);
    $total_cits = get_total_citations($pdo);
    $total_researchers = get_total_researchers($pdo);
    
    // Q1 + Q2 percentage
    $stmtQ = $pdo->query("SELECT SUM(CASE WHEN quartile IN ('Q1', 'Q2') THEN 1 ELSE 0 END) as q_high, COUNT(*) as total FROM publications WHERE quartile IS NOT NULL AND quartile != ''");
    $q_row = $stmtQ->fetch(PDO::FETCH_ASSOC);
    $q_pct = ($q_row && $q_row['total'] > 0) ? round(($q_row['q_high'] / $q_row['total']) * 100) : 0;

    // International collaboration
    $stmtInter = $pdo->query("SELECT COUNT(*) FROM publications WHERE countries IS NOT NULL AND TRIM(countries) != '' AND TRIM(REPLACE(REPLACE(countries, 'Thailand', ''), ',', '')) != ''");
    $inter_count = $stmtInter->fetchColumn() ?: 0;

    $extra_info = [
        'total_pubs' => $total_pubs,
        'total_cits' => $total_cits,
        'total_researchers' => $total_researchers,
        'q_pct' => $q_pct,
        'inter_count' => $inter_count,
    ];
} elseif ($type === 'researcher') {
    $res_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $res_stmt = $pdo->prepare("SELECT * FROM researchers WHERE id = :id");
    $res_stmt->execute(['id' => $res_id]);
    $researcher = $res_stmt->fetch(PDO::FETCH_ASSOC);

    if ($researcher) {
        $extra_info['researcher'] = $researcher;
        
        $p_sql = "
            SELECT p.* 
            FROM publications p
            JOIN researcher_publications rp ON p.id = rp.publication_id
            WHERE rp.researcher_id = :id
        ";
        if ($quartile_filter === 'Q1') {
            $p_sql .= " AND p.quartile = 'Q1'";
        } elseif ($quartile_filter === 'Q1_Q2') {
            $p_sql .= " AND p.quartile IN ('Q1', 'Q2')";
        }
        $p_sql .= " ORDER BY p.publish_year DESC, p.publish_date DESC, p.id DESC LIMIT :limit";
        
        $p_stmt = $pdo->prepare($p_sql);
        $p_stmt->bindValue(':id', $res_id, PDO::PARAM_INT);
        $p_stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $p_stmt->execute();
        $data = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($type === 'department') {
    $dept = trim($_GET['dept'] ?? '');
    $extra_info['dept'] = $dept;

    $d_sql = "
        SELECT DISTINCT p.* 
        FROM publications p
        JOIN researcher_publications rp ON p.id = rp.publication_id
        JOIN researchers r ON rp.researcher_id = r.id
        WHERE r.department = :dept
    ";
    if ($quartile_filter === 'Q1') {
        $d_sql .= " AND p.quartile = 'Q1'";
    } elseif ($quartile_filter === 'Q1_Q2') {
        $d_sql .= " AND p.quartile IN ('Q1', 'Q2')";
    }
    $d_sql .= " ORDER BY p.publish_year DESC, p.publish_date DESC, p.id DESC LIMIT :limit";

    $d_stmt = $pdo->prepare($d_sql);
    $d_stmt->bindValue(':dept', $dept, PDO::PARAM_STR);
    $d_stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $d_stmt->execute();
    $data = $d_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Recent publications
    $q_sql = "SELECT p.* FROM publications p WHERE 1=1";
    if ($quartile_filter === 'Q1') {
        $q_sql .= " AND p.quartile = 'Q1'";
    } elseif ($quartile_filter === 'Q1_Q2') {
        $q_sql .= " AND p.quartile IN ('Q1', 'Q2')";
    }
    $q_sql .= " ORDER BY p.publish_year DESC, p.publish_date DESC, p.id DESC LIMIT :limit";

    $stmt = $pdo->prepare($q_sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Base URL calculation for links back to full system
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'www.medsci.up.ac.th';
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$base_dir = ($script_dir === '/' || $script_dir === '\\' || $script_dir === '.') ? '' : '/' . trim($script_dir, '/\\');
$system_url = $protocol . $host . $base_dir;
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSC Research Widget</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --font-th: 'Sarabun', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --font-display: 'Kanit', sans-serif;
            --color-primary: #10b981;
            --color-primary-dark: #059669;
            --color-secondary: #f59e0b;
            --color-accent: #3b82f6;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            height: auto !important;
            min-height: 0 !important;
            overflow: hidden;
        }

        body {
            font-family: var(--font-th);
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            background: transparent;
        }

        /* Themes */
        body.theme-light {
            background-color: #ffffff;
            color: #1e293b;
        }
        body.theme-light .widget-container {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
        }
        body.theme-light .widget-item {
            background: #f8fafc;
            border: 1px solid #edf2f7;
        }
        body.theme-light .widget-item:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        body.theme-light .text-muted { color: #64748b; }
        body.theme-light .text-main { color: #0f172a; }
        body.theme-light .stat-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        body.theme-dark {
            background-color: #0b0f19;
            color: #f1f5f9;
        }
        body.theme-dark .widget-container {
            background-color: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        body.theme-dark .widget-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }
        body.theme-dark .widget-item:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(16, 185, 129, 0.4);
        }
        body.theme-dark .text-muted { color: #94a3b8; }
        body.theme-dark .text-main { color: #f8fafc; }
        body.theme-dark .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        body.theme-transparent {
            background: transparent;
            color: inherit;
        }
        body.theme-transparent .widget-container {
            background: transparent;
            border: none;
            padding: 4px;
        }
        body.theme-transparent .widget-item {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(150, 150, 150, 0.2);
        }

        /* Widget Container */
        .widget-container {
            width: 100%;
            border-radius: 12px;
            padding: 18px 20px;
            transition: all 0.2s ease;
        }

        /* Header */
        .widget-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(150, 150, 150, 0.15);
            flex-wrap: wrap;
            gap: 8px;
        }
        .widget-title {
            font-family: var(--font-display);
            font-size: 1.05rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .widget-title i {
            color: var(--color-primary);
        }
        .widget-badge {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.12);
            color: var(--color-primary);
            border: 1px solid rgba(16, 185, 129, 0.25);
            text-decoration: none;
        }

        /* List Items */
        .widget-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .widget-item {
            border-radius: 8px;
            padding: 12px 14px;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .item-title {
            font-size: 0.92rem;
            font-weight: 600;
            line-height: 1.45;
            margin-bottom: 6px;
            color: inherit;
        }
        .item-title a {
            color: inherit;
            text-decoration: none;
            transition: color 0.15s;
        }
        .item-title a:hover {
            color: var(--color-primary);
        }
        .item-meta {
            font-size: 0.78rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .meta-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Badges */
        .badge-q {
            font-size: 0.7rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
            display: inline-block;
        }
        .badge-q1 { background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.35); }
        .badge-q2 { background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.35); }
        .badge-q3 { background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.35); }
        .badge-q4 { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.35); }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
        }
        .stat-card {
            border-radius: 10px;
            padding: 14px 12px;
            text-align: center;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .stat-num {
            font-family: var(--font-display);
            font-size: 1.7rem;
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 4px;
        }
        .stat-label {
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Researcher Profile Header in Widget */
        .researcher-box {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 14px;
            padding: 10px;
            border-radius: 10px;
            background: rgba(16, 185, 129, 0.05);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        .res-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--color-primary);
            flex-shrink: 0;
            background: #e2e8f0;
        }
        .res-info h4 {
            font-size: 0.98rem;
            font-weight: 700;
            line-height: 1.25;
            margin-bottom: 2px;
        }
        .res-info p {
            font-size: 0.78rem;
        }

        /* Footer */
        .widget-footer {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1px solid rgba(150, 150, 150, 0.12);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.72rem;
        }
        .widget-footer a {
            color: var(--color-primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .widget-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body class="theme-<?php echo htmlspecialchars($theme); ?>">

<div class="widget-container">
    <?php if ($show_header): ?>
        <div class="widget-header">
            <div class="widget-title">
                <?php if ($type === 'stats'): ?>
                    <i class="fa-solid fa-chart-line"></i>
                    <span>ภาพรวมงานวิจัย คณะวิทยาศาสตร์การแพทย์</span>
                <?php elseif ($type === 'researcher' && !empty($extra_info['researcher'])): ?>
                    <i class="fa-solid fa-user-graduate"></i>
                    <span>ข้อมูลและผลงานวิจัยอาจารย์</span>
                <?php elseif ($type === 'department' && !empty($extra_info['dept'])): ?>
                    <i class="fa-solid fa-building-user"></i>
                    <span>ผลงานวิจัย ภาควิชา<?php echo htmlspecialchars($extra_info['dept']); ?></span>
                <?php else: ?>
                    <i class="fa-solid fa-book-open"></i>
                    <span>ผลงานตีพิมพ์วิจัยล่าสุด</span>
                <?php endif; ?>
            </div>

            <?php if ($show_badge): ?>
                <a href="<?php echo htmlspecialchars($system_url); ?>" target="_blank" class="widget-badge" title="MSC Research Portal">
                    <i class="fa-solid fa-up-right-from-square" style="font-size: 0.65rem;"></i> MSC Research
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Type 1: Stats Overview -->
    <?php if ($type === 'stats'): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-num" style="color: #10b981;"><?php echo number_format($extra_info['total_pubs']); ?></div>
                <div class="stat-label text-muted">ผลงานตีพิมพ์รวม</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" style="color: #3b82f6;"><?php echo number_format($extra_info['total_cits']); ?></div>
                <div class="stat-label text-muted">Citations สะสม</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" style="color: #f59e0b;"><?php echo $extra_info['q_pct']; ?>%</div>
                <div class="stat-label text-muted">สัดส่วน Q1 + Q2</div>
            </div>
            <div class="stat-card">
                <div class="stat-num" style="color: #8b5cf6;"><?php echo number_format($extra_info['inter_count']); ?></div>
                <div class="stat-label text-muted">ร่วมวิจัยนานาชาติ</div>
            </div>
        </div>

    <!-- Type 2: Researcher Profile + Recent Papers -->
    <?php elseif ($type === 'researcher'): ?>
        <?php if (empty($extra_info['researcher'])): ?>
            <div style="text-align: center; padding: 20px; color: var(--color-text-muted);">
                ไม่พบข้อมูลนักวิจัยที่ระบุ (ID: <?php echo htmlspecialchars($_GET['id'] ?? ''); ?>)
            </div>
        <?php else: 
            $r = $extra_info['researcher'];
            $full_th = trim(($r['title_th'] ?? '') . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']);
            $full_en = trim(($r['title_en'] ?? '') . ' ' . $r['first_name_en'] . ' ' . $r['last_name_en']);
            $avatar = !empty($r['avatar_url']) ? $system_url . '/' . $r['avatar_url'] : $system_url . '/assets/img/default-avatar.png';
        ?>
            <div class="researcher-box">
                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="avatar" class="res-avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($full_en); ?>&background=10b981&color=fff'">
                <div class="res-info">
                    <h4 class="text-main"><?php echo htmlspecialchars($full_th ?: $full_en); ?></h4>
                    <p class="text-muted">
                        <span><i class="fa-solid fa-building" style="font-size: 0.7rem;"></i> <?php echo htmlspecialchars($r['department'] ?: 'คณะวิทยาศาสตร์การแพทย์'); ?></span>
                        <?php if (!empty($r['h_index_peak'])): ?>
                            &bull; <strong style="color: #10b981;">h-index: <?php echo (int)$r['h_index_peak']; ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="widget-list">
                <?php if (empty($data)): ?>
                    <div style="text-align: center; padding: 15px; font-size: 0.85rem;" class="text-muted">ยังไม่มีผลงานตีพิมพ์ที่บันทึกในระบบ</div>
                <?php else: ?>
                    <?php foreach ($data as $item): ?>
                        <div class="widget-item">
                            <div class="item-title">
                                <?php if (!empty($item['url'])): ?>
                                    <a href="<?php echo htmlspecialchars($item['url']); ?>" target="_blank">
                                        <?php echo htmlspecialchars($item['title']); ?>
                                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.65rem; opacity: 0.6; margin-left: 2px;"></i>
                                    </a>
                                <?php elseif (!empty($item['doi'])): ?>
                                    <a href="https://doi.org/<?php echo htmlspecialchars($item['doi']); ?>" target="_blank">
                                        <?php echo htmlspecialchars($item['title']); ?>
                                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.65rem; opacity: 0.6; margin-left: 2px;"></i>
                                    </a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($item['title']); ?>
                                <?php endif; ?>
                            </div>
                            <div class="item-meta">
                                <div class="meta-left text-muted">
                                    <span><i class="fa-solid fa-book" style="font-size: 0.68rem;"></i> <?php echo htmlspecialchars($item['journal_name'] ?: 'N/A'); ?> (<?php echo $item['publish_year']; ?>)</span>
                                    <?php if ((int)$item['citation_count'] > 0): ?>
                                        <span>&bull;</span>
                                        <span style="color: #f59e0b;"><i class="fa-solid fa-quote-right" style="font-size: 0.65rem;"></i> <?php echo (int)$item['citation_count']; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item['quartile'])): ?>
                                    <span class="badge-q badge-<?php echo strtolower($item['quartile']); ?>">
                                        <?php echo htmlspecialchars($item['quartile']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <!-- Type 3 & 4: Recent Publications & Department Publications -->
    <?php else: ?>
        <div class="widget-list">
            <?php if (empty($data)): ?>
                <div style="text-align: center; padding: 20px;" class="text-muted">ไม่พบข้อมูลผลงานตีพิมพ์ตามเงื่อนไข</div>
            <?php else: ?>
                <?php foreach ($data as $item): ?>
                    <div class="widget-item">
                        <div class="item-title">
                            <?php if (!empty($item['url'])): ?>
                                <a href="<?php echo htmlspecialchars($item['url']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($item['title']); ?>
                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.65rem; opacity: 0.6; margin-left: 2px;"></i>
                                </a>
                            <?php elseif (!empty($item['doi'])): ?>
                                <a href="https://doi.org/<?php echo htmlspecialchars($item['doi']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($item['title']); ?>
                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.65rem; opacity: 0.6; margin-left: 2px;"></i>
                                </a>
                            <?php else: ?>
                                <?php echo htmlspecialchars($item['title']); ?>
                            <?php endif; ?>
                        </div>
                        <div class="item-meta">
                            <div class="meta-left text-muted">
                                <span><i class="fa-solid fa-book" style="font-size: 0.68rem;"></i> <?php echo htmlspecialchars($item['journal_name'] ?: 'N/A'); ?> (<?php echo $item['publish_year']; ?>)</span>
                                <?php if ((int)$item['citation_count'] > 0): ?>
                                    <span>&bull;</span>
                                    <span style="color: #f59e0b;"><i class="fa-solid fa-quote-right" style="font-size: 0.65rem;"></i> <?php echo (int)$item['citation_count']; ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['quartile'])): ?>
                                <span class="badge-q badge-<?php echo strtolower($item['quartile']); ?>">
                                    <?php echo htmlspecialchars($item['quartile']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="widget-footer text-muted">
        <span>ข้อมูลจากระบบฐานข้อมูลงานวิจัย คณะวิทยาศาสตร์การแพทย์ มพ.</span>
        <?php if ($type === 'researcher' && !empty($extra_info['researcher'])): ?>
            <a href="<?php echo htmlspecialchars($system_url . '/profile.php?id=' . (int)$extra_info['researcher']['id']); ?>" target="_blank">
                ดูผลงานทั้งหมด <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem;"></i>
            </a>
        <?php else: ?>
            <a href="<?php echo htmlspecialchars($system_url . '/publications_search.php'); ?>" target="_blank">
                ค้นหาผลงานทั้งหมด <i class="fa-solid fa-arrow-right" style="font-size: 0.65rem;"></i>
            </a>
        <?php endif; ?>
    </div>
</div>

<script>
let lastSentHeight = 0;
function notifyParentHeight() {
    try {
        const container = document.querySelector('.widget-container') || document.body;
        if (!container) return;
        const height = Math.ceil(container.getBoundingClientRect().height);
        if (height > 40 && Math.abs(height - lastSentHeight) >= 2) {
            lastSentHeight = height;
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type: 'msc-widget-resize',
                    height: height
                }, '*');
            }
        }
    } catch (e) {
    }
}

window.addEventListener('load', () => {
    notifyParentHeight();
    setTimeout(notifyParentHeight, 200);
});

if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(() => notifyParentHeight());
}

if ('ResizeObserver' in window) {
    const target = document.querySelector('.widget-container') || document.body;
    const ro = new ResizeObserver(() => notifyParentHeight());
    ro.observe(target);
}
</script>

</body>
</html>
