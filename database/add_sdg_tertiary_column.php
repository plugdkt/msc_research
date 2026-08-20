<?php
// database/add_sdg_tertiary_column.php
// Add sdg_tertiary column to the publications table.
//
// Raised by faculty leadership (2026-08-20): the auto-apply confidence
// threshold moved from 1.0 to >0.5, and up to 3 SDGs (not just 2) should be
// recorded per publication with a visible score per rank. The existing
// sdg_primary/sdg_secondary columns (see add_sdg_columns.php) only leave
// room for 2 - this adds the third slot.

require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting migration: Adding sdg_tertiary column to publications table...\n";

    $columns = $pdo->query("SHOW COLUMNS FROM `publications`")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('sdg_tertiary', $columns)) {
        $pdo->exec("ALTER TABLE `publications` ADD COLUMN `sdg_tertiary` VARCHAR(50) NULL AFTER `sdg_secondary`");
        echo "Successfully added `sdg_tertiary` column to the `publications` table.\n";
    } else {
        echo "`sdg_tertiary` column already exists in the `publications` table. Skipping.\n";
    }

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
