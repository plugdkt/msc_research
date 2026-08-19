<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

// Try downloading a year-specific file from Michael-E-Rose's repo
// Format in repo is: data/scimago/scimago_2023.csv or similar
$url = "https://raw.githubusercontent.com/Michael-E-Rose/SCImagoJournalRankIndicators/master/data/scimago/scimago_2023.csv";

try {
    echo "Downloading: $url\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $code\n";
    if ($code === 200) {
        echo "Response Excerpt:\n" . substr($res, 0, 1000) . "\n";
    } else {
        echo "Failed. Response: " . substr($res, 0, 500) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
