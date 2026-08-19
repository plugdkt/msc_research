<?php
// install.php
// Installer for MSC Research Publications Aggregator v2
//
// SECURITY: this file has no login gate (it can't — there's no admin
// account yet on a fresh install), so it must refuse to do anything once an
// admin account already exists. Delete this file from the server entirely
// once setup is done; leaving an unguarded installer reachable in
// production is itself a risk even with the checks below.

require_once __DIR__ . '/config/db.php';

$message = '';
$status = 'info';
$schema_ready = false;
$already_has_admin = false;
$admin_created = false;

// Figure out current state without assuming the schema exists yet.
try {
    $stmt = $pdo->query("SELECT COUNT(*) as c FROM `users`");
    $already_has_admin = ((int)($stmt->fetch()['c'] ?? 0)) > 0;
    $schema_ready = true;
} catch (PDOException $e) {
    $schema_ready = false;
}

// Step 1: create the schema. Refuses to run once an admin exists, so this
// page can't be used to disrupt a live install (CREATE TABLE IF NOT EXISTS
// is otherwise harmless, but there's no reason to let anyone re-trigger it).
if (isset($_POST['install']) && !$already_has_admin) {
    try {
        $dbName = DB_NAME;
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        $schemaFile = __DIR__ . '/database/schema.sql';
        if (!file_exists($schemaFile)) {
            throw new Exception("Schema file not found at database/schema.sql");
        }

        $sql = file_get_contents($schemaFile);
        // Note: Simple split by semicolon. Real SQL parser is better, but this works for standard schema files.
        $queries = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($queries as $query) {
            if (!empty($query)) {
                $pdo->exec($query);
            }
        }

        $schema_ready = true;
        $message = "สร้างฐานข้อมูลและตารางสำเร็จแล้ว กรุณาตั้งค่าบัญชี Admin คนแรกด้านล่าง";
        $status = "success";
    } catch (Exception $e) {
        $message = "เกิดข้อผิดพลาดในการติดตั้ง: " . $e->getMessage();
        $status = "error";
    }
}

// Step 2: create the one and only bootstrap admin account. No default
// username/password is ever seeded (see database/schema.sql) — this form
// is the only way to get the first admin row, and only while `users` is
// still empty.
if (isset($_POST['create_admin']) && $schema_ready && !$already_has_admin) {
    $new_username = trim($_POST['admin_username'] ?? '');
    $new_password = (string)($_POST['admin_password'] ?? '');
    $confirm_password = (string)($_POST['admin_password_confirm'] ?? '');

    if ($new_username === '' || $new_password === '') {
        $message = "กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบ";
        $status = "error";
    } elseif (strlen($new_password) < 10) {
        $message = "รหัสผ่านต้องมีความยาวอย่างน้อย 10 ตัวอักษร";
        $status = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "รหัสผ่านยืนยันไม่ตรงกับรหัสผ่านที่กรอกไว้";
        $status = "error";
    } else {
        try {
            // The bootstrap account is the project owner — created as
            // superadmin so it can manage other admins via admin/users.php
            // (which is gated to superadmin only).
            $stmt = $pdo->prepare("INSERT INTO `users` (username, password_hash, role) VALUES (?, ?, 'superadmin')");
            $stmt->execute([$new_username, password_hash($new_password, PASSWORD_DEFAULT)]);
            $admin_created = true;
            $already_has_admin = true;
            $message = "สร้างบัญชี Admin สำเร็จแล้ว! กรุณาลบไฟล์ install.php ออกจากเซิร์ฟเวอร์ตอนนี้ แล้วเข้าสู่ระบบที่หน้า Admin Panel";
            $status = "success";
        } catch (PDOException $e) {
            $message = "สร้างบัญชี Admin ไม่สำเร็จ: " . $e->getMessage();
            $status = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบติดตั้งฐานข้อมูล - MSC Research Aggregator v2</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: rgba(20, 26, 46, 0.6);
            --primary: #8a2be2;
            --primary-hover: #9d4edd;
            --accent: #ffd700;
            --text-color: #f3f4f6;
            --text-muted: #9ca3af;
            --success: #10b981;
            --error: #ef4444;
            --border-color: rgba(255, 255, 255, 0.08);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Sarabun', 'Inter', sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 10% 20%, rgba(138, 43, 226, 0.15) 0px, transparent 50%),
                radial-gradient(at 90% 80%, rgba(255, 215, 0, 0.05) 0px, transparent 50%);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 600px;
            background: var(--card-bg);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            font-weight: 600;
            background: linear-gradient(135deg, #ffffff 30%, #a78bfa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 30px;
        }

        .status-box {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: left;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        .status-info {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .status-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #d1fae5;
        }

        .status-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fee2e2;
        }

        .btn {
            display: inline-block;
            width: 100%;
            padding: 14px 28px;
            background: linear-gradient(135deg, var(--primary), #6b21a8);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(138, 43, 226, 0.3);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(138, 43, 226, 0.5);
            background: linear-gradient(135deg, var(--primary-hover), #7e22ce);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            margin-top: 15px;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            box-shadow: none;
            transform: none;
        }

        .db-info {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            font-size: 0.85rem;
            color: var(--text-muted);
            text-align: left;
        }

        .db-info ul {
            list-style: none;
            margin-top: 8px;
        }

        .db-info li {
            margin-bottom: 4px;
        }

        .highlight {
            color: var(--accent);
            font-family: 'Inter', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>ระบบติดตั้งฐานข้อมูล</h1>
        <div class="subtitle">Research Publications Aggregator v2 - คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา</div>

        <?php if ($message): ?>
            <div class="status-box status-<?php echo $status; ?>">
                <?php echo $message; ?>
            </div>
        <?php elseif ($already_has_admin): ?>
            <div class="status-box status-info">
                ระบบติดตั้งเรียบร้อยแล้วและมีบัญชี Admin อยู่แล้ว — ไฟล์นี้ไม่อนุญาตให้ติดตั้งซ้ำ กรุณาลบ <span class="highlight">install.php</span> ออกจากเซิร์ฟเวอร์
            </div>
        <?php elseif (!$schema_ready): ?>
            <div class="status-box status-info">
                ระบบจะทำการสร้างฐานข้อมูลชื่อ <span class="highlight"><?php echo DB_NAME; ?></span> และสร้างตารางข้อมูลที่จำเป็นสำหรับการจัดเก็บประวัตินักวิจัยและข้อมูลผลงานสิ่งตีพิมพ์
            </div>
        <?php else: ?>
            <div class="status-box status-info">
                สร้างฐานข้อมูลแล้ว — กรุณาตั้งค่าบัญชี Admin คนแรกด้านล่าง
            </div>
        <?php endif; ?>

        <?php if ($already_has_admin): ?>
            <a href="index.php" class="btn">ไปที่หน้าแรกหลัก (Dashboard)</a>
            <a href="admin/login.php" class="btn btn-secondary">ไปที่หน้าระบบควบคุม (Admin Panel)</a>
        <?php elseif (!$schema_ready): ?>
            <form method="POST">
                <button type="submit" name="install" class="btn">เริ่มการติดตั้งฐานข้อมูล</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <div class="form-group" style="text-align:left; margin-bottom: 14px;">
                    <label for="admin_username" style="display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:6px;">ชื่อผู้ใช้ Admin</label>
                    <input type="text" id="admin_username" name="admin_username" required
                        style="width:100%; padding:10px 14px; background:rgba(0,0,0,0.25); border:1px solid var(--border-color); border-radius:8px; color:white;">
                </div>
                <div class="form-group" style="text-align:left; margin-bottom: 14px;">
                    <label for="admin_password" style="display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:6px;">รหัสผ่าน (อย่างน้อย 10 ตัวอักษร)</label>
                    <input type="password" id="admin_password" name="admin_password" required minlength="10"
                        style="width:100%; padding:10px 14px; background:rgba(0,0,0,0.25); border:1px solid var(--border-color); border-radius:8px; color:white;">
                </div>
                <div class="form-group" style="text-align:left; margin-bottom: 14px;">
                    <label for="admin_password_confirm" style="display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:6px;">ยืนยันรหัสผ่าน</label>
                    <input type="password" id="admin_password_confirm" name="admin_password_confirm" required minlength="10"
                        style="width:100%; padding:10px 14px; background:rgba(0,0,0,0.25); border:1px solid var(--border-color); border-radius:8px; color:white;">
                </div>
                <button type="submit" name="create_admin" class="btn">สร้างบัญชี Admin</button>
            </form>
        <?php endif; ?>

        <div class="db-info">
            <strong>ข้อมูลการเชื่อมต่อปัจจุบัน (จาก config/db.php):</strong>
            <ul>
                <li>Host: <span class="highlight"><?php echo DB_HOST; ?></span></li>
                <li>Database Name: <span class="highlight"><?php echo DB_NAME; ?></span></li>
                <li>Username: <span class="highlight"><?php echo DB_USER; ?></span></li>
            </ul>
        </div>
    </div>
</body>
</html>
