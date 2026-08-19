<?php
// admin/suggest_sdgs.php
// Phase 6 (SDG-06c): AJAX endpoint - suggest SDGs for one publication using
// the in-house keyphrase dictionary (data/sdg_data.json). Read-only: it may
// backfill publications.abstract/keywords from Scopus if missing (so scoring
// has real text to work with), but it never writes sdg_primary/secondary/
// rationale itself - the admin reviews the suggestions in the UI and clicks
// Save on the normal edit form to apply them, same as every other field.
//
// JSON endpoint, so this cannot include admin_header.php (it would print
// HTML before the JSON body). Auth check mirrors admin_header.php/sync.php.

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

$pub_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($pub_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ไม่พบรหัสผลงานวิจัย']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, title, doi, abstract, keywords FROM `publications` WHERE id = ?");
$stmt->execute([$pub_id]);
$pub = $stmt->fetch();
if (!$pub) {
    http_response_code(404);
    echo json_encode(['error' => 'ไม่พบผลงานวิจัยนี้ในระบบ']);
    exit;
}

$fetched_new_data = false;

// Backfill abstract/keywords on demand if missing and we have a DOI to look
// up - avoids a Scopus API call on every "Suggest SDGs" click once this has
// run once for a given publication.
if (empty($pub['abstract']) && empty($pub['keywords']) && !empty($pub['doi'])) {
    $api_key = defined('SCOPUS_API_KEY') ? SCOPUS_API_KEY : (getenv('MSC_SCOPUS_API_KEY') ?: null);
    if ($api_key) {
        try {
            $details = fetch_scopus_abstract_details_with_retry($pub['doi'], $api_key);
            if (!empty($details['abstract']) || !empty($details['keywords'])) {
                $update = $pdo->prepare("UPDATE `publications` SET abstract = COALESCE(NULLIF(:abstract, ''), abstract), keywords = COALESCE(NULLIF(:keywords, ''), keywords) WHERE id = :id");
                $update->execute([
                    ':abstract' => $details['abstract'] ?? '',
                    ':keywords' => $details['keywords'] ?? '',
                    ':id' => $pub_id,
                ]);
                $pub['abstract'] = $details['abstract'] ?? $pub['abstract'];
                $pub['keywords'] = $details['keywords'] ?? $pub['keywords'];
                $fetched_new_data = true;
            }
        } catch (Exception $e) {
            // Non-fatal: fall through and score with whatever text is already
            // on the row (title only, in the worst case). Surface this in the
            // response so the admin knows why suggestions might be weak.
            echo json_encode([
                'warning' => 'ไม่สามารถดึงบทคัดย่อ/คำสำคัญจาก Scopus ได้ (' . $e->getMessage() . ') กำลังแนะนำ SDG จากข้อมูลที่มีอยู่แทน',
            ]);
        }
    }
}

$results = score_publication_sdgs($pub['title'], $pub['keywords'], $pub['abstract']);
$top = array_slice($results, 0, 2);

$suggestions = array_map(function ($r) {
    $rationale_parts = array_map(function ($m) {
        return $m['k'] . ' (' . ($m['full'] ? 'ตรงทั้งวลี' : 'ตรงบางส่วน') . ')';
    }, array_slice($r['matched'], 0, 5));
    return [
        'code' => 'SDG ' . $r['num'],
        'name' => $r['name'],
        'name_th' => $r['name_th'],
        'color' => $r['color'],
        'score' => round($r['score'], 3),
        'rationale' => implode(', ', $rationale_parts),
    ];
}, $top);

echo json_encode([
    'publication_id' => $pub_id,
    'used_abstract' => !empty($pub['abstract']),
    'used_keywords' => !empty($pub['keywords']),
    'fetched_new_data' => $fetched_new_data,
    'suggestions' => $suggestions,
    'total_candidates' => count($results),
]);
