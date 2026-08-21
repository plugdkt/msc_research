<?php
// database/add_llm_classification_columns.php
// Phase 10 (Zero-Shot LLM AI Layer): add columns for the UP AI Connect
// zero-shot SDG classification, run alongside (not replacing) the existing
// Phase 6 keyword-dictionary classifier (sdg_primary/secondary/tertiary).
//
// A single `llm_checked_at` (not separate per-field timestamps) marks that
// the LLM classification pipeline was attempted for this publication -
// mirrors the openalex_checked_at/rcr_checked_at design from Phases 7-8.
//
// llm_reviewed_by/llm_reviewed_at (SEM-08) record human-in-the-loop
// confirmation/correction of a low-confidence LLM classification -
// separate from llm_checked_at, which only marks "the LLM was asked."

require_once __DIR__ . '/../config/db.php';

try {
    echo "Starting migration: Phase 10 LLM classification schema...\n";

    $columns = $pdo->query("SHOW COLUMNS FROM `publications`")->fetchAll(PDO::FETCH_COLUMN);
    $alterQueries = [];

    if (!in_array('llm_sdg_primary', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_sdg_primary` TINYINT UNSIGNED NULL";
    }
    if (!in_array('llm_confidence_primary', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_confidence_primary` TINYINT UNSIGNED NULL";
    }
    if (!in_array('llm_sdg_secondary', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_sdg_secondary` TINYINT UNSIGNED NULL";
    }
    if (!in_array('llm_confidence_secondary', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_confidence_secondary` TINYINT UNSIGNED NULL";
    }
    if (!in_array('llm_rationale', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_rationale` TEXT NULL";
    }
    if (!in_array('llm_semantic_tags', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_semantic_tags` TEXT NULL COMMENT 'JSON array, reused by Phase 10 expert finder (SEM-05)'";
    }
    if (!in_array('llm_model', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_model` VARCHAR(50) NULL";
    }
    if (!in_array('llm_checked_at', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_checked_at` TIMESTAMP NULL";
    }
    if (!in_array('llm_reviewed_by', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_reviewed_by` VARCHAR(100) NULL COMMENT 'SEM-08 human-in-the-loop audit trail'";
    }
    if (!in_array('llm_reviewed_at', $columns)) {
        $alterQueries[] = "ADD COLUMN `llm_reviewed_at` TIMESTAMP NULL";
    }

    if (!empty($alterQueries)) {
        $pdo->exec("ALTER TABLE `publications` " . implode(", ", $alterQueries));
        echo "Added llm_* columns to `publications`.\n";
    } else {
        echo "LLM classification columns already exist on `publications`. Skipping.\n";
    }

} catch (Exception $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
