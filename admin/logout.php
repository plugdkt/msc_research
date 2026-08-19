<?php
// admin/logout.php
// Logout handler for admin session

session_start();
$_SESSION = [];
session_destroy();

header('Location: ../index.php');
exit;
