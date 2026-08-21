<?php
// database/add_expert_finder_cache.php
// Phase 10 (SEM-06): cache table for the LLM-ranked expert finder
// (admin/expert_finder_llm.php). Keyed by a normalized hash of the query
// text - an exact repeat of a query (e.g. a rehearsed demo query run ahead
// of the September 3 presentation) is served instantly from this table
// with zero UP AI Connect calls, so the live demo never depends on an
// uncached request succeeding on stage.

require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting migration: Phase 10 expert finder cache table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `expert_finder_cache` (
            `query_hash` CHAR(32) NOT NULL PRIMARY KEY,
            `query_text` VARCHAR(500) NOT NULL,
            `results_json` LONGTEXT NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "Ensured `expert_finder_cache` table exists.\n";

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
