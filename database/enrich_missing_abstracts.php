<?php
// database/enrich_missing_abstracts.php
// Multi-source abstract enrichment tool: retrieves full-text abstracts for publications
// that only have document-type placeholders ('Article', 'Review', etc.) or empty abstracts.
// Sources (in fallback order): Europe PMC -> OpenAlex -> CrossRef -> Scopus Abstract Retrieval API

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../config/secrets.local.php')) {
    require_once __DIR__ . '/../config/secrets.local.php';
}

echo "=== Multi-Source Abstract Enrichment Tool ===\n";

$target_stmt = $pdo->query("
    SELECT id, title, doi, source_id, abstract
    FROM `publications`
    WHERE (abstract IS NULL OR abstract = '' OR abstract = 'Article' OR LENGTH(abstract) < 40)
    ORDER BY id ASC
");
$targets = $target_stmt->fetchAll(PDO::FETCH_ASSOC);
$total_targets = count($targets);
echo "Found {$total_targets} publications needing abstract enrichment.\n\n";

if ($total_targets === 0) {
    echo "All publications already have complete abstracts.\n";
    exit;
}

function reconstruct_oa_abstract($inverted_index) {
    if (empty($inverted_index) || !is_array($inverted_index)) return null;
    $words = [];
    foreach ($inverted_index as $word => $positions) {
        if (is_array($positions)) {
            foreach ($positions as $pos) {
                $words[(int)$pos] = $word;
            }
        }
    }
    if (empty($words)) return null;
    ksort($words);
    return implode(' ', $words);
}

function get_abstract_europe_pmc($doi) {
    if (empty($doi)) return null;
    $clean_doi = trim(str_replace(['https://doi.org/', 'http://dx.doi.org/', 'doi:'], '', $doi));
    $url = "https://www.ebi.ac.uk/europepmc/webservices/rest/search?query=DOI:\"" . urlencode($clean_doi) . "\"&format=json&resultType=core";
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: MSC-Research/2.0\r\n"]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    $item = $data['resultList']['result'][0] ?? null;
    if (!empty($item['abstractText'])) {
        $text = trim(strip_tags($item['abstractText']));
        if (mb_strlen($text) >= 40) return $text;
    }
    return null;
}

function get_abstract_openalex($doi) {
    if (empty($doi)) return null;
    $clean_doi = trim(str_replace(['https://doi.org/', 'http://dx.doi.org/', 'doi:'], '', $doi));
    $url = "https://api.openalex.org/works/https://doi.org/" . urlencode($clean_doi);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: MSC-Research/2.0 (mailto:wittaya.su@up.ac.th)\r\n"]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    $text = reconstruct_oa_abstract($data['abstract_inverted_index'] ?? null);
    if ($text && mb_strlen($text) >= 40) return $text;
    return null;
}

function get_abstract_crossref($doi) {
    if (empty($doi)) return null;
    $clean_doi = trim(str_replace(['https://doi.org/', 'http://dx.doi.org/', 'doi:'], '', $doi));
    $url = "https://api.crossref.org/works/" . urlencode($clean_doi);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: MSC-Research/2.0 (mailto:wittaya.su@up.ac.th)\r\n"]]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    $abs = $data['message']['abstract'] ?? null;
    if (!empty($abs)) {
        $text = trim(strip_tags($abs));
        if (mb_strlen($text) >= 40) return $text;
    }
    return null;
}

function get_abstract_scopus_api($doi, $source_id) {
    if (!defined('SCOPUS_API_KEY') || empty(SCOPUS_API_KEY)) return null;
    $api_key = SCOPUS_API_KEY;
    
    $url = null;
    if (!empty($doi)) {
        $clean_doi = trim(str_replace(['https://doi.org/', 'http://dx.doi.org/', 'doi:'], '', $doi));
        $url = "https://api.elsevier.com/content/abstract/doi/" . urlencode($clean_doi);
    } elseif (!empty($source_id) && preg_match('/(?:SCOPUS_ID:)?(\d+)/i', $source_id, $m)) {
        $url = "https://api.elsevier.com/content/abstract/scopus_id/" . urlencode($m[1]);
    }
    if (!$url) return null;
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "Accept: application/json\r\nX-ELS-APIKey: {$api_key}\r\nUser-Agent: MSC-Research/2.0\r\n"
        ]
    ]);
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return null;
    $data = json_decode($json, true);
    $item = $data['abstracts-retrieval-response'] ?? null;
    $desc = $item['coredata']['dc:description'] ?? null;
    if (!empty($desc)) {
        $text = trim(strip_tags($desc));
        if (mb_strlen($text) >= 40) return $text;
    }
    return null;
}

$success_count = 0;
$sources_tally = ['Europe PMC' => 0, 'OpenAlex' => 0, 'CrossRef' => 0, 'Scopus API' => 0];
$update_stmt = $pdo->prepare("
    UPDATE `publications`
    SET `abstract` = :abstract,
        `llm_checked_at` = NULL
    WHERE `id` = :id
");

foreach ($targets as $idx => $p) {
    $num = $idx + 1;
    $doi = $p['doi'];
    $source_id = $p['source_id'];
    
    $abstract = null;
    $used_src = null;
    
    if (!$abstract && !empty($doi)) {
        $abstract = get_abstract_europe_pmc($doi);
        if ($abstract) $used_src = 'Europe PMC';
    }
    if (!$abstract && !empty($doi)) {
        $abstract = get_abstract_openalex($doi);
        if ($abstract) $used_src = 'OpenAlex';
    }
    if (!$abstract && !empty($doi)) {
        $abstract = get_abstract_crossref($doi);
        if ($abstract) $used_src = 'CrossRef';
    }
    if (!$abstract) {
        $abstract = get_abstract_scopus_api($doi, $source_id);
        if ($abstract) $used_src = 'Scopus API';
    }
    
    if ($abstract) {
        $update_stmt->execute([':abstract' => $abstract, ':id' => $p['id']]);
        $success_count++;
        $sources_tally[$used_src]++;
        echo "[{$num}/{$total_targets}] ID {$p['id']}: FOUND via {$used_src} (" . mb_strlen($abstract) . " chars) -> Updated in DB\n";
    } else {
        echo "[{$num}/{$total_targets}] ID {$p['id']}: No abstract available online\n";
    }
    
    usleep(100000);
}

echo "\n=== Enrichment Completed ===\n";
echo "Successfully fetched and updated: {$success_count} / {$total_targets} publications\n";
foreach ($sources_tally as $s => $cnt) {
    echo "  - {$s}: {$cnt} pubs\n";
}
