<?php
// database/add_sdg_columns.php
// Add sdg_primary, sdg_secondary, and sdg_rationale columns to the publications table

require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting migration: Adding SDG columns to publications table...\n";
    
    // Check if columns already exist
    $columns = $pdo->query("SHOW COLUMNS FROM `publications`")->fetchAll(PDO::FETCH_COLUMN);
    
    $alterQueries = [];
    if (!in_array('sdg_primary', $columns)) {
        $alterQueries[] = "ADD COLUMN `sdg_primary` VARCHAR(50) NULL AFTER `abstract`";
    }
    if (!in_array('sdg_secondary', $columns)) {
        $alterQueries[] = "ADD COLUMN `sdg_secondary` VARCHAR(50) NULL AFTER `sdg_primary`";
    }
    if (!in_array('sdg_rationale', $columns)) {
        $alterQueries[] = "ADD COLUMN `sdg_rationale` TEXT NULL AFTER `sdg_secondary`";
    }
    
    if (!empty($alterQueries)) {
        $sql = "ALTER TABLE `publications` " . implode(", ", $alterQueries);
        $pdo->exec($sql);
        echo "Successfully added SDG columns to the `publications` table.\n";
    } else {
        echo "SDG columns already exist in the `publications` table. Skipping.\n";
    }
    
} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
