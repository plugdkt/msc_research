<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$api_key = SCOPUS_API_KEY;

function try_url($url, $api_key) {
    echo "Querying: $url\n";
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-ELS-APIKey: {$api_key}",
            "Accept: application/json"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "HTTP Code: $code\n";
        echo "Response: " . substr($res, 0, 1000) . "\n\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n\n";
    }
}

// Try searching for a very common journal name "Cell"
try_url("https://api.elsevier.com/content/serial/title?title=Cell", $api_key);
// Try searching by issn with different format
try_url("https://api.elsevier.com/content/serial/title?issn=0092-8674", $api_key); // Cell ISSN
// Try with view=STANDARD
try_url("https://api.elsevier.com/content/serial/title?issn=0092-8674&view=STANDARD", $api_key);
// Try with view=COMPLETE
try_url("https://api.elsevier.com/content/serial/title?issn=0092-8674&view=COMPLETE", $api_key);
