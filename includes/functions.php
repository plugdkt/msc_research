<?php
// includes/functions.php
// Common functions and API integration logics

require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------------------
// CSRF protection for the batch/on-demand admin endpoints (SDG
// Auto-Classify, Topic classification, RCR fetch, Quartile fetch, SDG
// Suggest) added in Phases 6-9. These are all state-changing GET-based
// AJAX endpoints - a logged-in admin who visits a malicious page while
// their session is active could otherwise have one of these silently
// triggered cross-site (e.g. via an <img>/fetch to a crafted URL), since
// browsers attach session cookies to cross-site GETs automatically. A
// per-session random token, sent as a custom header (not a URL query
// param, to avoid it leaking via Referer logs) and checked with a
// timing-safe comparison, closes that gap without changing these
// endpoints' existing GET-based shape.
// ---------------------------------------------------------------------

/**
 * Returns this session's CSRF token, generating one on first call.
 */
function get_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifies a submitted CSRF token against this session's token.
 */
function verify_csrf_token($submitted_token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || empty($submitted_token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], (string)$submitted_token);
}

// ---------------------------------------------------------------------
// Typed HTTP exceptions
//
// http_get() throws one of these instead of a plain RuntimeException so
// callers implementing SYNC-01/02's retry contract can tell "connection
// failed / timed out" (retry with 5s/15s/45s backoff) apart from "got a
// 429" (wait per Retry-After/X-RateLimit-Reset, or 60s fallback) apart
// from any other HTTP error (not retryable — e.g. 401/403/500).
// ---------------------------------------------------------------------

class ApiTimeoutException extends RuntimeException {}

class ApiRateLimitException extends RuntimeException {
    /** @var int|null seconds to wait before retrying, from Retry-After / X-RateLimit-Reset, or null if absent */
    public $retryAfterSeconds;

    public function __construct($message, $retryAfterSeconds = null) {
        parent::__construct($message);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }
}

class ApiHttpException extends RuntimeException {
    /** @var int */
    public $httpCode;

    public function __construct($message, $httpCode) {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }
}

/**
 * Get country flag image URL from flagcdn.com based on country name mapping
 */
function get_country_flag_url($country_name) {
    static $countries_map = [
        'afghanistan' => 'af', 'albania' => 'al', 'algeria' => 'dz', 'andorra' => 'ad', 'angola' => 'ao',
        'antigua and barbuda' => 'ag', 'argentina' => 'ar', 'armenia' => 'am', 'australia' => 'au', 'austria' => 'at',
        'azerbaijan' => 'az', 'bahamas' => 'bs', 'bahrain' => 'bh', 'bangladesh' => 'bd', 'barbados' => 'bb',
        'belarus' => 'by', 'belgium' => 'be', 'belize' => 'bz', 'benin' => 'bj', 'bhutan' => 'bt',
        'bolivia' => 'bo', 'bosnia and herzegovina' => 'ba', 'botswana' => 'bw', 'brazil' => 'br', 'brunei' => 'bn',
        'bulgaria' => 'bg', 'burkina faso' => 'bf', 'burundi' => 'bi', 'cambodia' => 'kh', 'cameroon' => 'cm',
        'canada' => 'ca', 'cape verde' => 'cv', 'central african republic' => 'cf', 'chad' => 'td', 'chile' => 'cl',
        'china' => 'cn', 'colombia' => 'co', 'comoros' => 'km', 'congo' => 'cg', 'costa rica' => 'cr',
        'croatia' => 'hr', 'cuba' => 'cu', 'cyprus' => 'cy', 'czech republic' => 'cz', 'czechia' => 'cz',
        'denmark' => 'dk', 'djibouti' => 'dj', 'dominica' => 'dm', 'dominican republic' => 'do', 'east timor' => 'tl',
        'ecuador' => 'ec', 'egypt' => 'eg', 'el salvador' => 'sv', 'equatorial guinea' => 'gq', 'eritrea' => 'er',
        'estonia' => 'ee', 'eswatini' => 'sz', 'ethiopia' => 'et', 'fiji' => 'fj', 'finland' => 'fi',
        'france' => 'fr', 'gabon' => 'ga', 'gambia' => 'gm', 'georgia' => 'ge', 'germany' => 'de',
        'ghana' => 'gh', 'greece' => 'gr', 'grenada' => 'gd', 'guatemala' => 'gt', 'guinea' => 'gn',
        'guinea-bissau' => 'gw', 'guyana' => 'gy', 'haiti' => 'ht', 'honduras' => 'hn', 'hong kong' => 'hk',
        'hungary' => 'hu', 'iceland' => 'is', 'india' => 'in', 'indonesia' => 'id', 'iran' => 'ir',
        'iraq' => 'iq', 'ireland' => 'ie', 'israel' => 'il', 'italy' => 'it', 'ivory coast' => 'ci',
        'jamaica' => 'jm', 'japan' => 'jp', 'jordan' => 'jo', 'kazakhstan' => 'kz', 'kenya' => 'ke',
        'kiribati' => 'ki', 'north korea' => 'kp', 'south korea' => 'kr', 'korea' => 'kr', 'kosovo' => 'xk',
        'kuwait' => 'kw', 'kyrgyzstan' => 'kg', 'laos' => 'la', 'latvia' => 'lv', 'lebanon' => 'lb',
        'lesotho' => 'ls', 'liberia' => 'lr', 'libya' => 'ly', 'liechtenstein' => 'li', 'lithuania' => 'lt',
        'luxembourg' => 'lu', 'macau' => 'mo', 'madagascar' => 'mg', 'malawi' => 'mw', 'malaysia' => 'my',
        'maldives' => 'mv', 'mali' => 'ml', 'malta' => 'mt', 'marshall islands' => 'mh', 'mauritania' => 'mr',
        'mauritius' => 'mu', 'mexico' => 'mx', 'micronesia' => 'fm', 'moldova' => 'md', 'monaco' => 'mc',
        'mongolia' => 'mn', 'montenegro' => 'me', 'morocco' => 'ma', 'mozambique' => 'mz', 'myanmar' => 'mm',
        'namibia' => 'na', 'nauru' => 'nr', 'nepal' => 'np', 'netherlands' => 'nl', 'new zealand' => 'nz',
        'nicaragua' => 'ni', 'niger' => 'ne', 'nigeria' => 'ng', 'north macedonia' => 'mk', 'norway' => 'no',
        'oman' => 'om', 'pakistan' => 'pk', 'palau' => 'pw', 'palestine' => 'ps', 'panama' => 'pa',
        'papua new guinea' => 'pg', 'paraguay' => 'py', 'peru' => 'pe', 'philippines' => 'ph', 'poland' => 'pl',
        'portugal' => 'pt', 'qatar' => 'qa', 'romania' => 'ro', 'russia' => 'ru', 'russian federation' => 'ru',
        'rwanda' => 'rw', 'saint kitts and nevis' => 'kn', 'saint lucia' => 'lc', 'saint vincent and the grenadines' => 'vc',
        'samoa' => 'ws', 'san marino' => 'sm', 'sao tome and principe' => 'st', 'saudi arabia' => 'sa',
        'senegal' => 'sn', 'serbia' => 'rs', 'seychelles' => 'sc', 'sierra leone' => 'sl', 'singapore' => 'sg',
        'slovakia' => 'sk', 'slovenia' => 'si', 'solomon islands' => 'sb', 'somalia' => 'so', 'south africa' => 'za',
        'south sudan' => 'ss', 'spain' => 'es', 'sri lanka' => 'lk', 'sudan' => 'sd', 'suriname' => 'sr',
        'sweden' => 'se', 'switzerland' => 'ch', 'syria' => 'sy', 'taiwan' => 'tw', 'tajikistan' => 'tj',
        'tanzania' => 'tz', 'thailand' => 'th', 'togo' => 'tg', 'tonga' => 'to', 'trinidad and tobago' => 'tt',
        'tunisia' => 'tn', 'turkey' => 'tr', 'turkiye' => 'tr', 'turkmenistan' => 'tm', 'tuvalu' => 'tv',
        'uganda' => 'ug', 'ukraine' => 'ua', 'united arab emirates' => 'ae', 'united kingdom' => 'gb',
        'uk' => 'gb', 'united states' => 'us', 'usa' => 'us', 'united states of america' => 'us', 'uruguay' => 'uy',
        'uzbekistan' => 'uz', 'vanuatu' => 'vu', 'vatican city' => 'va', 'venezuela' => 've', 'vietnam' => 'vn',
        'yemen' => 'ye', 'zambia' => 'zm', 'zimbabwe' => 'zw'
    ];
    $clean = trim(strtolower($country_name));
    return isset($countries_map[$clean]) ? 'https://flagcdn.com/w40/' . $countries_map[$clean] . '.png' : null;
}

/**
 * Get total publication count
 */
function get_total_publications($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM `publications`");
        return $stmt->fetch()['total'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get total researcher count
 */
function get_total_researchers($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM `researchers`");
        return $stmt->fetch()['total'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get total citations count
 */
function get_total_citations($pdo) {
    try {
        $stmt = $pdo->query("SELECT SUM(citation_count) as total FROM `publications`");
        return $stmt->fetch()['total'] ?? 0;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get recent publications
 */
function get_recent_publications($pdo, $limit = 5) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, 
                   GROUP_CONCAT(r.first_name_th, ' ', r.last_name_th SEPARATOR ', ') as internal_authors,
                   CONCAT(ca.title_th, ca.first_name_th, ' ', ca.last_name_th) as corresponding_author_name
            FROM `publications` p
            LEFT JOIN `researcher_publications` rp ON p.id = rp.publication_id
            LEFT JOIN `researchers` r ON rp.researcher_id = r.id
            LEFT JOIN `researchers` ca ON p.corresponding_author_id = ca.id
            GROUP BY p.id
            ORDER BY p.publish_year DESC, p.publish_date DESC, p.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get publication count grouped by publish year
 */
function get_publications_by_year_summary($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT publish_year as year, COUNT(*) as count 
            FROM `publications` 
            GROUP BY publish_year 
            ORDER BY publish_year ASC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get publication count grouped by source database
 */
function get_publications_by_source_summary($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT source, COUNT(*) as count 
            FROM `publications` 
            GROUP BY source
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get department list and researcher count
 */
function get_departments_summary($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT department, COUNT(*) as count
            FROM `researchers`
            GROUP BY department
            ORDER BY count DESC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * DASH-06: timestamp of the most recent completed sync, for display on
 * every public page. Reads sync_log first (added in Phase 1) since it
 * records the actual sync run, not just "some publication row changed" —
 * falls back to the pre-sync_log heuristic (latest non-manual publication
 * update) only if sync_log has no successful/partial run yet (e.g. a
 * database that hasn't run the Phase 1 migration).
 */
function get_last_sync_time($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT completed_at FROM `sync_log`
            WHERE status IN ('success', 'partial') AND completed_at IS NOT NULL
            ORDER BY completed_at DESC
            LIMIT 1
        ");
        $ts = $stmt->fetchColumn();
        if ($ts) {
            return $ts;
        }
    } catch (PDOException $e) {
        // sync_log may not exist yet on a pre-migration database — fall through.
    }

    try {
        $stmt = $pdo->query("SELECT MAX(updated_at) FROM `publications` WHERE source != 'manual'");
        return $stmt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Calculate the h-index of a researcher based on local database citation counts
 * and persist it as a cumulative peak (never decreases).
 */
function calculate_researcher_h_index($pdo, $researcher_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.citation_count 
            FROM `publications` p
            JOIN `researcher_publications` rp ON p.id = rp.publication_id
            WHERE rp.researcher_id = ?
            ORDER BY p.citation_count DESC
        ");
        $stmt->execute([$researcher_id]);
        $citations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $h_index = 0;
        foreach ($citations as $index => $citation) {
            $rank = $index + 1;
            if ($citation >= $rank) {
                $h_index = $rank;
            } else {
                break;
            }
        }

        // Update peak: only store if new value is higher than current stored peak
        // ponytail: simple MAX(); upgrade path — store history table if yearly snapshots needed
        $pdo->prepare("
            UPDATE `researchers` SET h_index_peak = GREATEST(h_index_peak, :h) WHERE id = :id
        ")->execute([':h' => $h_index, ':id' => $researcher_id]);

        return $h_index;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Return the cumulative peak h-index stored in the researchers table.
 */
function get_researcher_h_index_peak($pdo, $researcher_id) {
    try {
        $stmt = $pdo->prepare("SELECT h_index_peak FROM `researchers` WHERE id = ?");
        $stmt->execute([$researcher_id]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Heuristically determine if a researcher is the First Author or Co-Author
 */
function determine_author_role($author_string, $first_name_en, $last_name_en) {
    if (empty($author_string)) return 'Co-Author';
    
    // Normalize names to handle lowercase / initials matching
    $last_name = strtolower(trim($last_name_en));
    $first_initial = strtolower(substr(trim($first_name_en), 0, 1));
    
    // Split author string on common delimiters (comma, semicolon)
    $authors = preg_split('/\s*[,;]\s*/', $author_string);
    $first_author = trim($authors[0] ?? '');
    
    if (empty($first_author)) return 'Co-Author';
    
    $normalized_first_author = strtolower($first_author);
    
    // Check if the first author entry contains both the last name and first initial
    if (strpos($normalized_first_author, $last_name) !== false && strpos($normalized_first_author, $first_initial) !== false) {
        return 'First Author';
    }
    
    return 'Co-Author';
}


/**
 * Get list of researchers with their publication counts.
 *
 * RESEARCHER-05: inactive researchers are hidden from public-facing lists
 * (the directory, dashboard "top researcher" spotlights) but their
 * historical publications still count in faculty-wide aggregates - so
 * $active_only defaults to true for those callers, and admin pages that
 * need to see/manage everyone (admin/index.php, admin/import.php) pass
 * false explicitly.
 */
function get_all_researchers($pdo, $active_only = true) {
    try {
        $where = $active_only ? "WHERE r.is_active = 1" : "";
        $stmt = $pdo->query("
            SELECT r.*, COUNT(rp.publication_id) as pub_count, SUM(p.citation_count) as total_citations
            FROM `researchers` r
            LEFT JOIN `researcher_publications` rp ON r.id = rp.researcher_id
            LEFT JOIN `publications` p ON rp.publication_id = p.id
            {$where}
            GROUP BY r.id
            ORDER BY r.first_name_th ASC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Get researcher by ID
 */
function get_researcher_by_id($pdo, $id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM `researchers` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Get publications of a specific researcher
 */
function get_researcher_publications($pdo, $researcher_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   CONCAT(ca.title_th, ca.first_name_th, ' ', ca.last_name_th) as corresponding_author_name
            FROM `publications` p
            JOIN `researcher_publications` rp ON p.id = rp.publication_id
            LEFT JOIN `researchers` ca ON p.corresponding_author_id = ca.id
            WHERE rp.researcher_id = ?
            ORDER BY p.publish_year DESC, p.publish_date DESC
        ");
        $stmt->execute([$researcher_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}


// ---------------------------------------------------------------------
// HTTP helpers
//
// Centralizing curl logic here means the SSL-verification fix and error
// handling only need to be correct in one place, instead of repeated
// (and previously inconsistently broken) in every fetch_* function.
// ---------------------------------------------------------------------

/**
 * Perform an HTTP GET request and return the raw response body.
 *
 * Throws a typed exception on failure so callers can implement the
 * SYNC-01/02 retry contract:
 * - ApiTimeoutException: connection/timeout-class curl failure (retry with backoff)
 * - ApiRateLimitException: HTTP 429 (retry after retryAfterSeconds, or a fallback)
 * - ApiHttpException: any other non-2xx status (not retryable)
 *
 * @throws ApiTimeoutException|ApiRateLimitException|ApiHttpException
 */
function http_get($url, array $headers = [], $timeout = 10, $user_agent = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // need response headers for Retry-After / X-RateLimit-Reset
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent ?: 'MSC-Research-System/1.0 (+https://www.medsci.up.ac.th; mailto:msc_research@up.ac.th)');
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    // SSL verification stays on for every outbound call (Scopus, NCBI, NIH, OpenAlex, CrossRef, ...).
    // If a local CA bundle is present, automatically configure CURLOPT_CAINFO.
    $ca_candidates = [
        defined('CURL_CA_BUNDLE') ? CURL_CA_BUNDLE : '',
        __DIR__ . '/../config/cacert.pem',
        'C:\\xampp\\php\\extras\\ssl\\cacert.pem',
    ];
    foreach ($ca_candidates as $ca_file) {
        if (!empty($ca_file) && file_exists($ca_file) && is_readable($ca_file)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca_file);
            break;
        }
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($errno !== 0) {
        // Every curl-level failure (DNS, connect, and actual timeouts alike)
        // is treated as the retryable "timeout" class per SYNC-01.
        throw new ApiTimeoutException("HTTP request failed ({$url}): curl error [{$errno}] {$error}");
    }

    $header_text = $raw !== false ? substr($raw, 0, $header_size) : '';
    $body = $raw !== false ? substr($raw, $header_size) : '';

    if ($http_code === 429) {
        $retry_after = null;
        if (preg_match('/^Retry-After:\s*(\d+)/mi', $header_text, $m)) {
            $retry_after = (int)$m[1];
        } elseif (preg_match('/^X-RateLimit-Reset:\s*(\d+)/mi', $header_text, $m)) {
            $retry_after = max(0, (int)$m[1] - time());
        }
        throw new ApiRateLimitException("HTTP request failed ({$url}): rate limited (429)", $retry_after);
    }

    if ($http_code < 200 || $http_code >= 300) {
        throw new ApiHttpException("HTTP request failed ({$url}): HTTP status {$http_code}", $http_code);
    }

    return $body;
}

/**
 * Perform an HTTP GET request and decode a JSON response.
 *
 * @throws RuntimeException
 */
function http_get_json($url, array $headers = [], $timeout = 10, $user_agent = null) {
    $default_headers = array_merge(['Accept: application/json'], $headers);
    $body = http_get($url, $default_headers, $timeout, $user_agent);
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("HTTP request failed ({$url}): invalid JSON response - " . json_last_error_msg());
    }
    return $data;
}


/**
 * Search CrossRef API by publication title to find the direct DOI and publisher URL.
 *
 * This is a best-effort enrichment step, not a critical API integration, so
 * failures here are caught and logged rather than thrown further up — a
 * missing enrichment should never block saving the underlying publication.
 */
function find_direct_link_by_title($title) {
    if (empty($title)) return null;

    // Clean title for better API matching
    $clean_title = preg_replace('/[^\w\s\-]/u', ' ', $title);
    $clean_title = preg_replace('/\s+/', ' ', $clean_title);

    $url = "https://api.crossref.org/works?query=" . urlencode(trim($clean_title)) . "&rows=1";

    try {
        $data = http_get_json($url, [], 5);
    } catch (Throwable $e) {
        error_log("[find_direct_link_by_title] " . $e->getMessage());
        return null;
    }

    $item = $data['message']['items'][0] ?? null;

    if ($item) {
        $foundTitle = $item['title'][0] ?? '';

        // Compute similarity percentage
        similar_text(strtolower(trim($title)), strtolower(trim($foundTitle)), $percent);
        if ($percent > 85) {
            $doi = $item['DOI'] ?? null;
            $url = $item['URL'] ?? null;

            if ($doi && !$url) {
                $url = "https://doi.org/{$doi}";
            }

            if ($doi || $url) {
                return [
                    'doi' => $doi,
                    'url' => $url
                ];
            }
        }
    }

    return null;
}

/**
 * Add or Update a publication record and associate it with a researcher (optional).
 * Deduplicates using the DOI or Title+Year.
 *
 * Design note: any external network call (CrossRef enrichment) happens
 * BEFORE the database transaction is opened. A DB transaction should only
 * ever wrap fast local writes — holding it open across a network round trip
 * risks long-held locks and deadlocks when multiple researchers are synced
 * concurrently.
 */
function add_or_update_publication($pdo, $data, $researcher_id = null) {
    $doi = !empty($data['doi']) ? trim($data['doi']) : null;
    $url = !empty($data['url']) ? trim($data['url']) : null;
    $pubId = null;

    try {
        // 1. Check if publication already exists in DB by DOI (if we have one)
        if ($doi !== null) {
            $stmt = $pdo->prepare("SELECT id, title FROM `publications` WHERE doi = ?");
            $stmt->execute([$doi]);
            $existing = $stmt->fetch();
            if ($existing) {
                similar_text(strtolower(trim($data['title'])), strtolower(trim($existing['title'])), $percent);
                
                $erratum_mismatch = false;
                $t1 = strtolower(trim($data['title']));
                $t2 = strtolower(trim($existing['title']));
                foreach (['erratum', 'correction', 'corrigendum', 'retraction', 'errata'] as $kw) {
                    if ((strpos($t1, $kw) !== false) !== (strpos($t2, $kw) !== false)) {
                        $erratum_mismatch = true;
                        break;
                    }
                }
                
                if ($percent >= 80 && !$erratum_mismatch) {
                    $pubId = $existing['id'];
                } else {
                    // DOI exists but it's a completely different publication (e.g. erratum/correction sharing the DOI)
                    // Set DOI to null to bypass the unique DB constraint on insert
                    $doi = null;
                }
            }
        }

        // 2. Check if publication exists by Title & Year (if not found by DOI)
        if (!$pubId) {
            $stmt = $pdo->prepare("SELECT id FROM `publications` WHERE LOWER(title) = ? AND publish_year = ?");
            $stmt->execute([strtolower(trim($data['title'])), (int)$data['publish_year']]);
            $existing = $stmt->fetch();
            if ($existing) {
                $pubId = $existing['id'];
            }
        }

        // 3. Only if it's a NEW publication (not in DB), query CrossRef to find a
        //    better DOI/direct link. This is the network call kept OUTSIDE any transaction.
        if (!$pubId) {
            if (empty($doi) || empty($url) || strpos($url, 'orcid.org') !== false) {
                $enriched = find_direct_link_by_title($data['title']);
                if ($enriched) {
                    $doi = $enriched['doi'] ?: $doi;
                    $url = $enriched['url'] ?: $url;

                    // Re-check: the enriched DOI might already exist in the database
                    if ($doi !== null) {
                        $stmt = $pdo->prepare("SELECT id, title FROM `publications` WHERE doi = ?");
                        $stmt->execute([$doi]);
                        $existing = $stmt->fetch();
                        if ($existing) {
                            similar_text(strtolower(trim($data['title'])), strtolower(trim($existing['title'])), $percent);
                            
                            $erratum_mismatch = false;
                            $t1 = strtolower(trim($data['title']));
                            $t2 = strtolower(trim($existing['title']));
                            foreach (['erratum', 'correction', 'corrigendum', 'retraction', 'errata'] as $kw) {
                                if ((strpos($t1, $kw) !== false) !== (strpos($t2, $kw) !== false)) {
                                    $erratum_mismatch = true;
                                    break;
                                }
                            }
                            
                            if ($percent >= 80 && !$erratum_mismatch) {
                                $pubId = $existing['id'];
                            } else {
                                $doi = null;
                            }
                        }
                    }
                }
            }
        }

        // 4. Write phase: short-lived transaction, no network calls inside.
        $journal_issn = !empty($data['journal_issn']) ? trim($data['journal_issn']) : null;
        $quartile = !empty($data['quartile']) ? trim($data['quartile']) : null;

        if ($journal_issn && empty($quartile)) {
            $clean_issn = preg_replace('/[^0-9X]/i', '', $journal_issn);
            try {
                $stmtFindQ = $pdo->prepare("SELECT quartile FROM `journal_quartiles` WHERE issn = ? AND quartile != '' LIMIT 1");
                $stmtFindQ->execute([$clean_issn]);
                $found_q = $stmtFindQ->fetchColumn();
                if ($found_q) {
                    $quartile = $found_q;
                }
            } catch (PDOException $e) {
                // Ignore DB error during lookup
            }
        }

        $pdo->beginTransaction();
        try {
            $countries = !empty($data['countries']) ? trim($data['countries']) : null;
            $funding_sponsor = !empty($data['funding_sponsor']) ? trim($data['funding_sponsor']) : null;
            $funding_no = !empty($data['funding_no']) ? trim($data['funding_no']) : null;
            $funding_amount = !empty($data['funding_amount']) && is_numeric($data['funding_amount']) ? (float)$data['funding_amount'] : null;
            $sdg_primary = !empty($data['sdg_primary']) ? trim($data['sdg_primary']) : null;
            $sdg_secondary = !empty($data['sdg_secondary']) ? trim($data['sdg_secondary']) : null;
            $sdg_tertiary = !empty($data['sdg_tertiary']) ? trim($data['sdg_tertiary']) : null;
            $sdg_rationale = !empty($data['sdg_rationale']) ? trim($data['sdg_rationale']) : null;
            $corresponding_author_id = !empty($data['corresponding_author_id']) ? (int)$data['corresponding_author_id'] : null;
            
            if ($pubId) {
                // Update existing with CASE logic to preserve direct links but upgrade general/empty ones
                $stmtUpdate = $pdo->prepare("
                    UPDATE `publications` 
                    SET `citation_count` = CASE 
                            WHEN :source = 'scopus' THEN :citations_scopus
                            ELSE GREATEST(`citation_count`, :citations_other)
                        END,
                        `url` = CASE 
                            WHEN :url1 IS NULL OR :url2 = '' THEN `url`
                            WHEN :url3 LIKE '%doi.org%' THEN :url4
                            WHEN `url` LIKE '%orcid.org%' OR `url` LIKE '%scopus.com%' OR `url` IS NULL OR `url` = '' THEN :url5
                            ELSE `url`
                        END,
                        `journal_issn` = COALESCE(:journal_issn, `journal_issn`),
                        `quartile` = COALESCE(:quartile, `quartile`),
                        `countries` = COALESCE(:countries, `countries`),
                        `funding_sponsor` = COALESCE(:funding_sponsor, `funding_sponsor`),
                        `funding_no` = COALESCE(:funding_no, `funding_no`),
                        `funding_amount` = COALESCE(:funding_amount, `funding_amount`),
                        `sdg_primary` = COALESCE(:sdg_primary, `sdg_primary`),
                        `sdg_secondary` = COALESCE(:sdg_secondary, `sdg_secondary`),
                        `sdg_tertiary` = COALESCE(:sdg_tertiary, `sdg_tertiary`),
                        `sdg_rationale` = COALESCE(:sdg_rationale, `sdg_rationale`),
                        `corresponding_author_id` = COALESCE(:corresponding_author_id, `corresponding_author_id`)
                    WHERE `id` = :id
                ");
                $stmtUpdate->execute([
                    ':source' => $data['source'] ?? 'manual',
                    ':citations_scopus' => (int)($data['citation_count'] ?? 0),
                    ':citations_other' => (int)($data['citation_count'] ?? 0),
                    ':url1' => $url,
                    ':url2' => $url,
                    ':url3' => $url,
                    ':url4' => $url,
                    ':url5' => $url,
                    ':journal_issn' => $journal_issn,
                    ':quartile' => $quartile,
                    ':countries' => $countries,
                    ':funding_sponsor' => $funding_sponsor,
                    ':funding_no' => $funding_no,
                    ':funding_amount' => $funding_amount,
                    ':sdg_primary' => $sdg_primary,
                    ':sdg_secondary' => $sdg_secondary,
                    ':sdg_tertiary' => $sdg_tertiary,
                    ':sdg_rationale' => $sdg_rationale,
                    ':corresponding_author_id' => $corresponding_author_id,
                    ':id' => $pubId
                ]);
            } else {
                // Insert new
                $stmtInsert = $pdo->prepare("
                    INSERT INTO `publications`
                    (title, authors, journal_name, journal_issn, quartile, countries, corresponding_author_id, funding_sponsor, funding_no, funding_amount, sdg_primary, sdg_secondary, sdg_tertiary, sdg_rationale, publish_year, publish_date, doi, citation_count, source, source_id, url, abstract)
                    VALUES
                    (:title, :authors, :journal_name, :journal_issn, :quartile, :countries, :corresponding_author_id, :funding_sponsor, :funding_no, :funding_amount, :sdg_primary, :sdg_secondary, :sdg_tertiary, :sdg_rationale, :publish_year, :publish_date, :doi, :citation_count, :source, :source_id, :url, :abstract)
                ");
                $stmtInsert->execute([
                    ':title' => trim($data['title']),
                    ':authors' => trim($data['authors']),
                    ':journal_name' => $data['journal_name'] ?? null,
                    ':journal_issn' => $journal_issn,
                    ':quartile' => $quartile,
                    ':countries' => $countries,
                    ':corresponding_author_id' => $corresponding_author_id,
                    ':funding_sponsor' => $funding_sponsor,
                    ':funding_no' => $funding_no,
                    ':funding_amount' => $funding_amount,
                    ':sdg_primary' => $sdg_primary,
                    ':sdg_secondary' => $sdg_secondary,
                    ':sdg_tertiary' => $sdg_tertiary,
                    ':sdg_rationale' => $sdg_rationale,
                    ':publish_year' => (int)$data['publish_year'],
                    ':publish_date' => !empty($data['publish_date']) ? $data['publish_date'] : null,
                    ':doi' => $doi,
                    ':citation_count' => (int)($data['citation_count'] ?? 0),
                    ':source' => $data['source'] ?? 'manual',
                    ':source_id' => $data['source_id'] ?? null,
                    ':url' => $url,
                    ':abstract' => $data['abstract'] ?? null
                ]);
                $pubId = $pdo->lastInsertId();
            }

            // 5. Link researcher if ID is provided
            if (!empty($researcher_id)) {
                $stmtLinkCheck = $pdo->prepare("
                    SELECT 1 FROM `researcher_publications` 
                    WHERE researcher_id = ? AND publication_id = ?
                ");
                $stmtLinkCheck->execute([$researcher_id, $pubId]);
                if (!$stmtLinkCheck->fetch()) {
                    $stmtLink = $pdo->prepare("
                        INSERT INTO `researcher_publications` (researcher_id, publication_id) 
                        VALUES (?, ?)
                    ");
                    $stmtLink->execute([$researcher_id, $pubId]);
                }
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $pubId;
    } catch (PDOException $e) {
        error_log("Failed to add_or_update_publication: " . $e->getMessage());
        return false;
    }
}

/**
 * Explicit, admin-triggered cleanup of publications with zero researcher links.
 *
 * Deliberately NOT called automatically from any sync function — run this
 * on demand (e.g. from a dedicated admin button) when you're confident no
 * other sync is running concurrently.
 *
 * Returns the number of rows deleted.
 */
function cleanup_orphaned_publications($pdo) {
    try {
        $stmt = $pdo->exec("
            DELETE FROM `publications` 
            WHERE `id` NOT IN (SELECT DISTINCT `publication_id` FROM `researcher_publications`)
        ");
        return (int)$stmt;
    } catch (PDOException $e) {
        error_log("Failed to clean up orphaned publications: " . $e->getMessage());
        return 0;
    }
}

/**
 * Fetch publications from Scopus (requires an Elsevier API Key).
 *
 * Scopus-only data source (2026-08-19 decision — ORCID/PubMed/Google Scholar
 * removed; multi-source matching/dedup was a recurring source of bugs in the
 * previous version, and Google Scholar scraping violated its ToS anyway).
 *
 * This function no longer returns simulated/mock data when a key is missing
 * or the request fails. Returning fake publication records that get saved
 * and attributed to a real researcher is a data-integrity and reputational
 * risk. Missing key or failed request now throws typed exceptions (see
 * http_get()) so the caller can implement the SYNC-01/02 retry contract.
 *
 * SYNC-03: records missing a DOI or author are skipped rather than saved
 * with placeholder text — pass $skipped_count by reference to get a count.
 *
 * @param int|null $skipped_count incremented once per skipped incomplete record
 * @throws RuntimeException if no API key is configured
 * @throws ApiTimeoutException|ApiRateLimitException|ApiHttpException on request failure
 */
function fetch_scopus_publications($scopus_author_id, $api_key = null, &$skipped_count = null) {
    if (empty($scopus_author_id)) return [];

    if (empty($api_key)) {
        throw new RuntimeException('ไม่พบ Scopus API Key — ไม่สามารถดึงข้อมูลได้ (ระบบไม่ใช้ข้อมูลจำลอง/ปลอมอีกต่อไป)');
    }

    $publications = [];
    $start = 0;
    $count = 200;

    do {
        $fields = "dc:title,dc:creator,prism:publicationName,prism:issn,prism:eIssn,prism:coverDate,prism:doi,citedby-count,affiliation,subtypeDescription,fund-no,fund-sponsor,prism:url,dc:identifier,eid,openaccess,openaccessFlag,link";
        $url = "https://api.elsevier.com/content/search/scopus?query=AU-ID(" . urlencode($scopus_author_id) . ")&count={$count}&start={$start}&field=" . urlencode($fields);
        $data = http_get_json($url, ["X-ELS-APIKey: {$api_key}"], 10);

        $entries = $data['search-results']['entry'] ?? [];
        $total_results = (int)($data['search-results']['opensearch:totalResults'] ?? 0);

        foreach ($entries as $entry) {
            if (isset($entry['error'])) {
                continue;
            }

            $doi = !empty($entry['prism:doi']) ? trim($entry['prism:doi']) : null;
            $authors_raw = !empty($entry['dc:creator']) ? trim($entry['dc:creator']) : null;

            // SYNC-03: skip incomplete records (no DOI, no author) instead of
            // saving them with placeholder text.
            if ($doi === null || $authors_raw === null) {
                if ($skipped_count !== null) {
                    $skipped_count++;
                }
                continue;
            }

            $coverDate = $entry['prism:coverDate'] ?? '';
            $year = !empty($coverDate) ? date('Y', strtotime($coverDate)) : date('Y');

            $scopus_link = "https://doi.org/" . $doi;
            if (isset($entry['link']) && is_array($entry['link'])) {
                foreach ($entry['link'] as $lnk) {
                    if (isset($lnk['@ref']) && $lnk['@ref'] === 'scopus') {
                        $scopus_link = $lnk['@href'] ?? $scopus_link;
                        break;
                    }
                }
            }

            $issn = !empty($entry['prism:issn']) ? trim($entry['prism:issn']) : (!empty($entry['prism:eIssn']) ? trim($entry['prism:eIssn']) : null);

            $countries_list = [];
            if (isset($entry['affiliation'])) {
                $affs = $entry['affiliation'];
                if (isset($affs['affiliation-country'])) {
                    $affs = [$affs];
                }
                if (is_array($affs)) {
                    foreach ($affs as $aff) {
                        if (is_array($aff) && !empty($aff['affiliation-country'])) {
                            $countries_list[] = trim($aff['affiliation-country']);
                        }
                    }
                }
            }
            $countries_list[] = 'Thailand';
            $countries = !empty($countries_list) ? implode(', ', array_unique($countries_list)) : null;

            $funding_sponsor = !empty($entry['fund-sponsor']) ? trim($entry['fund-sponsor']) : null;
            $funding_no = !empty($entry['fund-no']) ? trim($entry['fund-no']) : null;

            $publications[] = [
                'title' => $entry['dc:title'] ?? 'Untitled',
                'authors' => $authors_raw,
                'journal_name' => $entry['prism:publicationName'] ?? 'Scopus Document',
                'journal_issn' => $issn,
                'countries' => $countries,
                'funding_sponsor' => $funding_sponsor,
                'funding_no' => $funding_no,
                'publish_year' => $year,
                'publish_date' => $coverDate,
                'doi' => $doi,
                'citation_count' => (int)($entry['citedby-count'] ?? 0),
                'source' => 'scopus',
                'source_id' => $entry['dc:identifier'] ?? null,
                'url' => $scopus_link,
                'abstract' => $entry['subtypeDescription'] ?? null
            ];
        }

        $start += count($entries);
    } while ($start < $total_results && count($entries) > 0);

    return $publications;
}

/**
 * fetch_scopus_publications() wrapped with the SYNC-01/02 retry contract:
 * - connection/timeout failures: retry 3x with 5s/15s/45s backoff
 * - HTTP 429: wait per Retry-After/X-RateLimit-Reset header (or 60s
 *   fallback), retry, giving up after 3 attempts like the timeout case
 * - any other HTTP error (401/403/500/...): not retryable, propagates immediately
 *
 * @param int|null $skipped_count incremented once per skipped incomplete record (see fetch_scopus_publications)
 * @throws RuntimeException if all 3 attempts are exhausted, or on a non-retryable error
 */
function fetch_scopus_publications_with_retry($scopus_author_id, $api_key, &$skipped_count = null) {
    $backoff_schedule = [5, 15, 45];
    $last_exception = null;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            return fetch_scopus_publications($scopus_author_id, $api_key, $skipped_count);
        } catch (ApiRateLimitException $e) {
            $wait = $e->retryAfterSeconds !== null ? $e->retryAfterSeconds : 60;
            error_log("[scopus][sync] rate limited (429), waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiTimeoutException $e) {
            $wait = $backoff_schedule[$attempt] ?? 45;
            error_log("[scopus][sync] timeout/connection error, waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        }
        // ApiHttpException (any other non-2xx) and any other Throwable are not
        // caught here — they propagate immediately, since retrying a 401/403/500
        // won't help.
    }

    throw new RuntimeException(
        "Scopus sync failed after 3 attempts: " . ($last_exception ? $last_exception->getMessage() : 'unknown error')
    );
}

/**
 * Phase 6 (SDG-06a/b): fetch the real abstract text and author keywords for
 * one publication from the Scopus Abstract Retrieval API.
 *
 * This is a separate, per-publication call from fetch_scopus_publications()'s
 * bulk Search API - the Search API does not return real abstract/keyword
 * data (a previous version of this codebase mistakenly stored
 * `subtypeDescription`, the document type, in the `abstract` column). SDG
 * keyword matching needs the real text, so this is called on demand
 * (backfill) rather than inline during every regular sync, to keep the
 * regular sync fast and avoid one extra API call per publication on every run.
 *
 * @return array{abstract: ?string, keywords: ?string}
 * @throws ApiTimeoutException|ApiRateLimitException|ApiHttpException
 */
function fetch_scopus_abstract_details($doi, $api_key) {
    if (empty($doi)) {
        return ['abstract' => null, 'keywords' => null];
    }

    $url = "https://api.elsevier.com/content/abstract/doi/" . urlencode($doi);
    $data = http_get_json($url, ["X-ELS-APIKey: {$api_key}"], 10);

    $resp = $data['abstracts-retrieval-response'] ?? null;
    if (!$resp) {
        return ['abstract' => null, 'keywords' => null];
    }

    $core = $resp['coredata'] ?? [];
    $abstract = !empty($core['dc:description']) ? trim($core['dc:description']) : null;

    $kw_list = [];
    $raw_kw = $resp['authkeywords']['author-keyword'] ?? null;
    if (is_array($raw_kw)) {
        foreach ($raw_kw as $item) {
            if (is_array($item) && isset($item['$'])) {
                $kw_list[] = trim($item['$']);
            } elseif (is_string($item)) {
                $kw_list[] = trim($item);
            }
        }
    }
    $keywords = !empty($kw_list) ? implode(', ', $kw_list) : null;

    return ['abstract' => $abstract, 'keywords' => $keywords];
}

/**
 * fetch_scopus_abstract_details() wrapped with the same SYNC-01/02 retry
 * contract as the main sync (timeout: 3x with 5s/15s/45s backoff; 429: wait
 * per header or 60s fallback). A 404 (Scopus has no abstract record for
 * this DOI - not uncommon) is treated as "no data" rather than an error,
 * since it's expected for some publications, not a transient failure.
 *
 * @throws RuntimeException if all 3 attempts are exhausted on a retryable error
 */
function fetch_scopus_abstract_details_with_retry($doi, $api_key) {
    $backoff_schedule = [5, 15, 45];
    $last_exception = null;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            return fetch_scopus_abstract_details($doi, $api_key);
        } catch (ApiRateLimitException $e) {
            $wait = $e->retryAfterSeconds !== null ? $e->retryAfterSeconds : 60;
            error_log("[scopus][abstract] rate limited (429), waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiTimeoutException $e) {
            $wait = $backoff_schedule[$attempt] ?? 45;
            error_log("[scopus][abstract] timeout/connection error, waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiHttpException $e) {
            if ($e->httpCode === 404) {
                return ['abstract' => null, 'keywords' => null];
            }
            throw $e;
        }
    }

    throw new RuntimeException(
        "Scopus abstract fetch failed after 3 attempts: " . ($last_exception ? $last_exception->getMessage() : 'unknown error')
    );
}

/**
 * Phase 9 (Auto-Quartile): fetch a journal's CiteScore Quartile from the
 * Scopus Serial Title API by ISSN, using the `view=CITESCORE` parameter -
 * the plain STANDARD/ENHANCED views only return raw SJR/CiteScore numbers,
 * not the per-subject percentile that quartile is actually derived from
 * (verified live against multiple real ISSNs, including The Lancet, before
 * this was written - see .planning/ROADMAP.md Phase 9 for the walkthrough).
 *
 * Quartile is computed from CiteScore Percentile using Elsevier's own
 * published formula (Q1 >=75th percentile, Q2 >=50th, Q3 >=25th, Q4 below) -
 * not an invented heuristic. A journal can carry a different percentile per
 * ASJC subject area it's classified under; this takes the highest
 * percentile across all of them (most favorable quartile), matching how
 * `journal_quartiles` stores one quartile per journal, not per subject.
 * Leadership decision (2026-08-20): this replaces SCImago/SJR quartile as
 * the faculty's sole quartile source going forward.
 *
 * @return ?array{title: ?string, quartile: ?string, percentile: ?float, citescore: ?float, year: ?string}
 *   null if the ISSN isn't found in Scopus at all; quartile/percentile/citescore
 *   individually null if found but CiteScore hasn't been computed for it yet
 *   (e.g. a brand new journal with no citation history)
 * @throws ApiTimeoutException|ApiRateLimitException|ApiHttpException
 */
function fetch_scopus_citescore_quartile($issn) {
    $clean_issn = preg_replace('/[^0-9Xx]/', '', (string)$issn);
    if (strlen($clean_issn) < 8) {
        return null;
    }

    $api_key = defined('SCOPUS_API_KEY') ? SCOPUS_API_KEY : null;
    if (empty($api_key)) {
        throw new RuntimeException('ไม่พบ Scopus API Key');
    }

    $url = "https://api.elsevier.com/content/serial/title/issn/" . urlencode($clean_issn) . "?view=CITESCORE";
    $data = http_get_json($url, ["X-ELS-APIKey: {$api_key}"], 10);

    $entry = $data['serial-metadata-response']['entry'][0] ?? null;
    if (!$entry) {
        return null;
    }

    $title = $entry['dc:title'] ?? null;
    $years = $entry['citeScoreYearInfoList']['citeScoreYearInfo'] ?? [];

    $years_map = [];
    $latest_complete_year = null;

    foreach ($years as $y) {
        $year_str = isset($y['@year']) ? (string)$y['@year'] : '';
        if (empty($year_str)) {
            continue;
        }
        $status = $y['@status'] ?? '';
        $info = $y['citeScoreInformationList'][0]['citeScoreInfo'][0] ?? null;
        $citescore = isset($info['citeScore']) ? (float)$info['citeScore'] : null;

        $best_percentile = null;
        foreach (($info['citeScoreSubjectRank'] ?? []) as $sr) {
            if (!isset($sr['percentile'])) {
                continue;
            }
            $p = (float)$sr['percentile'];
            if ($best_percentile === null || $p > $best_percentile) {
                $best_percentile = $p;
            }
        }

        $quartile = null;
        if ($best_percentile !== null) {
            if ($best_percentile >= 75) {
                $quartile = 'Q1';
            } elseif ($best_percentile >= 50) {
                $quartile = 'Q2';
            } elseif ($best_percentile >= 25) {
                $quartile = 'Q3';
            } else {
                $quartile = 'Q4';
            }
        }

        $years_map[$year_str] = [
            'quartile' => $quartile,
            'percentile' => $best_percentile,
            'citescore' => $citescore,
            'status' => $status,
        ];

        if ($latest_complete_year === null && $status === 'Complete') {
            $latest_complete_year = $year_str;
        }
    }

    if ($latest_complete_year === null && !empty($years_map)) {
        $latest_complete_year = array_key_first($years_map);
    }

    if (empty($years_map)) {
        return ['title' => $title, 'quartile' => null, 'percentile' => null, 'citescore' => null, 'year' => null, 'years' => []];
    }

    $chosen_info = $years_map[$latest_complete_year] ?? [
        'quartile' => null,
        'percentile' => null,
        'citescore' => null,
    ];

    return [
        'title' => $title,
        'quartile' => $chosen_info['quartile'],
        'percentile' => $chosen_info['percentile'],
        'citescore' => $chosen_info['citescore'],
        'year' => $latest_complete_year,
        'years' => $years_map,
    ];
}

/**
 * fetch_scopus_citescore_quartile() wrapped with the same retry contract as
 * the rest of this file's external fetchers. A 404 (ISSN not in Scopus at
 * all) is graceful absence, not an error.
 *
 * @throws RuntimeException if all 3 attempts are exhausted on a retryable error
 */
function fetch_scopus_citescore_quartile_with_retry($issn) {
    $backoff_schedule = [5, 15, 45];
    $last_exception = null;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            return fetch_scopus_citescore_quartile($issn);
        } catch (ApiRateLimitException $e) {
            $wait = $e->retryAfterSeconds !== null ? $e->retryAfterSeconds : 60;
            error_log("[scopus][citescore] rate limited (429), waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiTimeoutException $e) {
            $wait = $backoff_schedule[$attempt] ?? 45;
            error_log("[scopus][citescore] timeout/connection error, waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiHttpException $e) {
            if ($e->httpCode === 404) {
                return null;
            }
            throw $e;
        }
    }

    throw new RuntimeException(
        "Scopus CiteScore fetch failed after 3 attempts: " . ($last_exception ? $last_exception->getMessage() : 'unknown error')
    );
}

/**
 * Phase 7 (RCR-01): resolve a publication's DOI to its PubMed ID (PMID) via
 * NCBI's ESearch E-utility against the `pubmed` database directly. This is
 * a hard prerequisite for the NIH iCite RCR lookup below - iCite is
 * PMID-only, there is no DOI lookup path.
 *
 * Deliberately NOT the PMC ID Converter (`pmc/utils/idconv`), despite that
 * looking like the obvious "ID converter" tool: idconv only resolves
 * articles deposited in PMC (PubMed Central's full-text archive), and
 * returns "not found" for the large fraction of PubMed-indexed articles
 * that have a PMID but were never deposited in PMC - verified against a
 * real DOI where idconv said "Identifier not found in PMC" while esearch
 * correctly found its PMID. ESearch searches PubMed's own metadata
 * (including its DOI field via `[doi]`), so it covers everything with a
 * PMID, not just the PMC subset.
 *
 * @return ?string the resolved PMID, or null if not found (no DOI, or the
 *   DOI isn't indexed in PubMed at all - common, since PubMed only covers
 *   biomedical/health literature, so non-MEDLINE-indexed journals
 *   genuinely have no PMID)
 * @throws ApiTimeoutException|ApiRateLimitException|ApiHttpException
 */
function fetch_pmid_from_doi($doi) {
    if (empty($doi)) {
        return null;
    }

    $params = [
        'db' => 'pubmed',
        'term' => $doi . '[doi]',
        'retmode' => 'json',
        'tool' => 'msc_research',
        'email' => 'msc_research@up.ac.th',
    ];
    $url = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?" . http_build_query($params);

    $data = http_get_json($url, [], 10);

    $ids = $data['esearchresult']['idlist'] ?? [];
    if (empty($ids)) {
        return null;
    }
    return (string)$ids[0];
}

/**
 * fetch_pmid_from_doi() wrapped with the same SYNC-01/02-style retry
 * contract used elsewhere (timeout: 3x with 5s/15s/45s backoff; 429: wait
 * per header or 60s fallback). A 404 is graceful absence (RCR-04), not an
 * error - returns null so the caller can mark this publication "checked,
 * no PMID" rather than fail the batch.
 *
 * @throws RuntimeException if all 3 attempts are exhausted on a retryable error
 */
function fetch_pmid_from_doi_with_retry($doi) {
    $backoff_schedule = [5, 15, 45];
    $last_exception = null;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            return fetch_pmid_from_doi($doi);
        } catch (ApiRateLimitException $e) {
            $wait = $e->retryAfterSeconds !== null ? $e->retryAfterSeconds : 60;
            error_log("[ncbi][idconv] rate limited (429), waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiTimeoutException $e) {
            $wait = $backoff_schedule[$attempt] ?? 45;
            error_log("[ncbi][idconv] timeout/connection error, waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiHttpException $e) {
            if ($e->httpCode === 404) {
                return null;
            }
            throw $e;
        }
    }

    throw new RuntimeException(
        "NCBI ID Converter fetch failed after 3 attempts: " . ($last_exception ? $last_exception->getMessage() : 'unknown error')
    );
}

/**
 * Phase 7 (RCR-02): fetch the Relative Citation Ratio and NIH percentile
 * for a PMID from the NIH iCite API.
 *
 * @return array{rcr: ?float, nih_percentile: ?float}
 * @throws ApiTimeoutException|ApiRateLimitException|ApiHttpException
 */
function fetch_icite_rcr($pmid) {
    if (empty($pmid)) {
        return ['rcr' => null, 'nih_percentile' => null];
    }

    $url = "https://icite.od.nih.gov/api/pubs?pmids=" . urlencode($pmid);
    $data = http_get_json($url, [], 10);

    $record = $data['data'][0] ?? null;
    if (!$record) {
        // iCite returns an empty `data` array (not a 404) for a PMID it
        // has no record for.
        return ['rcr' => null, 'nih_percentile' => null];
    }

    return [
        'rcr' => isset($record['relative_citation_ratio']) ? (float)$record['relative_citation_ratio'] : null,
        'nih_percentile' => isset($record['nih_percentile']) ? (float)$record['nih_percentile'] : null,
    ];
}

/**
 * fetch_icite_rcr() wrapped with the same retry contract as the rest of
 * this file's external fetchers.
 *
 * @throws RuntimeException if all 3 attempts are exhausted on a retryable error
 */
function fetch_icite_rcr_with_retry($pmid) {
    $backoff_schedule = [5, 15, 45];
    $last_exception = null;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            return fetch_icite_rcr($pmid);
        } catch (ApiRateLimitException $e) {
            $wait = $e->retryAfterSeconds !== null ? $e->retryAfterSeconds : 60;
            error_log("[icite][rcr] rate limited (429), waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiTimeoutException $e) {
            $wait = $backoff_schedule[$attempt] ?? 45;
            error_log("[icite][rcr] timeout/connection error, waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiHttpException $e) {
            if ($e->httpCode === 404) {
                return ['rcr' => null, 'nih_percentile' => null];
            }
            throw $e;
        }
    }

    throw new RuntimeException(
        "NIH iCite fetch failed after 3 attempts: " . ($last_exception ? $last_exception->getMessage() : 'unknown error')
    );
}

/**
 * Phase 7 (RCR-05): render a small badge showing a publication's Relative
 * Citation Ratio (+ NIH percentile if available). Returns '' if no RCR is
 * stored (RCR-04: graceful absence - callers simply don't show a badge
 * rather than showing an empty/placeholder one).
 *
 * Color follows NIH's own framing of RCR: 1.0 is the field-normalized
 * benchmark (an "average" NIH-funded paper in the same field/year), so
 * >=1.0 reads as at-or-above benchmark (green) and below reads amber.
 */
function render_rcr_badge($rcr, $percentile = null, $font_size = '0.72rem') {
    if ($rcr === null || $rcr === '') {
        return '';
    }
    $rcr_val = (float)$rcr;
    $color = $rcr_val >= 1.0 ? '#10b981' : '#f59e0b';
    $percentile_suffix = ($percentile !== null && $percentile !== '') ? (' · เปอร์เซ็นไทล์ ' . round((float)$percentile)) : '';

    return '<span class="badge" title="Relative Citation Ratio (NIH iCite)" style="font-size: ' . $font_size . '; background: ' . $color . '22; color: ' . $color . '; border: 1px solid ' . $color . '55; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 4px;">'
        . '<i class="fa-solid fa-chart-line" style="font-size: 0.85em;"></i> RCR ' . number_format($rcr_val, 2) . htmlspecialchars($percentile_suffix)
        . '</span>';
}

/**
 * Phase 8 (Topic Prominence & Trends): resolve a publication's OpenAlex
 * work record by DOI and extract its topic classification.
 *
 * Unlike the Scopus/NIH-iCite integrations elsewhere in this file,
 * OpenAlex's Works API is DOI-native (`/works/doi:{doi}`) - no PMID or any
 * other ID-conversion step is needed first. A `mailto` query param is sent
 * per OpenAlex's "polite pool" convention (higher, more reliable rate
 * limits for identified requests); `OPENALEX_API_KEY` is optional (empty
 * string works fine for anonymous access - see config/db.php).
 *
 * @return array{openalex_id: ?string, topics: array} topics is a list of
 *   ['topic_id','display_name','subfield','field','domain','score','is_primary']
 * @throws ApiTimeoutException|ApiRateLimitException|ApiHttpException
 */
function fetch_openalex_work_topics($doi) {
    if (empty($doi)) {
        return ['openalex_id' => null, 'topics' => []];
    }

    $params = ['mailto' => 'msc_research@up.ac.th'];
    if (defined('OPENALEX_API_KEY') && OPENALEX_API_KEY !== '') {
        $params['api_key'] = OPENALEX_API_KEY;
    }
    $url = "https://api.openalex.org/works/doi:" . urlencode($doi) . '?' . http_build_query($params);

    $data = http_get_json($url, [], 10);

    $openalex_id = $data['id'] ?? null;
    $primary_id = $data['primary_topic']['id'] ?? null;
    $topics = [];
    foreach (($data['topics'] ?? []) as $t) {
        $topics[] = [
            'topic_id' => $t['id'] ?? '',
            'display_name' => $t['display_name'] ?? '',
            'subfield' => $t['subfield']['display_name'] ?? null,
            'field' => $t['field']['display_name'] ?? null,
            'domain' => $t['domain']['display_name'] ?? null,
            'score' => isset($t['score']) ? (float)$t['score'] : null,
            'is_primary' => ($primary_id !== null && ($t['id'] ?? null) === $primary_id) ? 1 : 0,
        ];
    }

    return ['openalex_id' => $openalex_id, 'topics' => $topics];
}

/**
 * fetch_openalex_work_topics() wrapped with the same SYNC-01/02-style retry
 * contract used elsewhere (timeout: 3x with 5s/15s/45s backoff; 429: wait
 * per header or 60s fallback). A 404 (OpenAlex has no work for this DOI -
 * not uncommon, e.g. very new or non-indexed publications) is graceful
 * absence (TOPIC-04), not an error - returns an empty result so the caller
 * can mark this publication "checked, no match" rather than fail the batch.
 *
 * @throws RuntimeException if all 3 attempts are exhausted on a retryable error
 */
function fetch_openalex_work_topics_with_retry($doi) {
    $backoff_schedule = [5, 15, 45];
    $last_exception = null;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            return fetch_openalex_work_topics($doi);
        } catch (ApiRateLimitException $e) {
            $wait = $e->retryAfterSeconds !== null ? $e->retryAfterSeconds : 60;
            error_log("[openalex][topics] rate limited (429), waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiTimeoutException $e) {
            $wait = $backoff_schedule[$attempt] ?? 45;
            error_log("[openalex][topics] timeout/connection error, waiting {$wait}s before retry " . ($attempt + 1) . "/3");
            sleep($wait);
            $last_exception = $e;
        } catch (ApiHttpException $e) {
            if ($e->httpCode === 404) {
                return ['openalex_id' => null, 'topics' => []];
            }
            throw $e;
        }
    }

    throw new RuntimeException(
        "OpenAlex topic fetch failed after 3 attempts: " . ($last_exception ? $last_exception->getMessage() : 'unknown error')
    );
}

/**
 * Phase 6 (SDG-06b/06c): score a publication's title/keywords/abstract
 * against the bundled SDG keyphrase dictionary (data/sdg_data.json, a copy
 * of the in-house msc_sdgs tool's dictionary - see
 * .planning/research/SDG_MAPPING_SYSTEM.md).
 *
 * Ports the exact same algorithm the msc_sdgs tool uses client-side
 * (matchSDG() in its app_template.php), so results are consistent with what
 * that tool would show for the same text: a keyphrase matching in full
 * scores its full relevance weight; a multi-word keyphrase with >=60% of
 * its individual words present scores a discounted partial credit
 * (rel * word_fraction * 0.4). msc_sdgs's own tool also takes intro/
 * discussion/conclusion text, which Scopus metadata doesn't provide - this
 * necessarily works with a weaker signal (title + keywords + abstract only).
 *
 * @return array List of ['num','name','name_th','color','score','matched'] sorted by score descending, score > 0 only
 */
/**
 * Check whether an abstract string is genuine abstract text or merely a
 * document-type placeholder (e.g. 'Article', 'Review', 'Book Chapter') left over
 * from older sync code.
 */
function is_valid_abstract($text) {
    if (empty($text)) return false;
    $trimmed = trim((string)$text);
    if (mb_strlen($trimmed) < 40) return false;
    $placeholders = ['article', 'review', 'book chapter', 'conference paper', 'short survey', 'letter', 'editorial', 'note', 'erratum', 'data paper'];
    if (in_array(mb_strtolower($trimmed), $placeholders)) return false;
    return true;
}

/**
 * Resolves and returns the active SDG dictionary and its source metadata.
 * Prioritizes live dictionary from msc_sdgs (C:/inetpub/wwwroot/msc_sdgs/sdg_data.json),
 * falling back to the bundled copy (data/sdg_data.json) if unreadable.
 *
 * @return array ['source' => 'live'|'bundled'|'none', 'path' => string, 'data' => array, 'count' => int, 'label' => string]
 */
function get_sdg_dictionary_info() {
    static $info = null;
    if ($info !== null) {
        return $info;
    }

    $candidates = [
        'live' => [
            'C:/inetpub/wwwroot/msc_sdgs/sdg_data.json',
            dirname(__DIR__) . '/../msc_sdgs/sdg_data.json',
        ],
        'bundled' => [
            __DIR__ . '/../data/sdg_data.json',
        ]
    ];

    foreach ($candidates['live'] as $path) {
        if (file_exists($path) && is_readable($path)) {
            $content = @file_get_contents($path);
            $data = $content ? json_decode($content, true) : null;
            if (is_array($data) && !empty($data)) {
                $info = [
                    'source' => 'live',
                    'path' => $path,
                    'data' => $data,
                    'count' => count($data),
                    'label' => 'Live: msc_sdgs'
                ];
                return $info;
            }
        }
    }

    foreach ($candidates['bundled'] as $path) {
        if (file_exists($path) && is_readable($path)) {
            $content = @file_get_contents($path);
            $data = $content ? json_decode($content, true) : null;
            if (is_array($data) && !empty($data)) {
                $info = [
                    'source' => 'bundled',
                    'path' => $path,
                    'data' => $data,
                    'count' => count($data),
                    'label' => 'Bundled copy'
                ];
                return $info;
            }
        }
    }

    $info = [
        'source' => 'none',
        'path' => '',
        'data' => [],
        'count' => 0,
        'label' => 'None'
    ];
    return $info;
}

function score_publication_sdgs($title, $keywords, $abstract) {
    $info = get_sdg_dictionary_info();
    $sdg_data = $info['data'];
    if (empty($sdg_data)) {
        return [];
    }

    $clean = function ($s) {
        $s = strtolower((string)($s ?? ''));
        $s = preg_replace('/[^a-z0-9\s]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return ' ' . trim($s) . ' ';
    };

    $text = $clean(($title ?? '') . ' ' . ($keywords ?? '') . ' ' . ($abstract ?? ''));
    if (trim($text) === '') {
        return [];
    }

    $results = [];
    foreach ($sdg_data as $sdg) {
        $score = 0.0;
        $matched = [];
        foreach (($sdg['keyphrases'] ?? []) as $kp) {
            $phrase = trim($clean($kp['k'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $words = array_values(array_filter(explode(' ', $phrase), function ($w) {
                return strlen($w) > 2;
            }));
            $rel = (float)($kp['rel'] ?? 0);

            if (strpos($text, ' ' . $phrase . ' ') !== false) {
                $score += $rel;
                $matched[] = ['k' => $kp['k'], 'rel' => $rel, 'full' => true];
            } elseif (count($words) > 1) {
                $hit = 0;
                foreach ($words as $w) {
                    if (strpos($text, ' ' . $w . ' ') !== false) {
                        $hit++;
                    }
                }
                $frac = $hit / count($words);
                if ($frac >= 0.6) {
                    $score += $rel * $frac * 0.4;
                    $matched[] = ['k' => $kp['k'], 'rel' => $rel, 'full' => false];
                }
            }
        }

        if ($score > 0) {
            usort($matched, function ($a, $b) {
                return ($b['full'] <=> $a['full']) ?: ($b['rel'] <=> $a['rel']);
            });
            $results[] = [
                'num' => (int)($sdg['num'] ?? 0),
                'name' => $sdg['name'] ?? '',
                'name_th' => $sdg['name_th'] ?? '',
                'color' => $sdg['color'] ?? '#64748b',
                'score' => $score,
                'matched' => $matched,
            ];
        }
    }

    usort($results, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return $results;
}

/**
 * Format timestamp into Thai datetime format
 */
function format_thai_datetime($datetime) {
    if (!$datetime) return 'ยังไม่มีการซิงค์ข้อมูล';
    $time = strtotime($datetime);
    $months = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
        7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    $day = date('j', $time);
    $month = $months[(int)date('n', $time)];
    $year = (int)date('Y', $time) + 543;
    $time_str = date('H:i', $time) . ' น.';
    return "$day $month $year - $time_str";
}

/**
 * Get official details (name, color) for an SDG code (SDG 1 - SDG 17)
 */
function get_sdg_badge_details($sdg_code) {
    if (empty($sdg_code)) return null;
    $sdg_code = trim(strtoupper($sdg_code));

    // SDG-03/REPORT-06: publications with no SDG match are their own
    // reportable category, not just absent from every SDG's count.
    if ($sdg_code === 'UNCLASSIFIED') {
        return ['name' => 'Unclassified', 'th_name' => 'ยังไม่จำแนก SDG', 'color' => '#64748b'];
    }

    $num = (int)preg_replace('/[^0-9]/', '', $sdg_code);
    
    $sdgs = [
        1 => ['name' => 'No Poverty', 'th_name' => 'ขจัดความยากจน', 'color' => '#e5243b'],
        2 => ['name' => 'Zero Hunger', 'th_name' => 'ขจัดความหิวโหย', 'color' => '#dda63a'],
        3 => ['name' => 'Good Health and Well-being', 'th_name' => 'การมีสุขภาพและความเป็นอยู่ที่ดี', 'color' => '#4c9f38'],
        4 => ['name' => 'Quality Education', 'th_name' => 'การศึกษาที่เท่าเทียมและทั่วถึง', 'color' => '#c5192d'],
        5 => ['name' => 'Gender Equality', 'th_name' => 'ความเท่าเทียมทางเพศ', 'color' => '#ff3a21'],
        6 => ['name' => 'Clean Water and Sanitation', 'th_name' => 'การจัดการน้ำและสุขาภิบาล', 'color' => '#26bde2'],
        7 => ['name' => 'Affordable and Clean Energy', 'th_name' => 'พลังงานสะอาดที่ทุกคนเข้าถึงได้', 'color' => '#fcc30b'],
        8 => ['name' => 'Decent Work and Economic Growth', 'th_name' => 'งานที่มีคุณค่าและการเติบโตทางเศรษฐกิจ', 'color' => '#a21942'],
        9 => ['name' => 'Industry, Innovation and Infrastructure', 'th_name' => 'อุตสาหกรรม นวัตกรรม และโครงสร้างพื้นฐาน', 'color' => '#fd6925'],
        10 => ['name' => 'Reduced Inequalities', 'th_name' => 'ลดความเหลื่อมล้ำ', 'color' => '#dd1367'],
        11 => ['name' => 'Sustainable Cities and Communities', 'th_name' => 'เมืองและชุมชนที่ยั่งยืน', 'color' => '#fd9d24'],
        12 => ['name' => 'Responsible Consumption and Production', 'th_name' => 'การผลิตและการบริโภคที่รับผิดชอบ', 'color' => '#bf8d2c'],
        13 => ['name' => 'Climate Action', 'th_name' => 'การรับมือกับการเปลี่ยนแปลงสภาพภูมิอากาศ', 'color' => '#3f7e44'],
        14 => ['name' => 'Life Below Water', 'th_name' => 'การใช้ประโยชน์จากทรัพยากรทางทะเลอย่างยั่งยืน', 'color' => '#0a97d9'],
        15 => ['name' => 'Life on Land', 'th_name' => 'การใช้ประโยชน์จากระบบนิเวศทางบกอย่างยั่งยืน', 'color' => '#56c02b'],
        16 => ['name' => 'Peace, Justice and Strong Institutions', 'th_name' => 'สังคมสงบสุข ยุติธรรม และสถาบันที่เข้มแข็ง', 'color' => '#00689d'],
        17 => ['name' => 'Partnerships for the Goals', 'th_name' => 'ความร่วมมือเพื่อการพัฒนาที่ยั่งยืน', 'color' => '#19486a']
    ];
    
    return $sdgs[$num] ?? null;
}

/**
 * Get list of all 17 SDGs
 */
function get_all_sdgs() {
    return [
        'SDG 1' => ['name' => 'No Poverty', 'th_name' => 'ขจัดความยากจน', 'color' => '#e5243b'],
        'SDG 2' => ['name' => 'Zero Hunger', 'th_name' => 'ขจัดความหิวโหย', 'color' => '#dda63a'],
        'SDG 3' => ['name' => 'Good Health and Well-being', 'th_name' => 'การมีสุขภาพและความเป็นอยู่ที่ดี', 'color' => '#4c9f38'],
        'SDG 4' => ['name' => 'Quality Education', 'th_name' => 'การศึกษาที่เท่าเทียมและทั่วถึง', 'color' => '#c5192d'],
        'SDG 5' => ['name' => 'Gender Equality', 'th_name' => 'ความเท่าเทียมทางเพศ', 'color' => '#ff3a21'],
        'SDG 6' => ['name' => 'Clean Water and Sanitation', 'th_name' => 'การจัดการน้ำและสุขาภิบาล', 'color' => '#26bde2'],
        'SDG 7' => ['name' => 'Affordable and Clean Energy', 'th_name' => 'พลังงานสะอาดที่ทุกคนเข้าถึงได้', 'color' => '#fcc30b'],
        'SDG 8' => ['name' => 'Decent Work and Economic Growth', 'th_name' => 'งานที่มีคุณค่าและการเติบโตทางเศรษฐกิจ', 'color' => '#a21942'],
        'SDG 9' => ['name' => 'Industry, Innovation and Infrastructure', 'th_name' => 'อุตสาหกรรม นวัตกรรม และโครงสร้างพื้นฐาน', 'color' => '#fd6925'],
        'SDG 10' => ['name' => 'Reduced Inequalities', 'th_name' => 'ลดความเหลื่อมล้ำ', 'color' => '#dd1367'],
        'SDG 11' => ['name' => 'Sustainable Cities and Communities', 'th_name' => 'เมืองและชุมชนที่ยั่งยืน', 'color' => '#fd9d24'],
        'SDG 12' => ['name' => 'Responsible Consumption and Production', 'th_name' => 'การผลิตและการบริโภคที่รับผิดชอบ', 'color' => '#bf8d2c'],
        'SDG 13' => ['name' => 'Climate Action', 'th_name' => 'การรับมือกับการเปลี่ยนแปลงสภาพภูมิอากาศ', 'color' => '#3f7e44'],
        'SDG 14' => ['name' => 'Life Below Water', 'th_name' => 'การใช้ประโยชน์จากทรัพยากรทางทะเลอย่างยั่งยืน', 'color' => '#0a97d9'],
        'SDG 15' => ['name' => 'Life on Land', 'th_name' => 'การใช้ประโยชน์จากระบบนิเวศทางบกอย่างยั่งยืน', 'color' => '#56c02b'],
        'SDG 16' => ['name' => 'Peace, Justice and Strong Institutions', 'th_name' => 'สังคมสงบสุข ยุติธรรม และสถาบันที่เข้มแข็ง', 'color' => '#00689d'],
        'SDG 17' => ['name' => 'Partnerships for the Goals', 'th_name' => 'ความร่วมมือเพื่อการพัฒนาที่ยั่งยืน', 'color' => '#19486a']
    ];
}

/**
 * Render a beautiful SDG badge (Primary: solid with white inner box; Secondary: outlined with colored inner box)
 */
function render_sdg_badge($sdg_code, $is_primary = true, $font_size = '0.72rem') {
    if (empty($sdg_code)) return '';
    $sdg_info = get_sdg_badge_details($sdg_code);
    if (!$sdg_info) return '';
    
    $color = $sdg_info['color'];
    $num = (int)preg_replace('/[^0-9]/', '', $sdg_code);
    
    $box_size = '15px';
    $box_font = '0.65rem';
    if ($font_size === '0.68rem' || $font_size === '0.7rem') {
        $box_size = '13px';
        $box_font = '0.58rem';
    }
    
    if ($is_primary) {
        return '<span class="badge" style="font-size: ' . $font_size . '; background: ' . $color . '; color: white; border: none; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px; border-radius: 4px; line-height: 1.2; height: fit-content; text-transform: uppercase;">
            <span style="background: white; color: ' . $color . '; width: ' . $box_size . '; height: ' . $box_size . '; border-radius: 2px; display: inline-flex; align-items: center; justify-content: center; font-size: ' . $box_font . '; font-weight: 900; line-height: 1; flex-shrink: 0;">' . $num . '</span>
            <span>' . htmlspecialchars($sdg_code) . '</span>
        </span>';
    } else {
        return '<span class="badge" style="font-size: ' . $font_size . '; background: transparent; color: ' . $color . '; border: 1px solid ' . $color . '; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; padding: 2px 7px; border-radius: 4px; line-height: 1.2; height: fit-content; text-transform: uppercase;">
            <span style="background: ' . $color . '; color: white; width: ' . $box_size . '; height: ' . $box_size . '; border-radius: 2px; display: inline-flex; align-items: center; justify-content: center; font-size: ' . $box_font . '; font-weight: 900; line-height: 1; flex-shrink: 0;">' . $num . '</span>
            <span>' . htmlspecialchars($sdg_code) . '</span>
        </span>';
    }
}