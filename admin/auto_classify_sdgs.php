<?php
// admin/auto_classify_sdgs.php
// Batch auto-classify ALL unclassified publications against the SDG
// keyphrase dictionary in one click. Unlike admin/suggest_sdgs.php (admin
// reviews a suggestion, then saves it manually), this endpoint WRITES
// sdg_primary/sdg_secondary/sdg_tertiary/sdg_rationale directly and
// unattended - the
// user explicitly asked for one-click, whole-faculty batch classification,
// which isn't practical to review publication-by-publication.
//
// Runs as a one-publication-per-request loop, driven by JS in
// admin/sdg_import.php, rather than one big server-side loop over
// hundreds of publications: each unclassified publication needs its own
// Scopus Abstract Retrieval API call (no batch-lookup endpoint exists), so
// looping all of them inside a single HTTP request would very likely
// exceed IIS's FastCGI request timeout - the exact risk this project's own
// stack notes already flag for the main sync job. Short, cheap per-item
// requests avoid that entirely, and each publication commits independently,
// so the whole batch is naturally resumable if interrupted partway.
//
// Safety: a publication only gets written to when its top match score
// clears MIN_AUTO_APPLY_SCORE, and only if it has no existing SDG tag
// (never overwrites a human or prior auto-classify decision). This
// protects the project's Core Value (data accuracy) from being silently
// populated with low-confidence keyword coincidences - below the
// threshold, the publication is left Unclassified for manual review via
// admin/suggest_sdgs.php instead of forcing a guess onto it.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'ไม่ได้เข้าสู่ระบบ']);
    exit;
}

// Threshold history: 0.4 (initial) -> 1.0 (2026-08-19 server-side review,
// "≥1.0 or full-phrase match") -> >0.5 (2026-08-20, faculty leadership
// decision: since 1.0 is a full-phrase match's typical score, "more than
// half of a full match" is confident enough to auto-apply while still
// leaving weak keyword coincidences for manual review). Comparison is
// STRICT greater-than per that instruction, not >=.
const MIN_AUTO_APPLY_SCORE = 0.5;

// Up to 3 SDGs (primary/secondary/tertiary) may be auto-applied per
// publication - each one only if its own score clears MIN_AUTO_APPLY_SCORE.
const MAX_AUTO_APPLY_SDGS = 3;

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT id, title FROM `publications`
        WHERE (sdg_primary IS NULL OR sdg_primary = '')
          AND (sdg_secondary IS NULL OR sdg_secondary = '')
          AND (sdg_tertiary IS NULL OR sdg_tertiary = '')
        ORDER BY id ASC
    ");
    $rows = $stmt->fetchAll();
    echo json_encode([
        'items' => array_map(function ($r) {
            return ['id' => (int)$r['id'], 'title' => $r['title']];
        }, $rows),
        'min_score' => MIN_AUTO_APPLY_SCORE,
    ]);
    exit;
}

if ($action === 'process') {
    $pub_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($pub_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ไม่พบรหัสผลงานวิจัย']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, title, doi, abstract, keywords, sdg_primary, sdg_secondary, sdg_tertiary FROM `publications` WHERE id = ?");
    $stmt->execute([$pub_id]);
    $pub = $stmt->fetch();
    if (!$pub) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบผลงานวิจัยนี้ในระบบ']);
        exit;
    }

    // Never overwrite an existing SDG tag - a human (or an earlier
    // auto-classify pass) already made this call.
    if (!empty($pub['sdg_primary']) || !empty($pub['sdg_secondary']) || !empty($pub['sdg_tertiary'])) {
        echo json_encode(['id' => $pub_id, 'status' => 'skipped', 'reason' => 'มีการจำแนก SDG อยู่แล้ว']);
        exit;
    }

    // If publication lacks real abstract text or keywords, fetch on demand from Scopus API
    if ((!is_valid_abstract($pub['abstract']) || empty($pub['keywords'])) && !empty($pub['doi'])) {
        $api_key = defined('SCOPUS_API_KEY') ? SCOPUS_API_KEY : (getenv('MSC_SCOPUS_API_KEY') ?: null);
        if ($api_key) {
            try {
                $details = fetch_scopus_abstract_details_with_retry($pub['doi'], $api_key);
                $has_new_abs = !empty($details['abstract']) && is_valid_abstract($details['abstract']);
                $has_new_kw = !empty($details['keywords']);
                if ($has_new_abs || $has_new_kw) {
                    $update = $pdo->prepare("UPDATE `publications` SET abstract = :abstract, keywords = :keywords WHERE id = :id");
                    $update->execute([
                        ':abstract' => $has_new_abs ? $details['abstract'] : (is_valid_abstract($pub['abstract']) ? $pub['abstract'] : ''),
                        ':keywords' => $has_new_kw ? $details['keywords'] : ($pub['keywords'] ?? ''),
                        ':id' => $pub_id,
                    ]);
                    if ($has_new_abs) $pub['abstract'] = $details['abstract'];
                    if ($has_new_kw) $pub['keywords'] = $details['keywords'];
                }
            } catch (Exception $e) {
                // Non-fatal for the batch: leave this one Unclassified and
                // let the JS driver move on to the next publication.
                echo json_encode(['id' => $pub_id, 'status' => 'error', 'reason' => 'Scopus: ' . $e->getMessage()]);
                exit;
            }
        }
    }

    $results = score_publication_sdgs($pub['title'], $pub['keywords'], $pub['abstract']);

    // Strict "more than" (not >=) per the 2026-08-20 threshold decision.
    if (empty($results) || !($results[0]['score'] > MIN_AUTO_APPLY_SCORE)) {
        echo json_encode([
            'id' => $pub_id,
            'status' => 'low_confidence',
            'reason' => empty($results) ? 'ไม่พบคำสำคัญที่ตรงกับ SDG ใดเลย' : ('คะแนนต่ำกว่าเกณฑ์ (' . round($results[0]['score'], 2) . ' ไม่มากกว่า ' . MIN_AUTO_APPLY_SCORE . ')'),
            'top_score' => empty($results) ? 0 : round($results[0]['score'], 2),
        ]);
        exit;
    }

    // Take up to MAX_AUTO_APPLY_SDGS ranks, each gated individually by the
    // same strict threshold (a lower-ranked SDG never gets a pass just
    // because the top one cleared the bar).
    $qualified = array_values(array_filter(
        array_slice($results, 0, MAX_AUTO_APPLY_SDGS),
        function ($r) { return $r['score'] > MIN_AUTO_APPLY_SCORE; }
    ));

    $slot_codes = [null, null, null];
    foreach ($qualified as $i => $r) {
        $slot_codes[$i] = 'SDG ' . $r['num'];
    }
    [$primary_code, $secondary_code, $tertiary_code] = $slot_codes;

    $rationale_for = function ($r) {
        $parts = array_map(function ($m) {
            return $m['k'] . ' (' . ($m['full'] ? 'ตรงทั้งวลี' : 'ตรงบางส่วน') . ')';
        }, array_slice($r['matched'], 0, 5));
        return 'SDG ' . $r['num'] . ' (คะแนน ' . round($r['score'], 2) . '): ' . implode(', ', $parts);
    };
    $rationale_parts = array_map($rationale_for, $qualified);
    $rationale = '[Auto-Classify ' . date('Y-m-d') . '] ' . implode(' | ', $rationale_parts);

    $update = $pdo->prepare("UPDATE `publications` SET sdg_primary = :primary, sdg_secondary = :secondary, sdg_tertiary = :tertiary, sdg_rationale = :rationale WHERE id = :id");
    $update->execute([
        ':primary' => $primary_code,
        ':secondary' => $secondary_code,
        ':tertiary' => $tertiary_code,
        ':rationale' => $rationale,
        ':id' => $pub_id,
    ]);

    echo json_encode([
        'id' => $pub_id,
        'status' => 'applied',
        'sdg_primary' => $primary_code,
        'sdg_secondary' => $secondary_code,
        'sdg_tertiary' => $tertiary_code,
        'top_score' => round($results[0]['score'], 2),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
