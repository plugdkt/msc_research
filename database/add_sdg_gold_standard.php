<?php
// database/add_sdg_gold_standard.php
// Phase 10 (SEM-07): gold-standard table for validating SDG classification
// accuracy - a human subject-matter reviewer labels the TRUE SDG (or "none")
// for a random sample of publications, blind to both classifiers' guesses
// (see admin/sdg_validation.php - the labeling UI never shows either
// classifier's output while collecting a label, to avoid anchoring bias).
// Precision/Recall/F1 for the Phase 6 keyword classifier and the Phase 10
// LLM classifier are then computed against this table.

require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting migration: Phase 10 SDG gold-standard table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sdg_gold_standard` (
            `publication_id` INT NOT NULL PRIMARY KEY,
            `correct_sdg` TINYINT UNSIGNED NULL COMMENT 'NULL = reviewer judged no SDG applies',
            `labeled_by` VARCHAR(100) NOT NULL,
            `labeled_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT `fk_gold_standard_publication` FOREIGN KEY (`publication_id`) REFERENCES `publications`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "Ensured `sdg_gold_standard` table exists.\n";

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
