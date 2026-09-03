<?php
// grants.php - Grant Output & ROI Tracker (ระบบติดตามผลผลิตรายโครงการวิจัย)
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$current_page = 'grants';
$page_title = 'ติดตามผลผลิตโครงการวิจัย (Grant Output Tracker)';

// Filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_sponsor = isset($_GET['sponsor']) ? trim($_GET['sponsor']) : '';
$selected_quartile = isset($_GET['quartile']) ? trim($_GET['quartile']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'pubs_desc';

// 1. Overall Metrics across all grants
$total_grants_count = (int)$pdo->query("
    SELECT COUNT(DISTINCT funding_no) 
    FROM publications 
    WHERE funding_no IS NOT NULL AND TRIM(funding_no) != ''
")->fetchColumn();

$funded_pubs_count = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM publications 
    WHERE funding_sponsor IS NOT NULL AND TRIM(funding_sponsor) != ''
")->fetchColumn();

$total_pubs_count = (int)$pdo->query("SELECT COUNT(*) FROM publications")->fetchColumn();
$funded_pct = $total_pubs_count > 0 ? round($funded_pubs_count / $total_pubs_count * 100, 1) : 0;

$funded_metrics = $pdo->query("
    SELECT 
        SUM(citation_count) as total_cits,
        AVG(CASE WHEN rcr IS NOT NULL THEN rcr ELSE NULL END) as avg_rcr,
        SUM(CASE WHEN quartile IN ('Q1', 'Q2') THEN 1 ELSE 0 END) as q1_q2_count,
        COUNT(*) as total_with_grant
    FROM publications 
    WHERE funding_no IS NOT NULL AND TRIM(funding_no) != ''
")->fetch(PDO::FETCH_ASSOC);

$total_grant_cits = (int)($funded_metrics['total_cits'] ?? 0);
$avg_grant_rcr = round((float)($funded_metrics['avg_rcr'] ?? 0), 2);
$grant_q1_q2_pct = !empty($funded_metrics['total_with_grant']) 
    ? round($funded_metrics['q1_q2_count'] / $funded_metrics['total_with_grant'] * 100, 1) 
    : 0;

// 2. Fetch distinct sponsors for dropdown filter
$sponsors_stmt = $pdo->query("
    SELECT DISTINCT funding_sponsor 
    FROM publications 
    WHERE funding_sponsor IS NOT NULL AND TRIM(funding_sponsor) != '' 
    ORDER BY funding_sponsor ASC
");
$sponsors_list = $sponsors_stmt->fetchAll(PDO::FETCH_COLUMN);

// 3. Main Grants Query
$where_clauses = ["p.funding_no IS NOT NULL AND TRIM(p.funding_no) != ''"];
$params = [];

if ($search !== '') {
    $where_clauses[] = "(
        p.funding_no LIKE :s1 
        OR p.funding_sponsor LIKE :s2 
        OR p.title LIKE :s3
        OR r.first_name_th LIKE :s4
        OR r.last_name_th LIKE :s5
        OR r.first_name_en LIKE :s6
        OR r.last_name_en LIKE :s7
    )";
    $search_param = '%' . $search . '%';
    $params['s1'] = $search_param;
    $params['s2'] = $search_param;
    $params['s3'] = $search_param;
    $params['s4'] = $search_param;
    $params['s5'] = $search_param;
    $params['s6'] = $search_param;
    $params['s7'] = $search_param;
}

if ($selected_sponsor !== '') {
    $where_clauses[] = "p.funding_sponsor = :sponsor";
    $params['sponsor'] = $selected_sponsor;
}

if ($selected_quartile !== '') {
    if ($selected_quartile === 'Q1') {
        $where_clauses[] = "p.quartile = 'Q1'";
    } elseif ($selected_quartile === 'Q2') {
        $where_clauses[] = "p.quartile = 'Q2'";
    } elseif ($selected_quartile === 'Q1_Q2') {
        $where_clauses[] = "p.quartile IN ('Q1', 'Q2')";
    }
}

$where_sql = implode(' AND ', $where_clauses);

// Sorting
$order_by = "pub_count DESC, total_citations DESC";
if ($sort === 'cits_desc') {
    $order_by = "total_citations DESC, pub_count DESC";
} elseif ($sort === 'rcr_desc') {
    $order_by = "avg_rcr DESC, pub_count DESC";
} elseif ($sort === 'no_asc') {
    $order_by = "p.funding_no ASC";
}

$grants_sql = "
    SELECT 
        p.funding_no,
        p.funding_sponsor,
        COUNT(DISTINCT p.id) AS pub_count,
        SUM(p.citation_count) AS total_citations,
        ROUND(AVG(CASE WHEN p.rcr IS NOT NULL THEN p.rcr ELSE NULL END), 2) AS avg_rcr,
        SUM(CASE WHEN p.quartile = 'Q1' THEN 1 ELSE 0 END) AS q1_count,
        SUM(CASE WHEN p.quartile = 'Q2' THEN 1 ELSE 0 END) AS q2_count,
        SUM(CASE WHEN p.quartile = 'Q3' THEN 1 ELSE 0 END) AS q3_count,
        SUM(CASE WHEN p.quartile = 'Q4' THEN 1 ELSE 0 END) AS q4_count,
        GROUP_CONCAT(DISTINCT CONCAT(r.title_th, r.first_name_th, ' ', r.last_name_th) SEPARATOR ', ') AS faculty_researchers,
        GROUP_CONCAT(DISTINCT p.id) AS pub_ids_str
    FROM publications p
    LEFT JOIN researcher_publications rp ON p.id = rp.publication_id
    LEFT JOIN researchers r ON rp.researcher_id = r.id
    WHERE $where_sql
    GROUP BY p.funding_no, p.funding_sponsor
    ORDER BY $order_by
";

$stmt = $pdo->prepare($grants_sql);
$stmt->execute($params);
$grants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch publications details for all matched grants to enable instant zero-latency modal drilldowns
$all_pub_ids = [];
foreach ($grants as $g) {
    if (!empty($g['pub_ids_str'])) {
        $ids = explode(',', $g['pub_ids_str']);
        foreach ($ids as $id) {
            $all_pub_ids[(int)$id] = true;
        }
    }
}

$pubs_by_id = [];
if (!empty($all_pub_ids)) {
    $id_list = implode(',', array_keys($all_pub_ids));
    $pubs_query = $pdo->query("
        SELECT 
            p.id, p.title, p.authors, p.journal_name, p.publish_year, p.quartile, 
            p.citation_count, p.rcr, p.doi, p.url, p.funding_no, p.funding_sponsor
        FROM publications p
        WHERE p.id IN ($id_list)
        ORDER BY p.publish_year DESC, p.citation_count DESC
    ");
    while ($p_row = $pubs_query->fetch(PDO::FETCH_ASSOC)) {
        $pubs_by_id[$p_row['id']] = $p_row;
    }
}

include_once __DIR__ . '/includes/header.php';
?>

<!-- Hero / Page Header -->
<div class="hero glass-panel animate-fade-in" style="padding: 30px 24px; margin-bottom: 30px; position: relative; overflow: hidden;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 300px;">
            <div style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; font-weight: 600; color: #10b981; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.25); border-radius: 999px; padding: 4px 12px; margin-bottom: 12px;">
                <i class="fa-solid fa-hand-holding-dollar"></i> Grant Output &amp; ROI Tracker
            </div>
            <h2 style="font-size: 1.8rem; font-weight: 700; margin-bottom: 8px;">
                ระบบติดตามผลผลิตโครงการวิจัย (Grant Outputs)
            </h2>
            <p style="color: var(--color-text-muted); font-size: 0.95rem; margin: 0; line-height: 1.6; max-width: 780px;">
                วิเคราะห์ผลิตภาพทางวิชาการและผลตอบแทนต่อเงินทุน (Research ROI) เจาะลึกรายรหัสสัญญาโครงการวิจัย ตรวจสอบจำนวนผลงานตีพิมพ์ สัดส่วนระดับ Quartile (Q1–Q4) และการอ้างอิงสะสมที่เกิดจากแต่ละทุนวิจัย
            </p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="reports.php?tab=funding" class="btn-premium" style="padding: 10px 18px; text-decoration: none; font-size: 0.85rem; font-weight: 600; background: rgba(255,255,255,0.05); border-color: var(--border-glass); color: var(--color-text-main);">
                <i class="fa-solid fa-chart-pie"></i> สรุปตามแหล่งทุน
            </a>
            <a href="publications_search.php" class="btn-premium" style="padding: 10px 18px; text-decoration: none; font-size: 0.85rem; font-weight: 600; background: linear-gradient(135deg, #10b981, #059669); border-color: rgba(16,185,129,0.4);">
                <i class="fa-solid fa-magnifying-glass"></i> ค้นหาผลงานทั้งหมด
            </a>
        </div>
    </div>
</div>

<!-- Stats Grid: Key Grant Metrics -->
<div class="stats-grid animate-fade-in" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 30px;">
    <!-- 1. Total Tracked Grants -->
    <div class="stat-card glass-panel" style="border-bottom: 4px solid #10b981; padding: 22px 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <span style="font-size: 0.8rem; font-weight: 600; color: var(--color-text-muted);">รหัสทุน/โครงการที่บันทึก</span>
            <i class="fa-solid fa-file-contract" style="color: #10b981; font-size: 1.2rem;"></i>
        </div>
        <div style="font-size: 2.2rem; font-weight: 700; color: #10b981; font-family: var(--font-eng); line-height: 1.1;">
            <?php echo number_format($total_grants_count); ?>
        </div>
        <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 6px;">
            โครงการวิจัยที่มีรหัสสัญญาระบุ
        </div>
    </div>

    <!-- 2. Funded Publications -->
    <div class="stat-card glass-panel" style="border-bottom: 4px solid #3b82f6; padding: 22px 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <span style="font-size: 0.8rem; font-weight: 600; color: var(--color-text-muted);">ผลงานที่ได้รับการสนับสนุนทุน</span>
            <i class="fa-solid fa-book-bookmark" style="color: #3b82f6; font-size: 1.2rem;"></i>
        </div>
        <div style="font-size: 2.2rem; font-weight: 700; color: #3b82f6; font-family: var(--font-eng); line-height: 1.1;">
            <?php echo number_format($funded_pubs_count); ?>
        </div>
        <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 6px;">
            คิดเป็น <strong style="color: #60a5fa;"><?php echo $funded_pct; ?>%</strong> ของผลงานทั้งคณะ (<?php echo number_format($total_pubs_count); ?> เรื่อง)
        </div>
    </div>

    <!-- 3. High Quality Tier (Q1 & Q2) -->
    <div class="stat-card glass-panel" style="border-bottom: 4px solid #8b5cf6; padding: 22px 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <span style="font-size: 0.8rem; font-weight: 600; color: var(--color-text-muted);">สัดส่วนผลงาน Q1 &amp; Q2</span>
            <i class="fa-solid fa-award" style="color: #8b5cf6; font-size: 1.2rem;"></i>
        </div>
        <div style="font-size: 2.2rem; font-weight: 700; color: #8b5cf6; font-family: var(--font-eng); line-height: 1.1;">
            <?php echo $grant_q1_q2_pct; ?>%
        </div>
        <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 6px;">
            ผลงานจากทุนวิจัยจัดอยู่ใน Tier สูงสุด
        </div>
    </div>

    <!-- 4. Total Citations from Grants -->
    <div class="stat-card glass-panel" style="border-bottom: 4px solid #f59e0b; padding: 22px 18px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <span style="font-size: 0.8rem; font-weight: 600; color: var(--color-text-muted);">การอ้างอิงสะสมจากทุน</span>
            <i class="fa-solid fa-quote-right" style="color: #f59e0b; font-size: 1.2rem;"></i>
        </div>
        <div style="font-size: 2.2rem; font-weight: 700; color: #f59e0b; font-family: var(--font-eng); line-height: 1.1;">
            <?php echo number_format($total_grant_cits); ?>
        </div>
        <div style="font-size: 0.72rem; color: var(--color-text-muted); margin-top: 6px;">
            ครั้ง (ค่า RCR เฉลี่ย: <strong style="color: #10b981;"><?php echo $avg_grant_rcr; ?></strong>)
        </div>
    </div>
</div>

<!-- Search & Filter Controls -->
<div class="glass-panel animate-fade-in" style="padding: 24px; margin-bottom: 30px;">
    <form method="GET" action="grants.php" style="display: flex; flex-direction: column; gap: 16px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; align-items: flex-end;">
            <!-- Text Search -->
            <div style="flex: 2; min-width: 250px;">
                <label style="font-size: 0.78rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 6px;">
                    <i class="fa-solid fa-magnifying-glass"></i> ค้นหารหัสทุน / สัญญา / ชื่ออาจารย์ / หน่วยงาน
                </label>
                <div style="position: relative;">
                    <input type="text" name="search" class="search-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="เช่น 2260/2568, FF64, University of Phayao..." style="padding-left: 36px; width: 100%;">
                    <i class="fa-solid fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.85rem;"></i>
                </div>
            </div>

            <!-- Sponsor Select -->
            <div>
                <label style="font-size: 0.78rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 6px;">
                    <i class="fa-solid fa-building-columns"></i> หน่วยงานผู้มอบทุน
                </label>
                <select name="sponsor" class="search-input" style="width: 100%; height: 42px;">
                    <option value="">-- ทั้งหมดทุกแหล่งทุน --</option>
                    <?php foreach ($sponsors_list as $sp): ?>
                        <option value="<?php echo htmlspecialchars($sp); ?>" <?php echo $selected_sponsor === $sp ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($sp); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Quartile Filter -->
            <div>
                <label style="font-size: 0.78rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 6px;">
                    <i class="fa-solid fa-chart-pie"></i> ระดับ Quartile
                </label>
                <select name="quartile" class="search-input" style="width: 100%; height: 42px;">
                    <option value="">-- ทั้งหมดทุกระดับ --</option>
                    <option value="Q1_Q2" <?php echo $selected_quartile === 'Q1_Q2' ? 'selected' : ''; ?>>เฉพาะมีผลงาน Q1 หรือ Q2</option>
                    <option value="Q1"    <?php echo $selected_quartile === 'Q1' ? 'selected' : ''; ?>>เฉพาะมีผลงาน Q1</option>
                    <option value="Q2"    <?php echo $selected_quartile === 'Q2' ? 'selected' : ''; ?>>เฉพาะมีผลงาน Q2</option>
                </select>
            </div>

            <!-- Sort By -->
            <div>
                <label style="font-size: 0.78rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 6px;">
                    <i class="fa-solid fa-arrow-down-wide-short"></i> เรียงลำดับตาม
                </label>
                <select name="sort" class="search-input" style="width: 100%; height: 42px;">
                    <option value="pubs_desc" <?php echo $sort === 'pubs_desc' ? 'selected' : ''; ?>>จำนวนผลงาน (มาก &rarr; น้อย)</option>
                    <option value="cits_desc" <?php echo $sort === 'cits_desc' ? 'selected' : ''; ?>>การอ้างอิง Citations (มาก &rarr; น้อย)</option>
                    <option value="rcr_desc"  <?php echo $sort === 'rcr_desc' ? 'selected' : ''; ?>>ค่า RCR เฉลี่ย (มาก &rarr; น้อย)</option>
                    <option value="no_asc"    <?php echo $sort === 'no_asc' ? 'selected' : ''; ?>>รหัสทุน (A &rarr; Z)</option>
                </select>
            </div>

            <!-- Submit Buttons -->
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn-premium" style="padding: 10px 18px; height: 42px; font-weight: 600; flex: 1; justify-content: center;">
                    <i class="fa-solid fa-filter"></i> กรองข้อมูล
                </button>
                <?php if ($search !== '' || $selected_sponsor !== '' || $selected_quartile !== '' || $sort !== 'pubs_desc'): ?>
                    <a href="grants.php" class="btn-premium" style="padding: 10px 14px; height: 42px; background: rgba(255,255,255,0.06); border-color: var(--border-glass); color: var(--color-text-muted); text-decoration: none; display: inline-flex; align-items: center; justify-content: center;" title="ล้างตัวกรอง">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<!-- Main Grants Results List -->
<div class="glass-panel animate-fade-in" style="padding: 28px;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 14px; margin-bottom: 22px; flex-wrap: wrap; gap: 10px;">
        <h3 style="margin: 0; font-size: 1.15rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-list-check" style="color: #10b981;"></i>
            รายการโครงการวิจัยที่พบ
            <span style="font-size: 0.8rem; font-weight: 500; color: var(--color-text-muted); background: rgba(255,255,255,0.06); border-radius: 999px; padding: 2px 10px; margin-left: 6px;">
                <?php echo count($grants); ?> โครงการ
            </span>
        </h3>
        <span style="font-size: 0.78rem; color: var(--color-text-muted);">
            คลิกที่ปุ่ม <strong>"ดูผลงาน (X ฉบับ)"</strong> เพื่อเปิดดูรายละเอียดบทความวิจัยทั้งหมดของทุนนั้น
        </span>
    </div>

    <?php if (empty($grants)): ?>
        <div style="padding: 60px 20px; text-align: center; color: var(--color-text-muted);">
            <i class="fa-solid fa-folder-open" style="font-size: 3.5rem; color: rgba(255,255,255,0.1); margin-bottom: 16px; display: block;"></i>
            <h4 style="font-size: 1.1rem; color: var(--color-text-main); margin-bottom: 6px;">ไม่พบข้อมูลโครงการวิจัยตามเงื่อนไขที่ระบุ</h4>
            <p style="font-size: 0.85rem; max-width: 420px; margin: 0 auto;">ลองเปลี่ยนคำค้นหา หรือล้างตัวกรองเพื่อค้นหาโครงการวิจัยอื่น</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 14px;">
            <?php foreach ($grants as $index => $g): 
                $pub_ids = !empty($g['pub_ids_str']) ? explode(',', $g['pub_ids_str']) : [];
                $clean_modal_id = 'grant_' . md5($g['funding_no'] . '_' . ($g['funding_sponsor'] ?? ''));
                $is_multi_pub = $g['pub_count'] > 1;
            ?>
                <div class="glass-panel" style="padding: 18px 20px; border-radius: 12px; background: rgba(255,255,255,0.015); border: 1px solid var(--border-glass); transition: var(--transition-smooth); <?php echo $is_multi_pub ? 'border-left: 4px solid #10b981;' : ''; ?>" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background='rgba(255,255,255,0.015)'">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; flex-wrap: wrap;">
                        <!-- Left: Grant identity & Sponsor -->
                        <div style="flex: 2; min-width: 280px;">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px; flex-wrap: wrap;">
                                <span style="font-size: 1.05rem; font-weight: 700; font-family: var(--font-eng); color: #34d399; letter-spacing: 0.3px;">
                                    <i class="fa-solid fa-file-signature" style="color: #10b981; margin-right: 4px;"></i>
                                    <?php echo htmlspecialchars($g['funding_no']); ?>
                                </span>
                                <?php if ($g['pub_count'] >= 3): ?>
                                    <span class="badge" style="background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3); font-size: 0.7rem; font-weight: 600;">
                                        <i class="fa-solid fa-fire"></i> ผลิตภาพสูง (&ge; 3 เรื่อง)
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div style="font-size: 0.88rem; color: var(--color-text-main); font-weight: 500; margin-bottom: 8px;">
                                <i class="fa-solid fa-building-columns" style="font-size: 0.75rem; color: var(--color-text-muted); margin-right: 4px;"></i>
                                <?php echo htmlspecialchars($g['funding_sponsor'] ?: 'ไม่ระบุชื่อแหล่งทุนชัดเจน'); ?>
                            </div>

                            <?php if (!empty($g['faculty_researchers'])): ?>
                                <div style="font-size: 0.8rem; color: var(--color-text-muted); line-height: 1.4;">
                                    <i class="fa-solid fa-users" style="font-size: 0.75rem; margin-right: 4px; color: #a78bfa;"></i>
                                    นักวิจัยในคณะ: <span style="color: var(--color-text-main);"><?php echo htmlspecialchars($g['faculty_researchers']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Center: Quartile Distribution & Metrics -->
                        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                            <!-- Quartile Badges -->
                            <div style="display: flex; gap: 4px; align-items: center;">
                                <?php if ($g['q1_count'] > 0): ?>
                                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 700; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px;">
                                        Q1: <?php echo $g['q1_count']; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($g['q2_count'] > 0): ?>
                                    <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); font-weight: 700; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px;">
                                        Q2: <?php echo $g['q2_count']; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($g['q3_count'] > 0): ?>
                                    <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); font-weight: 700; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px;">
                                        Q3: <?php echo $g['q3_count']; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($g['q4_count'] > 0): ?>
                                    <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); font-weight: 700; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px;">
                                        Q4: <?php echo $g['q4_count']; ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Citations & RCR -->
                            <div style="text-align: right; border-left: 1px solid var(--border-glass); padding-left: 15px;">
                                <div style="font-size: 0.8rem; color: var(--color-text-muted);">
                                    Citations: <strong style="font-family: var(--font-eng); color: #fbbf24;"><?php echo number_format($g['total_citations']); ?></strong>
                                </div>
                                <?php if (!empty($g['avg_rcr'])): ?>
                                    <div style="font-size: 0.75rem; color: var(--color-text-muted); margin-top: 2px;">
                                        RCR เฉลี่ย: <strong style="font-family: var(--font-eng); color: #10b981;"><?php echo $g['avg_rcr']; ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Right: Action Button -->
                        <div style="align-self: center;">
                            <button type="button" onclick="openGrantModal('<?php echo $clean_modal_id; ?>')" class="btn-premium" style="padding: 9px 16px; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; background: <?php echo $is_multi_pub ? 'linear-gradient(135deg, #10b981, #059669)' : 'rgba(255,255,255,0.06)'; ?>; border-color: <?php echo $is_multi_pub ? 'rgba(16,185,129,0.4)' : 'var(--border-glass)'; ?>;">
                                <i class="fa-solid fa-book-open"></i> ดูผลงาน (<?php echo $g['pub_count']; ?> ฉบับ)
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Modal Window for this grant's publications -->
                <div id="modal-<?php echo $clean_modal_id; ?>" class="modal-overlay" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(8px); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
                    <div class="glass-panel" style="background: #0f172a; border: 1px solid var(--border-glass); border-radius: 18px; width: 100%; max-width: 860px; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 60px rgba(0,0,0,0.6); animation: slideUp 0.3s ease;">
                        
                        <!-- Modal Header -->
                        <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-glass); display: flex; justify-content: space-between; align-items: center; gap: 15px; background: rgba(255,255,255,0.02);">
                            <div>
                                <div style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.75rem; font-weight: 600; color: #10b981; background: rgba(16,185,129,0.1); padding: 2px 8px; border-radius: 4px; margin-bottom: 4px;">
                                    <i class="fa-solid fa-file-contract"></i> รหัสสัญญาโครงการวิจัย
                                </div>
                                <h3 style="margin: 0; font-size: 1.3rem; font-weight: 700; color: #34d399; font-family: var(--font-eng);">
                                    <?php echo htmlspecialchars($g['funding_no']); ?>
                                </h3>
                                <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-top: 4px;">
                                    ผู้มอบทุน: <strong style="color: var(--color-text-main);"><?php echo htmlspecialchars($g['funding_sponsor'] ?: 'ไม่ระบุแหล่งทุน'); ?></strong>
                                    &bull; ผลงานทั้งหมด: <strong style="color: #10b981; font-family: var(--font-eng);"><?php echo $g['pub_count']; ?></strong> ฉบับ
                                    &bull; Citations รวม: <strong style="color: #fbbf24; font-family: var(--font-eng);"><?php echo number_format($g['total_citations']); ?></strong> ครั้ง
                                </div>
                            </div>
                            <button type="button" onclick="closeGrantModal('<?php echo $clean_modal_id; ?>')" style="background: none; border: none; font-size: 1.6rem; color: var(--color-text-muted); cursor: pointer; padding: 4px 8px; line-height: 1;" title="ปิดหน้าต่าง">
                                &times;
                            </button>
                        </div>

                        <!-- Modal Body (Publication Cards) -->
                        <div style="padding: 20px 24px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; flex: 1;">
                            <?php foreach ($pub_ids as $p_id): 
                                if (!isset($pubs_by_id[(int)$p_id])) continue;
                                $p = $pubs_by_id[(int)$p_id];
                            ?>
                                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 10px; padding: 16px; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.04)'" onmouseout="this.style.background='rgba(255,255,255,0.02)'">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 8px;">
                                        <h4 style="margin: 0; font-size: 0.95rem; font-weight: 600; line-height: 1.4; flex: 1;">
                                            <?php if (!empty($p['url'])): ?>
                                                <a href="<?php echo htmlspecialchars($p['url']); ?>" target="_blank" style="color: inherit; text-decoration: none;" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                                    <?php echo htmlspecialchars($p['title']); ?>
                                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.75rem; margin-left: 4px; opacity: 0.7;"></i>
                                                </a>
                                            <?php elseif (!empty($p['doi'])): ?>
                                                <a href="https://doi.org/<?php echo htmlspecialchars($p['doi']); ?>" target="_blank" style="color: inherit; text-decoration: none;" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                                    <?php echo htmlspecialchars($p['title']); ?>
                                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.75rem; margin-left: 4px; opacity: 0.7;"></i>
                                                </a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($p['title']); ?>
                                            <?php endif; ?>
                                        </h4>

                                        <!-- Quartile Badge -->
                                        <?php if (!empty($p['quartile'])): 
                                            $q_bg = 'rgba(107,114,128,0.15)';
                                            $q_color = '#9ca3af';
                                            if ($p['quartile'] === 'Q1') { $q_bg = 'rgba(16,185,129,0.15)'; $q_color = '#10b981'; }
                                            elseif ($p['quartile'] === 'Q2') { $q_bg = 'rgba(59,130,246,0.15)'; $q_color = '#3b82f6'; }
                                            elseif ($p['quartile'] === 'Q3') { $q_bg = 'rgba(245,158,11,0.15)'; $q_color = '#f59e0b'; }
                                            elseif ($p['quartile'] === 'Q4') { $q_bg = 'rgba(239,68,68,0.15)'; $q_color = '#ef4444'; }
                                        ?>
                                            <span class="badge" style="background: <?php echo $q_bg; ?>; color: <?php echo $q_color; ?>; border: 1px solid <?php echo $q_color; ?>44; font-weight: 700; font-size: 0.75rem; padding: 2px 7px; border-radius: 4px; white-space: nowrap;">
                                                <?php echo $p['quartile']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div style="font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 8px; line-height: 1.4;">
                                        <i class="fa-solid fa-pen-nib" style="font-size: 0.7rem; margin-right: 4px;"></i>
                                        <?php echo htmlspecialchars($p['authors']); ?>
                                    </div>

                                    <div style="display: flex; gap: 12px; align-items: center; font-size: 0.78rem; color: var(--color-text-muted); flex-wrap: wrap;">
                                        <span><i class="fa-solid fa-book" style="color: var(--color-secondary); margin-right: 3px;"></i> <?php echo htmlspecialchars($p['journal_name'] ?: 'N/A'); ?></span>
                                        <span>&bull;</span>
                                        <span>ปีที่พิมพ์: <strong style="color: var(--color-text-main); font-family: var(--font-eng);"><?php echo $p['publish_year']; ?></strong></span>
                                        <?php if ($p['citation_count'] > 0): ?>
                                            <span>&bull;</span>
                                            <span style="color: #fbbf24;"><i class="fa-solid fa-quote-right" style="margin-right: 3px;"></i> Cited: <strong><?php echo $p['citation_count']; ?></strong></span>
                                        <?php endif; ?>
                                        <?php if (!empty($p['rcr'])): ?>
                                            <span>&bull;</span>
                                            <span style="color: #10b981;"><i class="fa-solid fa-chart-line" style="margin-right: 3px;"></i> RCR: <strong><?php echo $p['rcr']; ?></strong></span>
                                        <?php endif; ?>
                                        <?php if (!empty($p['doi'])): ?>
                                            <span style="margin-left: auto;">
                                                <a href="https://doi.org/<?php echo htmlspecialchars($p['doi']); ?>" target="_blank" style="color: var(--color-primary); text-decoration: none; font-family: var(--font-eng);">
                                                    DOI: <?php echo htmlspecialchars($p['doi']); ?>
                                                </a>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Modal Footer -->
                        <div style="padding: 14px 24px; border-top: 1px solid var(--border-glass); background: rgba(0,0,0,0.2); display: flex; justify-content: flex-end;">
                            <button type="button" onclick="closeGrantModal('<?php echo $clean_modal_id; ?>')" class="btn-premium" style="padding: 8px 18px; font-size: 0.85rem; font-weight: 600; background: rgba(255,255,255,0.06); border-color: var(--border-glass); color: var(--color-text-main);">
                                ปิดหน้าต่าง
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function openGrantModal(id) {
    const m = document.getElementById('modal-' + id);
    if (m) {
        m.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeGrantModal(id) {
    const m = document.getElementById('modal-' + id);
    if (m) {
        m.style.display = 'none';
        document.body.style.overflow = '';
    }
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.style.display = 'none';
        });
        document.body.style.overflow = '';
    }
});

// Auto-open modal if specified in URL query ?no=...
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const grantNo = urlParams.get('no');
    if (grantNo) {
        // Find modal whose header has this grant no
        document.querySelectorAll('.modal-overlay').forEach(m => {
            if (m.innerText.includes(grantNo)) {
                m.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
