<?php
// database/add_rcr_columns.php
// Phase 7 (NIH iCite RCR Integration): add PMID + Relative Citation Ratio
// columns to the publications table.
//
// A single `rcr_checked_at` (not separate PMID-checked / RCR-checked
// timestamps) marks that the whole pipeline was attempted for this
// publication - no DOI, no resolvable PMID, and no iCite record for a
// resolved PMID are all "checked, nothing found" rather than 3 different
// states to track, mirroring the openalex_checked_at design in Phase 8.

require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting migration: Phase 7 NIH iCite RCR schema...\n";

    $columns = $pdo->query("SHOW COLUMNS FROM `publications`")->fetchAll(PDO::FETCH_COLUMN);
    $alterQueries = [];
    // Deliberately not "AFTER openalex_checked_at" - that column only
    // exists once Phase 8's migration has run, and this migration
    // shouldn't depend on running in a particular order relative to it.
    if (!in_array('pmid', $columns)) {
        $alterQueries[] = "ADD COLUMN `pmid` VARCHAR(20) NULL AFTER `doi`";
    }
    if (!in_array('rcr', $columns)) {
        $alterQueries[] = "ADD COLUMN `rcr` DECIMAL(10,4) NULL AFTER `pmid`";
    }
    if (!in_array('nih_percentile', $columns)) {
        $alterQueries[] = "ADD COLUMN `nih_percentile` DECIMAL(5,2) NULL AFTER `rcr`";
    }
    if (!in_array('rcr_checked_at', $columns)) {
        $alterQueries[] = "ADD COLUMN `rcr_checked_at` TIMESTAMP NULL AFTER `nih_percentile`";
    }

    if (!empty($alterQueries)) {
        $pdo->exec("ALTER TABLE `publications` " . implode(", ", $alterQueries));
        echo "Added pmid/rcr/nih_percentile/rcr_checked_at columns to `publications`.\n";
    } else {
        echo "RCR columns already exist on `publications`. Skipping.\n";
    }

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
