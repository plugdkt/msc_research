<?php
// embed_generator.php - Redirect to Admin Embed tool
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: admin/embed.php' . $qs, true, 302);
exit;
