<?php
// admin/fetch_quartiles.php
// Phase 9: batch-fetch CiteScore Quartile from the Scopus Serial Title API,
// per unique journal ISSN (not per publication - many publications share a
// journal, so this is far cheaper than a per-publication loop). Mirrors the
// list/process shape of admin/classify_topics.php and admin/fetch_rcr.php.
//
// Leadership decision (2026-08-20): Scopus CiteScore Quartile replaces
// SCImago/SJR Quartile as the faculty's sole source going forward, so this
// always overwrites - both the journal_quartiles master row and every
// publication.quartile for that ISSN - the same "always update, even if it
// clears old SCImago quartiles" behavior the existing CSV import
// (admin/scopus_quartiles.php) already uses for the identical reason.

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

function clean_issn_value($issn) {
    return preg_replace('/[^0-9Xx]/', '', (string)$issn);
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    // mode=refresh re-targets every ISSN in use (re-fetch, CiteScore updates
    // yearly); default targets only ISSNs never fetched via this tool yet.
    $refresh = isset($_GET['mode']) && $_GET['mode'] === 'refresh';
    $stmt = $pdo->query("SELECT DISTINCT journal_issn FROM `publications` WHERE journal_issn IS NOT NULL AND journal_issn != ''");
    $all_issns = [];
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $clean = clean_issn_value($row['journal_issn']);
        if (strlen($clean) < 8 || isset($seen[$clean])) {
            continue;
        }
        $seen[$clean] = true;
        $all_issns[] = $clean;
    }

    if (!$refresh) {
        $already_fetched = $pdo->query("SELECT DISTINCT issn FROM `journal_quartiles` WHERE source = 'scopus_citescore_api'")->fetchAll(PDO::FETCH_COLUMN);
        $already_fetched = array_flip($already_fetched);
        $all_issns = array_values(array_filter($all_issns, function ($issn) use ($already_fetched) {
            return !isset($already_fetched[$issn]);
        }));
    }

    echo json_encode([
        'items' => array_map(function ($issn) {
            return ['issn' => $issn];
        }, $all_issns),
        'mode' => $refresh ? 'refresh' : 'initial',
    ]);
    exit;
}

if ($action === 'process') {
    $issn = clean_issn_value($_GET['issn'] ?? '');
    if (strlen($issn) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'ISSN ไม่ถูกต้อง']);
        exit;
    }

    try {
        $result = fetch_scopus_citescore_quartile_with_retry($issn);
    } catch (Exception $e) {
        echo json_encode(['issn' => $issn, 'status' => 'error', 'reason' => $e->getMessage()]);
        exit;
    }

    if ($result === null) {
        echo json_encode(['issn' => $issn, 'status' => 'no_match', 'reason' => 'ไม่พบวารสารนี้ใน Scopus']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM `journal_quartiles` WHERE issn = ?")->execute([$issn]);

        if ($result['quartile'] !== null) {
            $pdo->prepare("
                INSERT INTO `journal_quartiles` (issn, title, quartile, sjr, source)
                VALUES (:issn, :title, :quartile, :citescore, 'scopus_citescore_api')
            ")->execute([
                ':issn' => $issn,
                ':title' => $result['title'],
                ':quartile' => $result['quartile'],
                ':citescore' => $result['citescore'],
            ]);
        }

        // Always overwrite - matches the existing CSV import's retroactive
        // update behavior, since leadership wants Scopus quartile as the
        // sole source now, replacing any prior SCImago-imported value.
        $pdo->prepare("
            UPDATE `publications`
            SET quartile = :quartile
            WHERE REPLACE(REPLACE(REPLACE(UPPER(journal_issn), '-', ''), ' ', ''), '.', '') = :issn
        ")->execute([
            ':quartile' => $result['quartile'],
            ':issn' => $issn,
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['issn' => $issn, 'status' => 'error', 'reason' => 'DB: ' . $e->getMessage()]);
        exit;
    }

    if ($result['quartile'] === null) {
        echo json_encode(['issn' => $issn, 'status' => 'no_match', 'title' => $result['title'], 'reason' => 'พบวารสารแต่ยังไม่มีข้อมูล CiteScore/Quartile']);
        exit;
    }

    echo json_encode([
        'issn' => $issn,
        'status' => 'applied',
        'title' => $result['title'],
        'quartile' => $result['quartile'],
        'percentile' => $result['percentile'],
        'citescore' => $result['citescore'],
        'year' => $result['year'],
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
