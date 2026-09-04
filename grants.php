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
        OR EXISTS (
            SELECT 1 FROM researcher_publications rp_s
            JOIN researchers r_s ON rp_s.researcher_id = r_s.id
            WHERE rp_s.publication_id = p.id AND (
                r_s.first_name_th LIKE :s4
                OR r_s.last_name_th LIKE :s5
                OR r_s.first_name_en LIKE :s6
                OR r_s.last_name_en LIKE :s7
            )
        )
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
        GROUP_CONCAT(DISTINCT NULLIF(TRIM(p.funding_sponsor), '') SEPARATOR ', ') AS funding_sponsor,
        COUNT(p.id) AS pub_count,
        SUM(p.citation_count) AS total_citations,
        ROUND(AVG(CASE WHEN p.rcr IS NOT NULL THEN p.rcr ELSE NULL END), 2) AS avg_rcr,
        SUM(CASE WHEN p.quartile = 'Q1' THEN 1 ELSE 0 END) AS q1_count,
        SUM(CASE WHEN p.quartile = 'Q2' THEN 1 ELSE 0 END) AS q2_count,
        SUM(CASE WHEN p.quartile = 'Q3' THEN 1 ELSE 0 END) AS q3_count,
        SUM(CASE WHEN p.quartile = 'Q4' THEN 1 ELSE 0 END) AS q4_count,
        GROUP_CONCAT(p.id) AS pub_ids_str
    FROM publications p
    WHERE $where_sql
    GROUP BY p.funding_no
    ORDER BY $order_by
";

$stmt = $pdo->prepare($grants_sql);
$stmt->execute($params);
$grants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pre-fetch publications details and faculty researchers for all matched grants
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
$pub_researchers = [];
if (!empty($all_pub_ids)) {
    $id_list = implode(',', array_keys($all_pub_ids));
    $pubs_query = $pdo->query("
        SELECT 
            p.id, p.title, p.authors, p.journal_name, p.publish_year, p.quartile, 
            p.citation_count, p.rcr, p.doi, p.url, p.funding_no, p.funding_sponsor,
            p.corresponding_author_id
        FROM publications p
        WHERE p.id IN ($id_list)
        ORDER BY p.publish_year DESC, p.citation_count DESC
    ");
    while ($p_row = $pubs_query->fetch(PDO::FETCH_ASSOC)) {
        $pubs_by_id[$p_row['id']] = $p_row;
    }

    $r_query = $pdo->query("
        SELECT 
            rp.publication_id, 
            r.id AS researcher_id,
            r.first_name_en,
            r.last_name_en,
            TRIM(CONCAT(COALESCE(r.title_th, ''), ' ', r.first_name_th, ' ', r.last_name_th)) AS fullname
        FROM researcher_publications rp
        JOIN researchers r ON rp.researcher_id = r.id
        WHERE rp.publication_id IN ($id_list)
    ");
    while ($r_row = $r_query->fetch(PDO::FETCH_ASSOC)) {
        $pub_researchers[$r_row['publication_id']][] = [
            'id' => (int)$r_row['researcher_id'],
            'fullname' => $r_row['fullname'],
            'first_name_en' => $r_row['first_name_en'],
            'last_name_en' => $r_row['last_name_en']
        ];
    }
}

include_once __DIR__ . '/includes/header.php';
?>

<!-- Hero / Page Header -->
<div class="hero glass-panel animate-fade-in" style="padding: 36px 24px; margin-bottom: 30px; position: relative; overflow: hidden; text-align: center;">
    <div style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 600; color: #10b981; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.25); border-radius: 999px; padding: 5px 16px; margin-bottom: 14px;">
        <i class="fa-solid fa-hand-holding-dollar"></i> Grant Output &amp; ROI Tracker
    </div>
    <h2 style="font-size: 1.9rem; font-weight: 700; margin-bottom: 12px; color: var(--color-text-main);">
        ระบบติดตามผลผลิตโครงการวิจัย (Grant Outputs)
    </h2>
    <p style="color: var(--color-text-muted); font-size: 0.96rem; margin: 0 auto; line-height: 1.7; max-width: 820px;">
        วิเคราะห์ผลิตภาพทางวิชาการและผลตอบแทนต่อเงินทุน (Research ROI) เจาะลึกรายรหัสสัญญาโครงการวิจัย ตรวจสอบจำนวนผลงานตีพิมพ์ สัดส่วนระดับ Quartile (Q1–Q4) และการอ้างอิงสะสมที่เกิดจากแต่ละทุนวิจัย
    </p>
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
        <!-- Top Row: Search Bar & Actions -->
        <div>
            <label style="font-size: 0.82rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 8px;">
                <i class="fa-solid fa-magnifying-glass" style="color: var(--color-primary);"></i> ค้นหารหัสทุน / สัญญา / ชื่ออาจารย์ / หน่วยงานผู้มอบทุน
            </label>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <div style="position: relative; flex: 1; min-width: 260px;">
                    <input type="text" name="search" class="search-input" value="<?php echo htmlspecialchars($search); ?>" placeholder="พิมพ์ค้นหา เช่น 2260/2568, FF64, University of Phayao, ชื่อผู้วิจัย..." style="padding-left: 38px; width: 100%; height: 44px; font-size: 0.92rem;">
                    <i class="fa-solid fa-search" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); font-size: 0.9rem;"></i>
                    <?php if ($search !== ''): ?>
                        <a href="grants.php?<?php echo http_build_query(array_merge($_GET, ['search' => ''])); ?>" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); text-decoration: none;" title="ล้างคำค้นหา">
                            <i class="fa-solid fa-circle-xmark"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn-premium" style="padding: 0 24px; height: 44px; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; white-space: nowrap;">
                    <i class="fa-solid fa-filter"></i> กรองข้อมูล
                </button>
                <?php if ($search !== '' || $selected_sponsor !== '' || $selected_quartile !== '' || $sort !== 'pubs_desc'): ?>
                    <a href="grants.php" class="btn-premium" style="padding: 0 16px; height: 44px; background: rgba(255,255,255,0.06); border-color: var(--border-glass); color: var(--color-text-muted); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;" title="ล้างตัวกรองทั้งหมด">
                        <i class="fa-solid fa-rotate-left"></i> ล้างตัวกรอง
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bottom Row: Dropdown Filters -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; padding-top: 14px; border-top: 1px dashed rgba(255,255,255,0.1);">
            <!-- Sponsor Select -->
            <div>
                <label style="font-size: 0.78rem; font-weight: 600; color: var(--color-text-muted); display: block; margin-bottom: 6px;">
                    <i class="fa-solid fa-building-columns"></i> หน่วยงานผู้มอบทุน
                </label>
                <select name="sponsor" class="search-input" style="width: 100%; height: 42px; cursor: pointer;" onchange="this.form.submit()">
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
                <select name="quartile" class="search-input" style="width: 100%; height: 42px; cursor: pointer;" onchange="this.form.submit()">
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
                <select name="sort" class="search-input" style="width: 100%; height: 42px; cursor: pointer;" onchange="this.form.submit()">
                    <option value="pubs_desc" <?php echo $sort === 'pubs_desc' ? 'selected' : ''; ?>>จำนวนผลงาน (มาก &rarr; น้อย)</option>
                    <option value="cits_desc" <?php echo $sort === 'cits_desc' ? 'selected' : ''; ?>>การอ้างอิง Citations (มาก &rarr; น้อย)</option>
                    <option value="rcr_desc"  <?php echo $sort === 'rcr_desc' ? 'selected' : ''; ?>>ค่า RCR เฉลี่ย (มาก &rarr; น้อย)</option>
                    <option value="no_asc"    <?php echo $sort === 'no_asc' ? 'selected' : ''; ?>>รหัสทุน (A &rarr; Z)</option>
                </select>
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
            คลิกที่ปุ่ม <strong>"ดูผลงาน (X ฉบับ)"</strong> เพื่อขยายดูรายละเอียดบทความวิจัยทั้งหมดของทุนนั้น
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
                $clean_drawer_id = 'grant_' . md5($g['funding_no'] . '_' . ($g['funding_sponsor'] ?? ''));
                $is_multi_pub = $g['pub_count'] > 1;

                $grant_researchers = [];
                foreach ($pub_ids as $p_id) {
                    if (isset($pub_researchers[(int)$p_id])) {
                        foreach ($pub_researchers[(int)$p_id] as $r_item) {
                            $r_name = trim($r_item['fullname'] ?? '');
                            if ($r_name === '') {
                                $r_name = trim(($r_item['first_name_en'] ?? '') . ' ' . ($r_item['last_name_en'] ?? ''));
                            }
                            if ($r_name !== '') {
                                $grant_researchers[$r_name] = true;
                            }
                        }
                    }
                }
                $faculty_researchers = implode(', ', array_keys($grant_researchers));
            ?>
                <div class="glass-panel" id="grant-card-<?php echo $clean_drawer_id; ?>" style="padding: 18px 20px; border-radius: 12px; background: rgba(255,255,255,0.015); border: 1px solid var(--border-glass); transition: var(--transition-smooth); <?php echo $is_multi_pub ? 'border-left: 4px solid #10b981;' : ''; ?>" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background='rgba(255,255,255,0.015)'">
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

                            <div style="margin-bottom: 8px;">
                                <?php 
                                $parsed_sp = parse_grant_sponsors($g['funding_sponsor']);
                                echo render_grant_sponsors_html($parsed_sp); 
                                ?>
                            </div>

                            <?php if (!empty($faculty_researchers)): ?>
                                <div style="font-size: 0.8rem; color: var(--color-text-muted); line-height: 1.4;">
                                    <i class="fa-solid fa-users" style="font-size: 0.75rem; margin-right: 4px; color: #a78bfa;"></i>
                                    นักวิจัยในคณะ: <span style="color: var(--color-text-main);"><?php echo htmlspecialchars($faculty_researchers); ?></span>
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
                            <button type="button" onclick="toggleGrantDrawer('<?php echo $clean_drawer_id; ?>')" id="btn-toggle-<?php echo $clean_drawer_id; ?>" class="btn-premium" style="padding: 9px 16px; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; background: <?php echo $is_multi_pub ? 'linear-gradient(135deg, #10b981, #059669)' : 'rgba(255,255,255,0.06)'; ?>; border-color: <?php echo $is_multi_pub ? 'rgba(16,185,129,0.4)' : 'var(--border-glass)'; ?>; cursor: pointer;">
                                <i class="fa-solid fa-chevron-down" id="icon-<?php echo $clean_drawer_id; ?>"></i>
                                <span id="text-<?php echo $clean_drawer_id; ?>">ดูผลงาน (<?php echo $g['pub_count']; ?> ฉบับ)</span>
                            </button>
                        </div>
                    </div>

                    <!-- In-Place Publications List Drawer (Expands smoothly on click) -->
                    <div id="drawer-<?php echo $clean_drawer_id; ?>" class="grant-drawer" style="display: none; margin-top: 20px; border-top: 1px dashed rgba(255,255,255,0.12); padding-top: 18px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                            <div style="font-size: 0.88rem; font-weight: 600; color: #10b981; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-book-open"></i>
                                รายการผลงานตีพิมพ์ที่ได้รับทุนสนับสนุนนี้ (<?php echo $g['pub_count']; ?> ฉบับ)
                            </div>
                            <div style="font-size: 0.75rem; color: var(--color-text-muted);">
                                รหัสทุน: <strong style="color: #34d399; font-family: var(--font-eng);"><?php echo htmlspecialchars($g['funding_no']); ?></strong>
                                &bull; Citations รวม: <strong style="color: #fbbf24; font-family: var(--font-eng);"><?php echo number_format($g['total_citations']); ?></strong> ครั้ง
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($pub_ids as $p_id): 
                                if (!isset($pubs_by_id[(int)$p_id])) continue;
                                $p = $pubs_by_id[(int)$p_id];
                            ?>
                                <div style="background: rgba(255,255,255,0.025); border: 1px solid var(--border-glass); border-radius: 10px; padding: 14px 16px; transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="this.style.background='rgba(255,255,255,0.025)'">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 6px;">
                                        <h4 style="margin: 0; font-size: 0.92rem; font-weight: 600; line-height: 1.45; flex: 1;">
                                            <?php if (!empty($p['url'])): ?>
                                                <a href="<?php echo htmlspecialchars($p['url']); ?>" target="_blank" style="color: inherit; text-decoration: none; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                                    <?php echo htmlspecialchars($p['title']); ?>
                                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.72rem; margin-left: 4px; opacity: 0.7;"></i>
                                                </a>
                                            <?php elseif (!empty($p['doi'])): ?>
                                                <a href="https://doi.org/<?php echo htmlspecialchars($p['doi']); ?>" target="_blank" style="color: inherit; text-decoration: none; transition: var(--transition-smooth);" onmouseover="this.style.color='var(--color-primary)'" onmouseout="this.style.color='inherit'">
                                                    <?php echo htmlspecialchars($p['title']); ?>
                                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 0.72rem; margin-left: 4px; opacity: 0.7;"></i>
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
                                            <span class="badge" style="background: <?php echo $q_bg; ?>; color: <?php echo $q_color; ?>; border: 1px solid <?php echo $q_color; ?>44; font-weight: 700; font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; white-space: nowrap;">
                                                <?php echo $p['quartile']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div style="font-size: 0.78rem; color: var(--color-text-muted); margin-bottom: 6px; line-height: 1.4;">
                                        <i class="fa-solid fa-pen-nib" style="font-size: 0.7rem; margin-right: 4px;"></i>
                                        <?php echo htmlspecialchars($p['authors']); ?>
                                    </div>

                                    <?php 
                                    // Faculty Researchers and their Author Roles on this publication
                                    $p_res_list = $pub_researchers[(int)$p_id] ?? [];
                                    if (!empty($p_res_list)): 
                                        $parsed_roles = [];
                                        foreach ($p_res_list as $r_info) {
                                            $base_role = determine_author_role($p['authors'], $r_info['first_name_en'], $r_info['last_name_en']);
                                            $is_corr = (!empty($p['corresponding_author_id']) && (int)$p['corresponding_author_id'] === (int)$r_info['id']);
                                            
                                            if ($base_role === 'First Author' && $is_corr) {
                                                $role_label = 'First & Corresponding Author';
                                                $sort_weight = 1;
                                            } elseif ($base_role === 'First Author') {
                                                $role_label = 'First Author';
                                                $sort_weight = 2;
                                            } elseif ($is_corr) {
                                                $role_label = 'Corresponding Author';
                                                $sort_weight = 3;
                                            } else {
                                                $role_label = 'Co-Author';
                                                $sort_weight = 4;
                                            }
                                            
                                            $parsed_roles[] = [
                                                'fullname' => $r_info['fullname'],
                                                'role' => $role_label,
                                                'weight' => $sort_weight
                                            ];
                                        }
                                        
                                        usort($parsed_roles, function($a, $b) {
                                            return $a['weight'] <=> $b['weight'];
                                        });
                                    ?>
                                        <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; align-items: center;">
                                            <span style="font-size: 0.72rem; color: var(--color-text-muted); margin-right: 2px;">
                                                <i class="fa-solid fa-user-check" style="color: #a78bfa;"></i> นักวิจัยในคณะ:
                                            </span>
                                            <?php foreach ($parsed_roles as $pr): 
                                                $r_name = $pr['fullname'];
                                                $r_role = $pr['role'];
                                                if (strpos($r_role, 'First') !== false) {
                                                    $b_bg = 'rgba(16, 185, 129, 0.15)';
                                                    $b_color = '#10b981';
                                                    $b_border = 'rgba(16, 185, 129, 0.35)';
                                                    $b_icon = 'fa-solid fa-star';
                                                } elseif (strpos($r_role, 'Corresponding') !== false) {
                                                    $b_bg = 'rgba(245, 158, 11, 0.15)';
                                                    $b_color = '#f59e0b';
                                                    $b_border = 'rgba(245, 158, 11, 0.35)';
                                                    $b_icon = 'fa-solid fa-envelope';
                                                } else {
                                                    $b_bg = 'rgba(139, 92, 246, 0.1)';
                                                    $b_color = '#c084fc';
                                                    $b_border = 'rgba(139, 92, 246, 0.25)';
                                                    $b_icon = 'fa-solid fa-user-group';
                                                }
                                            ?>
                                                <span class="badge" style="background: <?php echo $b_bg; ?>; color: var(--color-text-main); border: 1px solid <?php echo $b_border; ?>; font-size: 0.73rem; padding: 2px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;">
                                                    <i class="<?php echo $b_icon; ?>" style="font-size: 0.65rem; color: <?php echo $b_color; ?>;"></i>
                                                    <span style="font-weight: 500;"><?php echo htmlspecialchars($r_name); ?></span>
                                                    <span style="font-size: 0.65rem; font-weight: 700; color: <?php echo $b_color; ?>; text-transform: uppercase; background: rgba(0,0,0,0.2); padding: 1px 5px; border-radius: 4px;">
                                                        <?php echo $r_role; ?>
                                                    </span>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div style="display: flex; gap: 12px; align-items: center; font-size: 0.76rem; color: var(--color-text-muted); flex-wrap: wrap;">
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
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Toggle in-place drawer for publications
function toggleGrantDrawer(id) {
    const drawer = document.getElementById('drawer-' + id);
    const icon = document.getElementById('icon-' + id);
    const btnText = document.getElementById('text-' + id);
    if (!drawer) return;

    if (drawer.style.display === 'none' || !drawer.style.display) {
        drawer.style.display = 'block';
        if (icon) icon.className = 'fa-solid fa-chevron-up';
        if (btnText) btnText.innerText = 'ซ่อนผลงาน';
        
        // Smooth scroll slightly into view if drawer is large
        drawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } else {
        drawer.style.display = 'none';
        if (icon) icon.className = 'fa-solid fa-chevron-down';
        if (btnText) {
            // Restore original text from data or count
            const pubCount = drawer.querySelectorAll('h4').length;
            btnText.innerText = 'ดูผลงาน (' + pubCount + ' ฉบับ)';
        }
    }
}

// Auto-expand drawer if specified in URL query ?no=...
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const grantNo = urlParams.get('no');
    if (grantNo) {
        document.querySelectorAll('.glass-panel').forEach(card => {
            if (card.innerText && card.innerText.includes(grantNo)) {
                const btn = card.querySelector('button[id^="btn-toggle-"]');
                if (btn) btn.click();
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
