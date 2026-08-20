<?php
// config/db.php
// Database connection configurations for MSC Research Publications Aggregator
//
// No real secrets live in this file. They come from (in order of
// precedence): real environment variables set on the server, or, for local
// development only, config/secrets.local.php (gitignored — copy
// config/secrets.example.php to create it).

$secrets_file = __DIR__ . '/secrets.local.php';
if (file_exists($secrets_file)) {
    require_once $secrets_file;
}

if (!defined('DB_HOST')) define('DB_HOST', getenv('MSC_DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('MSC_DB_NAME') ?: 'msc_research');
if (!defined('DB_USER')) define('DB_USER', getenv('MSC_DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('MSC_DB_PASS') ?: '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('SCOPUS_API_KEY')) define('SCOPUS_API_KEY', getenv('MSC_SCOPUS_API_KEY') ?: '');

// Phase 8 (Topic Prominence & Trends): OpenAlex's public API works without
// any key (anonymous requests are rate-limited but functional). This is
// only for the "polite pool"/premium tier - higher rate limits when set.
if (!defined('OPENALEX_API_KEY')) define('OPENALEX_API_KEY', getenv('MSC_OPENALEX_API_KEY') ?: '');

// MEDSCI ACC & SSO Configurations
if (!defined('SSO_CLIENT_ID')) define('SSO_CLIENT_ID', getenv('MSC_SSO_CLIENT_ID') ?: 'msc_research');
if (!defined('SSO_CLIENT_SECRET')) define('SSO_CLIENT_SECRET', getenv('MSC_SSO_CLIENT_SECRET') ?: '');
if (!defined('SSO_LOGIN_URL')) define('SSO_LOGIN_URL', getenv('MSC_SSO_LOGIN_URL') ?: 'https://www.medsci.up.ac.th/msc_acc/sso/login.php');
if (!defined('SSO_VERIFY_URL')) define('SSO_VERIFY_URL', getenv('MSC_SSO_VERIFY_URL') ?: 'https://www.medsci.up.ac.th/msc_acc/api/verify.php');

// Dev-only: username to auto-provision as admin on first successful SSO
// login (see admin/sso_callback.php). Empty/undefined means the bypass is
// off — this MUST be empty in production.
if (!defined('DEV_SSO_BYPASS_USERNAME')) define('DEV_SSO_BYPASS_USERNAME', getenv('MSC_DEV_SSO_BYPASS_USERNAME') ?: '');


try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (\PDOException $e) {
    // If the database does not exist, we try to connect without a database name 
    // to allow install.php to run and create it.
    if ($e->getCode() == 1049) { // Unknown database error
        try {
            $dsn_no_db = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn_no_db, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
        } catch (\PDOException $ex) {
            die("Database server connection failed: " . $ex->getMessage());
        }
    } else {
        die("Database connection failed: " . $e->getMessage());
    }
}
