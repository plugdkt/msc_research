<?php
// admin/clear_data.php
// Utility to wipe all database records and start fresh

$current_page = 'admin_dashboard';
$page_title = 'ล้างข้อมูลระบบ - Admin Panel';

require_once __DIR__ . '/admin_header.php';
require_once __DIR__ . '/../config/db.php';

$message = '';
$status = 'info';

if (isset($_POST['confirm_clear'])) {
    try {
        $pdo->beginTransaction();
        
        // 1. Disable Foreign Key Checks temporarily
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // 2. Truncate tables to clear all data and reset auto-increment IDs
        $pdo->exec("TRUNCATE TABLE `researcher_publications`");
        $pdo->exec("TRUNCATE TABLE `publications`");
        $pdo->exec("TRUNCATE TABLE `researchers`");
        
        // 3. Re-enable Foreign Key Checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        $pdo->commit();
        $message = "ล้างข้อมูลนักวิจัยและงานวิจัยทั้งหมดในระบบเรียบร้อยแล้ว! ฐานข้อมูลของคุณสะอาดพร้อมใช้งานแล้วครับ";
        $status = "success";
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "เกิดข้อผิดพลาดในการล้างข้อมูล: " . $e->getMessage();
        $status = "error";
    }
}
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>ล้างฐานข้อมูลระบบ (Clear Database)</h2>
    <p>ระบบจะทำการลบรายชื่อนักวิจัยและสิ่งตีพิมพ์วิชาการทั้งหมดในระบบออกอย่างถาวรเพื่อเริ่มต้นระบบใหม่จากศูนย์</p>
</div>

<div class="container" style="max-width: 600px; margin: 40px auto;">
    <div class="glass-panel" style="padding: 40px; text-align: center; position: relative;">
        <div style="position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--color-error); border-radius: 20px 20px 0 0;"></div>
        
        <i class="fa-solid fa-triangle-exclamation" style="font-size: 4rem; color: var(--color-warning); margin-bottom: 20px;"></i>
        
        <?php if ($message): ?>
            <div style="padding: 15px; border-radius: 8px; margin-bottom: 25px; 
                 background: <?php echo $status === 'success' ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)'; ?>;
                 color: <?php echo $status === 'success' ? '#34d399' : '#f87171'; ?>;
                 border: 1px solid <?php echo $status === 'success' ? '#10b981' : '#ef4444'; ?>;">
                <?php echo $message; ?>
            </div>
            
            <a href="index.php" class="btn-premium" style="display: block; text-align: center; padding: 12px;">
                <i class="fa-solid fa-arrow-left"></i> กลับไปที่แผงควบคุมหลัก
            </a>
        <?php else: ?>
            <h3 style="margin-bottom: 15px; font-weight: 600;">คำเตือนที่สำคัญ!</h3>
            <p style="color: var(--color-text-muted); font-size: 0.95rem; line-height: 1.6; margin-bottom: 30px;">
                การดำเนินการนี้จะทำการลบข้อมูลในตาราง <strong style="color:var(--color-text-main);">นักวิจัย (Researchers)</strong>, 
                <strong style="color:var(--color-text-main);">ผลงานวิจัย (Publications)</strong> และการเชื่อมโยงทั้งหมดออกทั้งหมดอย่างถาวร<br>
                <span style="color: var(--color-warning);"><i class="fa-solid fa-shield-halved"></i> บัญชีผู้ดูแลระบบ (Admin) จะไม่ถูกลบและคุณยังสามารถล็อกอินได้ปกติ</span>
            </p>
            
            <form method="POST">
                <button type="submit" name="confirm_clear" class="btn-logout" style="width: 100%; padding: 14px; font-size: 1rem; cursor: pointer; border-radius: 10px; font-weight: 600;">
                    <i class="fa-solid fa-trash-can"></i> ยืนยันการล้างข้อมูลทั้งหมดเริ่มต้นใหม่
                </button>
                <a href="index.php" class="btn-premium" style="display: block; text-align: center; padding: 12px; margin-top: 15px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass); box-shadow: none; color: var(--color-text-main);">
                    ยกเลิกและย้อนกลับ
                </a>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
