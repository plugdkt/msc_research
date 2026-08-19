<?php
// admin/index.php
// Admin Control Panel home page

$current_page = 'admin_dashboard';
$page_title = 'แผงควบคุมระบบจัดการ - Admin Panel';

require_once __DIR__ . '/admin_header.php';
require_once __DIR__ . '/../includes/functions.php';

// Fetch statistics
$total_pubs = get_total_publications($pdo);
$total_researchers = get_total_researchers($pdo);
$total_citations = get_total_citations($pdo);

// Fetch researcher details
$researchers = get_all_researchers($pdo);
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>ยินดีต้อนรับเข้าสู่ระบบจัดการหลังบ้าน</h2>
    <p>ผู้ดูแลระบบสามารถจัดการข้อมูลนักวิจัย นำเข้าผลงานตีพิมพ์แบบแมนนวล หรือสั่งอัปเดตซิงค์ข้อมูลผ่าน API ได้จากหน้าต่างนี้</p>
</div>

<!-- Admin Quick Actions Grid -->
<div class="stats-grid animate-fade-in" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-bottom: 40px; animation-delay: 0.1s;">
    <a href="researchers.php" class="glass-panel stat-card" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 30px;">
        <i class="fa-solid fa-user-gear" style="font-size: 3rem; color: var(--color-primary); margin-bottom: 15px;"></i>
        <h3 style="margin-bottom: 8px;">จัดการนักวิจัย</h3>
        <p style="font-size: 0.85rem; color: var(--color-text-muted);">เพิ่ม ลบ หรือแก้ไขรหัส ORCID / Scopus / Scholar ของบุคลากร</p>
    </a>
    
    <a href="import.php" class="glass-panel stat-card" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 30px;">
        <i class="fa-solid fa-file-import" style="font-size: 3rem; color: var(--color-accent); margin-bottom: 15px;"></i>
        <h3 style="margin-bottom: 8px;">นำเข้าผลงานแบบมือ</h3>
        <p style="font-size: 0.85rem; color: var(--color-text-muted);">อัปโหลดไฟล์ Excel/CSV หรือกรอกข้อมูลผลงานวิจัยด้วยตนเอง</p>
    </a>
    
    <a href="sync.php" class="glass-panel stat-card" style="text-decoration: none; color: inherit; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 30px;">
        <i class="fa-solid fa-rotate" style="font-size: 3rem; color: var(--color-success); margin-bottom: 15px;"></i>
        <h3 style="margin-bottom: 8px;">ซิงค์ข้อมูล API</h3>
        <p style="font-size: 0.85rem; color: var(--color-text-muted);">เรียกเชื่อมต่อดึงข้อมูลล่าสุดจาก ORCID, PubMed และ Scopus</p>
    </a>
</div>

<!-- Database Stats & Researchers List -->
<div class="main-grid animate-fade-in" style="animation-delay: 0.2s;">
    <!-- Left column: DB Stats -->
    <div class="sidebar glass-panel" style="position: static; padding: 24px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px;">
            <i class="fa-solid fa-database" style="color: var(--color-primary);"></i> สถานะฐานข้อมูล
        </h3>
        
        <div style="display: flex; flex-direction: column; gap: 15px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="color: var(--color-text-muted);">นักวิจัยทั้งหมด:</span>
                <strong style="font-family: var(--font-eng);"><?php echo $total_researchers; ?> คน</strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="color: var(--color-text-muted);">ผลงานทั้งหมด:</span>
                <strong style="font-family: var(--font-eng);"><?php echo $total_pubs; ?> รายการ</strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="color: var(--color-text-muted);">ยอดการอ้างอิงรวม:</span>
                <strong style="color: var(--color-accent); font-family: var(--font-eng);"><?php echo $total_citations; ?> ครั้ง</strong>
            </div>
        </div>
    </div>

    <!-- Right column: List of researchers and their integration statuses -->
    <div class="glass-panel" style="padding: 24px;">
        <h3 style="margin-bottom: 20px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px;">
            <i class="fa-solid fa-users" style="color: var(--color-accent);"></i> สถานะการเชื่อมต่อรหัสนักวิจัย
        </h3>
        
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-glass); color: var(--color-text-muted);">
                        <th style="padding: 12px 8px;">ชื่อ-นามสกุล</th>
                        <th style="padding: 12px 8px;">ภาควิชา</th>
                        <th style="padding: 12px 8px; text-align: center;">ORCID</th>
                        <th style="padding: 12px 8px; text-align: center;">Scopus</th>
                        <th style="padding: 12px 8px; text-align: center;">Scholar</th>
                        <th style="padding: 12px 8px; text-align: center;">จำนวนผลงาน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($researchers)): ?>
                        <tr>
                            <td colspan="6" style="padding: 20px; text-align: center; color: var(--color-text-muted);">
                                ยังไม่มีข้อมูลนักวิจัยในระบบ
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($researchers as $r): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                                <td style="padding: 12px 8px; font-weight: 500;">
                                    <?php echo htmlspecialchars(($r['title_th'] ?? '') . ' ' . $r['first_name_th'] . ' ' . $r['last_name_th']); ?>
                                </td>
                                <td style="padding: 12px 8px; color: var(--color-text-muted);">
                                    <?php echo htmlspecialchars($r['department']); ?>
                                </td>
                                <td style="padding: 12px 8px; text-align: center;">
                                    <?php if (!empty($r['orcid_id'])): ?>
                                        <i class="fa-solid fa-circle-check" style="color: var(--color-success);" title="<?php echo htmlspecialchars($r['orcid_id']); ?>"></i>
                                    <?php else: ?>
                                        <i class="fa-solid fa-circle-xmark" style="color: rgba(255,255,255,0.1);"></i>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px; text-align: center;">
                                    <?php if (!empty($r['scopus_author_id'])): ?>
                                        <i class="fa-solid fa-circle-check" style="color: var(--color-success);" title="<?php echo htmlspecialchars($r['scopus_author_id']); ?>"></i>
                                    <?php else: ?>
                                        <i class="fa-solid fa-circle-xmark" style="color: rgba(255,255,255,0.1);"></i>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px; text-align: center;">
                                    <?php if (!empty($r['google_scholar_id'])): ?>
                                        <i class="fa-solid fa-circle-check" style="color: var(--color-success);" title="<?php echo htmlspecialchars($r['google_scholar_id']); ?>"></i>
                                    <?php else: ?>
                                        <i class="fa-solid fa-circle-xmark" style="color: rgba(255,255,255,0.1);"></i>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 12px 8px; text-align: center; font-weight: 600; font-family: var(--font-eng);">
                                    <?php echo $r['pub_count']; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
