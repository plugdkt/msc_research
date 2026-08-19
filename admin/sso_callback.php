<?php
// admin/sso_callback.php
// Callback handler for MEDSCI ACC SSO login

session_start();
require_once __DIR__ . '/../config/db.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    $_SESSION['sso_error'] = "ไม่พบโทเคนยืนยันตัวตนส่งกลับมาจากระบบกลาง";
    header('Location: login.php');
    exit;
}

// Prepare verify API request
$client_id = defined('SSO_CLIENT_ID') ? SSO_CLIENT_ID : 'msc_research';
$client_secret = defined('SSO_CLIENT_SECRET') ? SSO_CLIENT_SECRET : '';
$verify_api_url = defined('SSO_VERIFY_URL') ? SSO_VERIFY_URL : 'https://www.medsci.up.ac.th/msc_acc/api/verify.php';

$ch = curl_init($verify_api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'token' => $token,
    'client_id' => $client_id,
    'client_secret' => $client_secret
]));
// SECURITY: default MUST be true. This call carries client_secret and is
// what proves a token really came from MEDSCI ACC - verifying it off by
// default lets anyone on the network path forge a verify response and log
// in as any user. SSO_SSL_VERIFY exists only as an explicit, deliberate
// escape hatch (e.g. a genuinely broken internal CA chain on this specific
// server) - set it in config/secrets.local.php only if you understand that
// tradeoff, and prefer fixing the real cause instead: point curl.cainfo in
// php.ini at an up-to-date cacert.pem bundle rather than disabling
// verification.
$ssl_verify = defined('SSO_SSL_VERIFY') ? (bool)SSO_SSL_VERIFY : true;
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response_json = curl_exec($ch);

if (curl_errno($ch)) {
    $error = curl_error($ch);
    curl_close($ch);
    $_SESSION['sso_error'] = "ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์เพื่อยืนยันตัวตนได้: " . $error;
    header('Location: login.php');
    exit;
}
curl_close($ch);

$result = json_decode($response_json, true);

if ($result && $result['status'] === 'success') {
    $username = trim($result['user']['username'] ?? '');
    
    if (empty($username)) {
        $_SESSION['sso_error'] = "ไม่พบข้อมูลชื่อผู้ใช้งานจากบัญชีกลาง";
        header('Location: login.php');
        exit;
    }
    
    // Check if user has admin permission locally in the database
    try {
        $stmt = $pdo->prepare("SELECT * FROM `users` WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Authorized! Establish local session
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_role'] = $user['role'];
            
            // Clear any error
            unset($_SESSION['sso_error']);
            
            header('Location: index.php');
            exit;
        } else {
            // Dev-only bootstrap: auto-provision DEV_SSO_BYPASS_USERNAME as admin
            // on its first successful SSO login. Controlled entirely by an env
            // var / secrets.local.php (see config/db.php) — never a literal
            // username in source, and empty (= disabled) by default so this is
            // inert wherever that config isn't explicitly set.
            $bypass_username = defined('DEV_SSO_BYPASS_USERNAME') ? DEV_SSO_BYPASS_USERNAME : '';
            if ($bypass_username !== '' && hash_equals($bypass_username, $username)) {
                $stmtInsert = $pdo->prepare("INSERT INTO `users` (username, password_hash, role) VALUES (?, ?, ?)");
                $stmtInsert->execute([$username, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'admin']);

                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $username;
                $_SESSION['admin_role'] = 'admin';
                unset($_SESSION['sso_error']);
                header('Location: index.php');
                exit;
            }

            // Authenticated globally but not registered locally
            $_SESSION['sso_error'] = "บัญชีของท่าน (username: " . htmlspecialchars($username) . ") ยืนยันตัวตนผ่านระบบกลางสำเร็จ แต่ไม่มีสิทธิ์เข้าถึงระบบควบคุมนี้ กรุณาติดต่อผู้ดูแลระบบเพื่อเพิ่มสิทธิ์ในตารางผู้ใช้งาน";
            header('Location: login.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['sso_error'] = "เกิดข้อผิดพลาดกับฐานข้อมูลภายใน: " . $e->getMessage();
        header('Location: login.php');
        exit;
    }
} else {
    $error_msg = $result['message'] ?? 'โทเคนยืนยันสิทธิ์ไม่ถูกต้องหรือหมดอายุ';
    $_SESSION['sso_error'] = "สิทธิ์เข้าถึงล้มเหลว: " . $error_msg;
    header('Location: login.php');
    exit;
}
