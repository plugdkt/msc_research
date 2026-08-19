<?php
// includes/functions.php
// Common functions and API integration logics

require_once __DIR__ . '/../config/db.php';

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
 * Get list of all researchers with their publication counts
 */
function get_all_researchers($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT r.*, COUNT(rp.publication_id) as pub_count, SUM(p.citation_count) as total_citations
            FROM `researchers` r
            LEFT JOIN `researcher_publications` rp ON r.id = rp.researcher_id
            LEFT JOIN `publications` p ON rp.publication_id = p.id
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
 * Throws a RuntimeException on network failure or a non-2xx HTTP status,
 * so callers can distinguish "API is down / rejected us" from
 * "API responded successfully with zero results".
 *
 * @throws RuntimeException
 */
function http_get($url, array $headers = [], $timeout = 10, $user_agent = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
    curl_setopt($ch, CURLOPT_USERAGENT, $user_agent ?: 'MSC-Research-System/1.0 (+https://www.medsci.up.ac.th; mailto:msc_research@up.ac.th)');
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    // SSL verification stays on for every outbound call (Scopus, ORCID, PubMed,
    // CrossRef, ...). If the Windows/IIS CA bundle is out of date, fix that at
    // the php.ini level (curl.cainfo pointing at an up-to-date cacert.pem)
    // rather than disabling verification here — that would silently accept
    // any TLS certificate, including a MITM's, for every API this app calls.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException("HTTP request failed ({$url}): curl error [{$errno}] {$error}");
    }
    if ($http_code < 200 || $http_code >= 300) {
        throw new RuntimeException("HTTP request failed ({$url}): HTTP status {$http_code}");
    }

    return $response;
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
                    ':sdg_rationale' => $sdg_rationale,
                    ':corresponding_author_id' => $corresponding_author_id,
                    ':id' => $pubId
                ]);
            } else {
                // Insert new
                $stmtInsert = $pdo->prepare("
                    INSERT INTO `publications` 
                    (title, authors, journal_name, journal_issn, quartile, countries, corresponding_author_id, funding_sponsor, funding_no, funding_amount, sdg_primary, sdg_secondary, sdg_rationale, publish_year, publish_date, doi, citation_count, source, source_id, url, abstract)
                    VALUES 
                    (:title, :authors, :journal_name, :journal_issn, :quartile, :countries, :corresponding_author_id, :funding_sponsor, :funding_no, :funding_amount, :sdg_primary, :sdg_secondary, :sdg_rationale, :publish_year, :publish_date, :doi, :citation_count, :source, :source_id, :url, :abstract)
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
 * Match an author-list string (as returned by an external API, e.g.
 * "Somchai P, Prayoon K, Deejai S") against a researcher's English profile
 * names.
 *
 * This is inherently a heuristic — author strings are formatted differently
 * by every provider — but it is meaningfully stricter than a plain substring
 * search: it splits the string into individual author entries and requires
 * the last name to appear as a whole word AND the first-name initial to
 * appear as a standalone token WITHIN THE SAME entry, rather than anywhere
 * in the entire author list. A plain `strpos($whole_string, $initial)` (the
 * previous implementation) matches almost any list because a single letter
 * is common; that check has been removed.
 *
 * For deterministic matching, prefer linking by ORCID iD ([auid] in PubMed,
 * AU-ID in Scopus) wherever the data source supports it — this name-based
 * matcher should be treated as a fallback for sources that don't expose
 * a stable per-author identifier.
 */
function match_author_to_researcher($author_string, $first_name_en, $last_name_en) {
    $last_name = trim($last_name_en);
    $first_initial = strtoupper(substr(trim($first_name_en), 0, 1));

    if ($last_name === '' || $first_initial === '' || empty($author_string)) {
        return false;
    }

    // Split the combined author string into individual author entries on
    // comma/semicolon. Some formats separate the last name and initials
    // with their own comma (e.g. "Deejai, S"), so re-attach a short
    // ALL-CAPS-looking token (likely initials) back onto the previous entry.
    $raw_entries = preg_split('/\s*[,;]\s*/', $author_string);
    $entries = [];
    foreach ($raw_entries as $token) {
        $token = trim($token);
        if ($token === '') continue;
        if (preg_match('/^[A-Za-z]{1,3}\.?$/', $token) && !empty($entries)) {
            $entries[count($entries) - 1] .= ' ' . $token;
        } else {
            $entries[] = $token;
        }
    }

    $last_name_pattern = preg_quote($last_name, '/');
    $initial_pattern = preg_quote($first_initial, '/');

    foreach ($entries as $entry) {
        // Last name must appear as a distinct whole word within this single author entry.
        if (!preg_match('/\b' . $last_name_pattern . '\b/iu', $entry)) {
            continue;
        }
        // First-name initial must appear as its own token (e.g. "P", "P.", "PK"),
        // still scoped to this one author entry rather than the whole author list.
        if (preg_match('/\b' . $initial_pattern . '[A-Za-z]{0,2}\.?\b/u', strtoupper($entry))) {
            return true;
        }
    }

    return false;
}

/**
 * Search and Sync publications by Affiliation/Query from PubMed, then strictly filter
 * and only save publications that belong to registered researchers.
 *
 * NOTE: this function no longer performs an automatic database-wide cleanup
 * of "orphaned" publications. A blanket
 *   DELETE FROM publications WHERE id NOT IN (SELECT publication_id FROM researcher_publications)
 * run as a side effect of every affiliation sync is dangerous: if this sync
 * runs concurrently with another sync that has inserted a publication but
 * not yet linked it to a researcher, that unrelated publication could be
 * deleted out from under it. Cleanup is now a separate, explicit,
 * admin-triggered function — see cleanup_orphaned_publications().
 */
function sync_affiliation_publications($pdo, $affiliation_query) {
    if (empty($affiliation_query)) return ['total_found' => 0, 'imported' => 0, 'linked' => 0];

    $pubs = fetch_pubmed_publications($affiliation_query);
    if (empty($pubs)) return ['total_found' => 0, 'imported' => 0, 'linked' => 0];

    // Fetch all registered researchers to match names
    $stmt = $pdo->query("SELECT id, first_name_en, last_name_en FROM `researchers`");
    $researchers = $stmt->fetchAll();

    $imported_count = 0;
    $linked_count = 0;

    foreach ($pubs as $pub) {
        $matched_researcher_ids = [];

        // 1. Check if the publication authors match any of our registered researchers
        foreach ($researchers as $r) {
            if (match_author_to_researcher($pub['authors'], $r['first_name_en'], $r['last_name_en'])) {
                $matched_researcher_ids[] = $r['id'];
            }
        }

        // 2. STRICT FILTER: Only save the publication if it matches at least one registered researcher
        if (!empty($matched_researcher_ids)) {
            $pubId = add_or_update_publication($pdo, $pub, null);

            if ($pubId) {
                $imported_count++;

                foreach ($matched_researcher_ids as $r_id) {
                    $stmtLinkCheck = $pdo->prepare("
                        SELECT 1 FROM `researcher_publications` 
                        WHERE researcher_id = ? AND publication_id = ?
                    ");
                    $stmtLinkCheck->execute([$r_id, $pubId]);
                    if (!$stmtLinkCheck->fetch()) {
                        $stmtLink = $pdo->prepare("
                            INSERT INTO `researcher_publications` (researcher_id, publication_id) 
                            VALUES (?, ?)
                        ");
                        $stmtLink->execute([$r_id, $pubId]);
                        $linked_count++;
                    }
                }
            }
        }
    }

    return [
        'total_found' => count($pubs),
        'imported' => $imported_count,
        'linked' => $linked_count
    ];
}

/**
 * Explicit, admin-triggered cleanup of publications with zero researcher links.
 *
 * Deliberately NOT called automatically from any sync function — run this
 * on demand (e.g. from a dedicated admin button) when you're confident no
 * other sync is running concurrently, to avoid the race condition described
 * in sync_affiliation_publications() above.
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
 * Fetch publications from PubMed using NCBI E-utilities API
 *
 * @throws RuntimeException on network/API failure (caller is expected to catch this)
 */
function fetch_pubmed_publications($query) {
    if (empty($query)) return [];

    $publications = [];
    $searchUrl = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&term=" . urlencode($query) . "&retmode=json&retmax=50";

    $data = http_get_json($searchUrl, [], 10);
    $ids = $data['esearchresult']['idlist'] ?? [];

    if (!empty($ids)) {
        $idString = implode(',', $ids);
        $summaryUrl = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&id={$idString}&retmode=json";
        $summaryData = http_get_json($summaryUrl, [], 10);
        $results = $summaryData['result'] ?? [];

        foreach ($ids as $id) {
            if (isset($results[$id])) {
                $pub = $results[$id];

                // Extract authors
                $authorsArr = [];
                if (isset($pub['authors'])) {
                    foreach ($pub['authors'] as $author) {
                        $authorsArr[] = $author['name'] ?? '';
                    }
                }
                $authors = implode(', ', array_filter($authorsArr));

                // Extract DOI
                $doi = '';
                if (isset($pub['articleids'])) {
                    foreach ($pub['articleids'] as $artid) {
                        if ($artid['idtype'] === 'doi') {
                            $doi = $artid['value'];
                            break;
                        }
                    }
                }

                // Parse publish year
                $pubDate = $pub['pubdate'] ?? '';
                $year = date('Y');
                if (preg_match('/\b(19|20)\d{2}\b/', $pubDate, $matches)) {
                    $year = $matches[0];
                }

                $issn = !empty($pub['issn']) ? trim($pub['issn']) : (!empty($pub['essn']) ? trim($pub['essn']) : null);

                $publications[] = [
                    'title' => $pub['title'] ?? 'Untitled',
                    'authors' => !empty($authors) ? $authors : 'Unknown Authors',
                    'journal_name' => $pub['source'] ?? 'PubMed Document',
                    'journal_issn' => $issn,
                    'countries' => 'Thailand',
                    'publish_year' => $year,
                    'publish_date' => date('Y-m-d', strtotime($pubDate)),
                    'doi' => $doi,
                    'citation_count' => 0, // PubMed API doesn't return citation counts directly in summary
                    'source' => 'pubmed',
                    'source_id' => $id,
                    'url' => "https://pubmed.ncbi.nlm.nih.gov/{$id}/",
                    'abstract' => ''
                ];
            }
        }
    }

    return $publications;
}

/**
 * Fetch publications from ORCID Public API
 *
 * @throws RuntimeException on network/API failure (caller is expected to catch this)
 */
function fetch_orcid_publications($orcid_id) {
    if (empty($orcid_id)) return [];

    // Clean any hidden characters, non-breaking spaces, or whitespace
    $orcid_id = preg_replace('/[^0-9\-X]/i', '', trim($orcid_id));
    if (!preg_match('/^\d{4}-\d{4}-\d{4}-[\dX]{4}$/', $orcid_id)) {
        throw new InvalidArgumentException("Invalid ORCID iD format: {$orcid_id}");
    }

    $url = "https://pub.orcid.org/v3.0/{$orcid_id}/works";
    $data = http_get_json($url, [], 10);
    $works = $data['group'] ?? [];

    $publications = [];
    foreach ($works as $group) {
        $workSummary = $group['work-summary'][0] ?? null;
        if ($workSummary) {
            $title = $workSummary['title']['title']['value'] ?? 'Untitled';

            $doi = null;
            if (isset($workSummary['external-ids']['external-id'])) {
                foreach ($workSummary['external-ids']['external-id'] as $extId) {
                    if ($extId['external-id-type'] === 'doi') {
                        $doi = $extId['external-id-value'] ?? null;
                        break;
                    }
                }
            }

            $year = $workSummary['publication-date']['year']['value'] ?? date('Y');
            $month = $workSummary['publication-date']['month']['value'] ?? '01';
            $day = $workSummary['publication-date']['day']['value'] ?? '01';
            $pubDate = "{$year}-{$month}-{$day}";

            $journalName = $workSummary['journal-title']['value'] ?? 'ORCID Registry';

            $url_field = null;
            if (isset($workSummary['url']['value'])) {
                $url_field = trim($workSummary['url']['value']);
            }

            if (empty($url_field)) {
                if (!empty($doi)) {
                    $url_field = "https://doi.org/" . trim($doi);
                } else {
                    $url_field = "https://orcid.org/{$orcid_id}";
                }
            }

            // ORCID works do not list full author arrays in the summary view.
            $publications[] = [
                'title' => $title,
                'authors' => 'ORCID Contributor',
                'journal_name' => $journalName,
                'publish_year' => $year,
                'publish_date' => $pubDate,
                'doi' => $doi,
                'citation_count' => 0,
                'source' => 'orcid',
                'source_id' => $workSummary['put-code'] ?? null,
                'url' => $url_field,
                'abstract' => ''
            ];
        }
    }

    return $publications;
}

/**
 * Fetch publications from Scopus (requires an Elsevier API Key).
 *
 * IMPORTANT: this function no longer returns simulated/mock data when a key
 * is missing or the request fails. Returning fake publication records that
 * get saved and attributed to a real researcher is a data-integrity and
 * reputational risk (a real person's public profile would show research
 * that doesn't exist). Missing key or failed request now throws, so the
 * caller (sync.php) logs a clear failure instead of silently inserting
 * fabricated data.
 *
 * @throws RuntimeException if no API key is configured or the request fails
 */
function fetch_scopus_publications($scopus_author_id, $api_key = null) {
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

            $coverDate = $entry['prism:coverDate'] ?? '';
            $year = !empty($coverDate) ? date('Y', strtotime($coverDate)) : date('Y');

            $doi = !empty($entry['prism:doi']) ? trim($entry['prism:doi']) : null;
            $scopus_link = null;

            if (!empty($doi)) {
                $scopus_link = "https://doi.org/" . $doi;
            } elseif (isset($entry['link']) && is_array($entry['link'])) {
                foreach ($entry['link'] as $lnk) {
                    if (isset($lnk['@ref']) && $lnk['@ref'] === 'scopus') {
                        $scopus_link = $lnk['@href'] ?? null;
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
                'authors' => $entry['dc:creator'] ?? 'Scopus Author',
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
 * Fetch publications from a Google Scholar public profile.
 *
 * ⚠️ Scraping Google Scholar is against Google's Terms of Service and is
 * inherently fragile (layout changes, CAPTCHA/rate-limit walls appear
 * without warning). This function no longer falls back to fabricated mock
 * data on failure — a blocked or failed scrape now throws, so it shows up
 * as a clear "ล้มเหลว" entry in the sync log rather than silently inserting
 * publications that were never actually verified against the profile.
 *
 * Recommended longer-term fix (as discussed): stop auto-syncing Google
 * Scholar entirely and instead store just the profile URL, showing it as a
 * "View Google Scholar profile" link on the researcher's page.
 *
 * @throws RuntimeException if the request fails or the page appears to be
 *                           a block/CAPTCHA page rather than a real profile
 */
function fetch_scholar_publications($scholar_id) {
    if (empty($scholar_id)) return [];

    $scholar_id = trim($scholar_id);
    if (preg_match('/user=([^&]+)/', $scholar_id, $matches)) {
        $scholar_id = $matches[1];
    } else {
        $parts = explode('&', $scholar_id);
        $scholar_id = trim($parts[0]);
    }

    if (empty($scholar_id)) return [];

    $url = "https://scholar.google.com/citations?user=" . urlencode($scholar_id) . "&hl=th&cstart=0&pagesize=50";

    $html = http_get($url, [], 8, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

    // Detect common block/CAPTCHA indicators rather than silently treating
    // a blocked request as "zero publications".
    $lower_html = strtolower($html);
    if (strpos($lower_html, 'gs_captcha') !== false
        || strpos($lower_html, 'sorry, we can') !== false
        || strpos($lower_html, 'unusual traffic') !== false) {
        throw new RuntimeException('Google Scholar บล็อกการเข้าถึงอัตโนมัติ (ตรวจพบหน้า CAPTCHA/ป้องกันการเข้าถึงอัตโนมัติ)');
    }

    if (strpos($html, 'gsc_a_tr') === false) {
        // Page loaded but doesn't look like a citations profile page at all
        // (e.g. wrong user ID, profile is private, or Google changed markup).
        throw new RuntimeException('ไม่พบข้อมูลผลงานในหน้าโปรไฟล์ Google Scholar (ตรวจสอบว่า Scholar ID ถูกต้องและโปรไฟล์เป็นสาธารณะ)');
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $publications = [];
    $rows = $xpath->query("//tr[@class='gsc_a_tr']");
    foreach ($rows as $row) {
        $titleNode = $xpath->query(".//a[@class='gsc_a_at']", $row)->item(0);
        $metaNodes = $xpath->query(".//div[@class='gs_gray']", $row);
        $citeNode = $xpath->query(".//a[@class='gsc_a_ac gs_ibl']", $row)->item(0);
        $yearNode = $xpath->query(".//span[@class='gsc_a_h gsc_a_hc gs_ibl']", $row)->item(0);

        if ($titleNode) {
            $title = trim($titleNode->textContent);
            $authors = $metaNodes->item(0) ? trim($metaNodes->item(0)->textContent) : 'Google Scholar Contributor';
            $journal = $metaNodes->item(1) ? trim($metaNodes->item(1)->textContent) : 'Google Scholar';
            $citations = $citeNode ? (int)trim($citeNode->textContent) : 0;
            $year = $yearNode ? (int)trim($yearNode->textContent) : date('Y');

            $publications[] = [
                'title' => $title,
                'authors' => $authors,
                'journal_name' => $journal,
                'publish_year' => $year,
                'publish_date' => null,
                'doi' => null,
                'citation_count' => $citations,
                'source' => 'google_scholar',
                'source_id' => null,
                'url' => "https://scholar.google.com" . $titleNode->getAttribute('href'),
                'abstract' => ''
            ];
        }
    }

    // A genuinely empty-but-real profile (0 publications) is possible but rare;
    // we no longer substitute fabricated data here regardless of outcome.
    return $publications;
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