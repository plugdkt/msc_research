<?php
// database/add_keywords_column.php
// Migration: add publications.keywords for Phase 6 SDG keyword matching
// (schema.sql only applies to a fresh install).

require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting migration: publications.keywords column...\n";

    $columns = $pdo->query("SHOW COLUMNS FROM `publications`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('keywords', $columns)) {
        $pdo->exec("ALTER TABLE `publications` ADD COLUMN `keywords` TEXT NULL AFTER `abstract`");
        echo "Added `keywords` column to `publications`.\n";
    } else {
        echo "`keywords` column already exists on `publications`. Skipping.\n";
    }

    echo "Migration complete.\n";
} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
