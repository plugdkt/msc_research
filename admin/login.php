<?php
// admin/login.php
// Admin authentication page

session_start();
require_once __DIR__ . '/../config/db.php';

// Redirect if already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
if (isset($_SESSION['sso_error'])) {
    $error = $_SESSION['sso_error'];
    unset($_SESSION['sso_error']);
}

// Build SSO Redirect URL dynamically
$client_id = defined('SSO_CLIENT_ID') ? SSO_CLIENT_ID : 'msc_research';
if (defined('SSO_REDIRECT_URI') && !empty(SSO_REDIRECT_URI)) {
    $callback_url = SSO_REDIRECT_URI;
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $callback_path = dirname($_SERVER['PHP_SELF']);
    $callback_path = str_replace('\\', '/', $callback_path);
    $callback_url = $protocol . "://" . $host . $callback_path . "/sso_callback.php";
}

$sso_login_base = defined('SSO_LOGIN_URL') ? SSO_LOGIN_URL : 'https://www.medsci.up.ac.th/msc_acc/sso/login.php';
$sso_url = $sso_login_base . "?client_id=" . urlencode($client_id) . "&redirect_uri=" . urlencode($callback_url);

// Check if user specifically requested local login (or if there's an error, to avoid loop)
$use_local = isset($_GET['local']) || isset($_POST['login']) || !empty($error);

if (!$use_local) {
    header('Location: ' . $sso_url);
    exit;
}

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM `users` WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_role'] = $user['role'];
                
                header('Location: index.php');
                exit;
            } else {
                $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            }
        } catch (PDOException $e) {
            $error = 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล';
        }
    } else {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบผู้ดูแลระบบ - MSC Research</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&family=Sarabun:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #060913;
            --card-bg: rgba(17, 24, 39, 0.6);
            --primary: #8b5cf6;
            --primary-hover: #7c3aed;
            --accent: #fbbf24;
            --text-color: #f8fafc;
            --text-muted: #94a3b8;
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
                radial-gradient(at 10% 20%, rgba(139, 92, 246, 0.15) 0px, transparent 50%),
                radial-gradient(at 90% 80%, rgba(251, 191, 36, 0.05) 0px, transparent 50%);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: var(--card-bg);
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            position: relative;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 20px 20px 0 0;
        }

        .logo-area {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo-circle {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), #6366f1);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 12px;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.4);
        }

        h2 {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .subtitle {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fee2e2;
            padding: 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px 12px 40px;
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: white;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
        }

        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), #6b21a8);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
            margin-top: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.5);
            background: linear-gradient(135deg, var(--primary-hover), #7e22ce);
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            font-size: 0.85rem;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .back-link:hover {
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-area">
            <div class="logo-circle">M</div>
            <h2>เข้าสู่ระบบ Admin</h2>
            <div class="subtitle">ระบบรวบรวมข้อมูลงานวิจัย คณะวิทยาศาสตร์การแพทย์</div>
        </div>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="username">ชื่อผู้ใช้งาน</label>
                <div class="input-group">
                    <i class="fa-solid fa-user input-icon"></i>
                    <input type="text" id="username" name="username" class="form-control" placeholder="username" required autocomplete="username">
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">รหัสผ่าน</label>
                <div class="input-group">
                    <i class="fa-solid fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                </div>
            </div>
            
            <button type="submit" name="login" class="btn">เข้าสู่ระบบ (Local Account)</button>
            
            <div style="text-align: center; margin: 15px 0 10px 0; color: var(--text-muted); font-size: 0.8rem; display: flex; align-items: center; justify-content: center; gap: 10px;">
                <span style="flex: 1; height: 1px; background: var(--border-color);"></span>
                <span>หรือเข้าสู่ระบบส่วนกลาง</span>
                <span style="flex: 1; height: 1px; background: var(--border-color);"></span>
            </div>

            <a href="<?php echo htmlspecialchars($sso_url); ?>" class="btn" style="display: block; text-align: center; text-decoration: none; background: linear-gradient(135deg, #10b981, #047857); box-shadow: 0 4px 15px rgba(16, 185, 129, 0.2); margin-top: 5px; line-height: 1.2;">
                <i class="fa-solid fa-shield-halved" style="margin-right: 6px;"></i> เข้าสู่ระบบด้วย MEDSCI ACC (SSO)
            </a>
        </form>
        
        <a href="../index.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> กลับสู่หน้าหลักแดชบอร์ด
        </a>
    </div>
</body>
</html>
