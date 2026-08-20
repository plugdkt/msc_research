<?php
// admin/fetch_rcr.php
// Phase 7 (RCR-06): batch + on-demand PMID/RCR resolution.
//
// Same one-publication-per-request shape as admin/classify_topics.php,
// driven by JS in admin/rcr_import.php for the batch case - this is the
// PRIMARY mechanism (RCR-06). The SAME action=process endpoint also serves
// as the SECONDARY on-demand mechanism: admin/publications.php's edit form
// calls it directly for a single publication (mirroring SDG-06b's
// "Suggest SDGs" button), so there is no separate single-publication
// endpoint to keep in sync with this one.
//
// Unlike SDG-06c, there is no "never overwrite" guard: RCR/PMID are never
// admin-curated (nothing lets a human hand-enter them), so a refresh
// always safely replaces the previously stored values - RCR changes over
// time as citations accrue, unlike a one-time SDG classification.

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

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    // mode=refresh re-targets every publication with a DOI (RCR changes
    // over time); default targets only publications never attempted yet.
    $refresh = isset($_GET['mode']) && $_GET['mode'] === 'refresh';
    $sql = "SELECT id, title FROM `publications` WHERE doi IS NOT NULL AND doi != ''";
    if (!$refresh) {
        $sql .= " AND rcr_checked_at IS NULL";
    }
    $sql .= " ORDER BY id ASC";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();
    echo json_encode([
        'items' => array_map(function ($r) {
            return ['id' => (int)$r['id'], 'title' => $r['title']];
        }, $rows),
        'mode' => $refresh ? 'refresh' : 'initial',
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

    $stmt = $pdo->prepare("SELECT id, doi, pmid FROM `publications` WHERE id = ?");
    $stmt->execute([$pub_id]);
    $pub = $stmt->fetch();
    if (!$pub) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบผลงานวิจัยนี้ในระบบ']);
        exit;
    }

    if (empty($pub['doi'])) {
        $pdo->prepare("UPDATE `publications` SET rcr_checked_at = NOW() WHERE id = ?")->execute([$pub_id]);
        echo json_encode(['id' => $pub_id, 'status' => 'skipped', 'reason' => 'ไม่มี DOI']);
        exit;
    }

    try {
        // Only resolve PMID if we don't already have one - a DOI's PMID
        // mapping doesn't change over time the way RCR itself does, so a
        // refresh only needs to re-fetch RCR, not re-resolve PMID.
        $pmid = $pub['pmid'];
        if (empty($pmid)) {
            $pmid = fetch_pmid_from_doi_with_retry($pub['doi']);
        }

        if (empty($pmid)) {
            $pdo->prepare("UPDATE `publications` SET rcr_checked_at = NOW() WHERE id = ?")->execute([$pub_id]);
            echo json_encode(['id' => $pub_id, 'status' => 'no_match', 'reason' => 'ไม่พบ PMID สำหรับ DOI นี้ (อาจไม่ได้อยู่ใน PubMed)']);
            exit;
        }

        $rcr_data = fetch_icite_rcr_with_retry($pmid);
    } catch (Exception $e) {
        echo json_encode(['id' => $pub_id, 'status' => 'error', 'reason' => $e->getMessage()]);
        exit;
    }

    $update = $pdo->prepare("
        UPDATE `publications`
        SET pmid = :pmid, rcr = :rcr, nih_percentile = :nih_percentile, rcr_checked_at = NOW()
        WHERE id = :id
    ");
    $update->execute([
        ':pmid' => $pmid,
        ':rcr' => $rcr_data['rcr'],
        ':nih_percentile' => $rcr_data['nih_percentile'],
        ':id' => $pub_id,
    ]);

    if ($rcr_data['rcr'] === null) {
        echo json_encode(['id' => $pub_id, 'status' => 'no_match', 'reason' => 'พบ PMID (' . $pmid . ') แต่ไม่มีข้อมูล RCR ใน iCite']);
        exit;
    }

    echo json_encode([
        'id' => $pub_id,
        'status' => 'applied',
        'pmid' => $pmid,
        'rcr' => round($rcr_data['rcr'], 2),
        'nih_percentile' => $rcr_data['nih_percentile'] !== null ? round($rcr_data['nih_percentile'], 1) : null,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid action']);
