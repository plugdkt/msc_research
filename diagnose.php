<?php
// diagnose.php
// Diagnostic tool to check database status and login credentials

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('HTTP/1.1 403 Forbidden');
    echo "<h3>Access Denied</h3><p>กรุณาเข้าสู่ระบบในฐานะผู้ดูแลระบบก่อนเข้าใช้งานหน้านี้</p>";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/config/db.php';

echo "<h2>ระบบวินิจฉัยและตรวจสอบระบบ (Diagnostic Utility)</h2>";
echo "<hr>";

// 1. Check PHP Extensions
echo "<h3>1. ตรวจสอบ PHP Extensions:</h3>";
$extensions = ['pdo', 'pdo_mysql', 'curl', 'dom'];
echo "<ul>";
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    echo "<li>Extension <strong>{$ext}</strong>: " . ($loaded ? "<span style='color:green;'>โหลดแล้ว (Loaded)</span>" : "<span style='color:red;'>ไม่ได้โหลด (Missing)</span>") . "</li>";
}
echo "</ul>";

// 2. Check Database Connection & Tables
echo "<h3>2. ตรวจสอบการเชื่อมต่อฐานข้อมูล:</h3>";
try {
    // Test basic connection
    echo "<li>การเชื่อมต่อฐานข้อมูล: <span style='color:green;'>สำเร็จ (Connected)</span></li>";
    
    // Check if database is selected
    $dbSelected = $pdo->query("SELECT DATABASE()")->fetchColumn();
    if (!$dbSelected) {
        echo "<li style='color:red;'><strong>ไม่พบฐานข้อมูล:</strong> ปัจจุบันยังไม่มีฐานข้อมูลชื่อ <code>" . DB_NAME . "</code> ในระบบ MySQL Server ของคุณ</li>";
        echo "<p style='color:orange; font-size:1.1rem; font-weight:bold; padding: 10px; background: rgba(251,191,36,0.1); border-left: 5px solid var(--accent); border-radius: 4px;'>";
        echo "👉 กรุณาเปิดเบราว์เซอร์ไปที่หน้าติดตั้ง <a href='install.php' style='color:#ffd700; text-decoration:underline;'>install.php</a> เพื่อทำการสร้างฐานข้อมูลและติดตั้งตารางเริ่มต้นครับ";
        echo "</p>";
        return;
    }
    
    // Check if database exists
    $dbName = DB_NAME;
    echo "<li>ชื่อฐานข้อมูลที่ใช้: <strong>{$dbName}</strong></li>";
    
    // Check if tables exist
    $tables = ['users', 'researchers', 'publications', 'researcher_publications'];
    echo "<ul>";
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        $exists = $stmt->rowCount() > 0;
        echo "<li>ตาราง <strong>{$table}</strong>: " . ($exists ? "<span style='color:green;'>มีอยู่ในฐานข้อมูล</span>" : "<span style='color:red;'>ไม่มี (ยังไม่ได้รัน install.php หรือเกิดข้อผิดพลาด)</span>") . "</li>";
    }
    echo "</ul>";
    
    // 3. Check Admin User
    echo "<h3>3. ตรวจสอบผู้ใช้งานระบบ (Admin):</h3>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `users`");
    $userCount = $stmt->fetch()['count'];
    echo "<li>จำนวนผู้ใช้งานในตาราง users: <strong>{$userCount} คน</strong></li>";
    
    if ($userCount > 0) {
        $stmt = $pdo->query("SELECT * FROM `users` WHERE username = 'admin'");
        $admin = $stmt->fetch();
        if ($admin) {
            echo "<li>พบผู้ใช้ชื่อ <strong>admin</strong> ในระบบ</li>";
            $password_to_test = 'admin1234';
            $is_correct = password_verify($password_to_test, $admin['password_hash']);
            echo "<li>ทดสอบรหัสผ่าน <strong>'{$password_to_test}'</strong> กับ Hash ในฐานข้อมูล: " . 
                 ($is_correct ? "<span style='color:green;'>ถูกต้อง (Valid)</span>" : "<span style='color:red;'>ไม่ถูกต้อง (Invalid Hash!)</span>") . "</li>";
            
            // Output a regeneration query if invalid
            if (!$is_correct) {
                $new_hash = password_hash($password_to_test, PASSWORD_DEFAULT);
                echo "<p style='color:orange;'><strong>คำแนะนำ:</strong> รหัสผ่านไม่ตรงกับ Hash กรุณารันคำสั่ง SQL นี้ในฐานข้อมูลของคุณเพื่อรีเซ็ตรหัสผ่านเป็น admin1234:<br>";
                echo "<code style='background:#eee;padding:5px;display:block;'>UPDATE `users` SET `password_hash` = '{$new_hash}' WHERE `username` = 'admin';</code></p>";
            }
        } else {
            echo "<li style='color:red;'>ไม่พบผู้ใช้งานชื่อ admin ในระบบ</li>";
        }
    } else {
        echo "<li style='color:red;'>ไม่มีบัญชีผู้ใช้ใดๆ ในระบบ กรุณาเข้าลิงก์ <a href='install.php'>install.php</a> เพื่อสร้างข้อมูลเริ่มต้น</li>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red;'><strong>เกิดข้อผิดพลาดในการเชื่อมต่อ:</strong> " . $e->getMessage() . "</p>";
    echo "<p>กรุณาตรวจสอบว่า:</p>";
    echo "1. ได้เปิดบริการ MySQL Server แล้วหรือยัง<br>";
    echo "2. ข้อมูลการเชื่อมต่อใน <code>config/db.php</code> ถูกต้องหรือไม่<br>";
}
