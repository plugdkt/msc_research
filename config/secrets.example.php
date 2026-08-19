<?php
// config/secrets.example.php
// Template for local development. Copy this file to config/secrets.local.php
// (which is gitignored) and fill in real values there.
//
// On the production IIS server, prefer setting these as real environment
// variables (see web.config <environmentVariables>, or Windows Task
// Scheduler / IIS Application Pool environment settings) instead of keeping
// a secrets.local.php file on the server.

define('DB_HOST', 'localhost');
define('DB_NAME', 'msc_research');
define('DB_USER', 'root');
define('DB_PASS', 'CHANGE_ME');
define('SCOPUS_API_KEY', 'CHANGE_ME');

define('SSO_CLIENT_ID', 'msc_research');
define('SSO_CLIENT_SECRET', 'CHANGE_ME');
define('SSO_LOGIN_URL', 'https://www.medsci.up.ac.th/msc_acc/sso/login.php');
define('SSO_VERIFY_URL', 'https://www.medsci.up.ac.th/msc_acc/api/verify.php');

// Leave unset (defaults to true / verification ON). Only set this to false
// if this specific server has a genuinely broken internal CA chain AND you
// understand that it lets anyone on the network path forge the SSO verify
// response and log in as any user - the real fix is pointing curl.cainfo in
// php.ini at an up-to-date cacert.pem, not this.
// define('SSO_SSL_VERIFY', false);

// Optional dev-only convenience: a username that is auto-provisioned as
// admin the first time it successfully authenticates via SSO. Leave this
// commented out (or empty) on any server other than your own dev machine.
// define('DEV_SSO_BYPASS_USERNAME', 'your-username');
