<?php
// database/add_publication_topics.php
// Phase 8 (Topic Prominence & Trends): resolve OpenAlex's topic
// classification for each publication via its DOI and store it.
//
// Two schema additions:
// 1. `publications.openalex_id` / `openalex_checked_at` - tracks whether
//    we've attempted OpenAlex resolution for this publication at all, and
//    what we found (or didn't). Distinguishes "never tried" from "tried,
//    no match" so a batch tool can target only unattempted rows, and a
//    separate refresh can deliberately re-target everything.
// 2. `publication_topics` - a normalized one-to-many side table, not fixed
//    columns like sdg_primary/secondary/tertiary. OpenAlex topics are never
//    admin-curated (no review UI is planned for them, unlike SDGs) - they're
//    purely computed data feeding aggregate/trend reports, so there's no
//    fixed-slot UX to design around, and this avoids repeating the
//    2-column-then-3-column SDG migration pain.

require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting migration: Phase 8 OpenAlex topic classification schema...\n";

    $columns = $pdo->query("SHOW COLUMNS FROM `publications`")->fetchAll(PDO::FETCH_COLUMN);
    $alterQueries = [];
    if (!in_array('openalex_id', $columns)) {
        $alterQueries[] = "ADD COLUMN `openalex_id` VARCHAR(100) NULL AFTER `keywords`";
    }
    if (!in_array('openalex_checked_at', $columns)) {
        $alterQueries[] = "ADD COLUMN `openalex_checked_at` TIMESTAMP NULL AFTER `openalex_id`";
    }
    if (!empty($alterQueries)) {
        $pdo->exec("ALTER TABLE `publications` " . implode(", ", $alterQueries));
        echo "Added openalex_id/openalex_checked_at columns to `publications`.\n";
    } else {
        echo "openalex_id/openalex_checked_at already exist on `publications`. Skipping.\n";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `publication_topics` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `publication_id` INT NOT NULL,
            `topic_id` VARCHAR(100) NOT NULL,
            `display_name` VARCHAR(255) NOT NULL,
            `subfield` VARCHAR(255) NULL,
            `field` VARCHAR(255) NULL,
            `domain` VARCHAR(255) NULL,
            `score` DECIMAL(6,4) NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`publication_id`) REFERENCES `publications`(`id`) ON DELETE CASCADE,
            INDEX `idx_publication_id` (`publication_id`),
            INDEX `idx_field` (`field`),
            INDEX `idx_domain` (`domain`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Ensured `publication_topics` table exists.\n";

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
