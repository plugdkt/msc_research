<?php
// fix_admin.php
// Quick script to update/fix the admin password hash in the database

require_once __DIR__ . '/config/db.php';

try {
    $password = 'admin1234';
    $new_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Check if admin exists
    $stmt = $pdo->prepare("SELECT id FROM `users` WHERE username = 'admin'");
    $stmt->execute();
    $admin = $stmt->fetch();
    
    if ($admin) {
        // Update hash
        $stmtUpdate = $pdo->prepare("UPDATE `users` SET password_hash = ? WHERE username = 'admin'");
        $stmtUpdate->execute([$new_hash]);
        $message = "รีเซ็ตและอัปเดตรหัสผ่านของผู้ใช้ <strong>admin</strong> เป็น <strong>{$password}</strong> เรียบร้อยแล้ว!";
    } else {
        // Insert admin
        $stmtInsert = $pdo->prepare("INSERT INTO `users` (username, password_hash, role) VALUES ('admin', ?, 'admin')");
        $stmtInsert->execute([$new_hash]);
        $message = "สร้างบัญชีผู้ใช้ <strong>admin</strong> พร้อมรหัสผ่าน <strong>{$password}</strong> สำเร็จ!";
    }
    
    echo "<div style='font-family: sans-serif; padding: 20px; border-radius: 8px; background: #d1fae5; border: 1px solid #10b981; color: #065f46;'>";
    echo "<h3>สำเร็จ!</h3>";
    echo "<p>{$message}</p>";
    echo "<p>ระบบได้ลบไฟล์นี้ออกเพื่อความปลอดภัยโดยอัตโนมัติแล้ว กรุณากลับไปเข้าสู่ระบบอีกครั้ง</p>";
    echo "</div>";
    
    // Automatically delete this temporary script for security
    unlink(__FILE__);
    
} catch (Exception $e) {
    echo "<div style='font-family: sans-serif; padding: 20px; border-radius: 8px; background: #fee2e2; border: 1px solid #ef4444; color: #991b1b;'>";
    echo "<h3>เกิดข้อผิดพลาด</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
