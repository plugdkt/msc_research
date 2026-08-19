<?php
// database/add_sync_log_and_is_active.php
// Migration: add the sync_log table and researchers.is_active column to an
// already-deployed database (schema.sql only applies to a fresh install).

require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting migration: sync_log table + researchers.is_active column...\n";

    // 1. researchers.is_active
    $columns = $pdo->query("SHOW COLUMNS FROM `researchers`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('is_active', $columns)) {
        $pdo->exec("ALTER TABLE `researchers` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `h_index_peak`");
        echo "Added `is_active` column to `researchers`.\n";
    } else {
        echo "`is_active` column already exists on `researchers`. Skipping.\n";
    }

    // 2. sync_log table
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sync_log', $tables)) {
        $pdo->exec("
            CREATE TABLE `sync_log` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `completed_at` TIMESTAMP NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'running',
                `triggered_by` VARCHAR(100) NULL,
                `researchers_processed` INT NOT NULL DEFAULT 0,
                `publications_synced` INT NOT NULL DEFAULT 0,
                `records_skipped` INT NOT NULL DEFAULT 0,
                `duplicate_scopus_ids_found` INT NOT NULL DEFAULT 0,
                `error_message` TEXT NULL,
                `details` TEXT NULL,
                INDEX (`started_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Created `sync_log` table.\n";
    } else {
        echo "`sync_log` table already exists. Skipping.\n";
    }

    echo "Migration complete.\n";
} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
