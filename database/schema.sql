-- Database creation script for Research Publications Aggregator v2
-- Collation: utf8mb4_unicode_ci

CREATE TABLE IF NOT EXISTS `researchers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title_th` VARCHAR(50) NULL,
    `first_name_th` VARCHAR(100) NOT NULL,
    `last_name_th` VARCHAR(100) NOT NULL,
    `title_en` VARCHAR(50) NULL,
    `first_name_en` VARCHAR(100) NOT NULL,
    `last_name_en` VARCHAR(100) NOT NULL,
    `orcid_id` VARCHAR(50) UNIQUE NULL,
    `scopus_author_id` VARCHAR(50) UNIQUE NULL,
    `pubmed_query` VARCHAR(255) NULL,
    `google_scholar_id` VARCHAR(50) UNIQUE NULL,
    `department` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NULL,
    `avatar_url` VARCHAR(255) NULL,
    `researcher_type` VARCHAR(50) DEFAULT 'สายวิชาการ' NOT NULL,
    `h_index_peak` INT NOT NULL DEFAULT 0,
    -- RESEARCHER-05: inactive/departed researchers are hidden from the public
    -- directory, but their historical publications still count in faculty-wide
    -- statistics — so this is a visibility flag, never a delete.
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `publications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` TEXT NOT NULL,
    `authors` TEXT NOT NULL,
    `journal_name` VARCHAR(255) NULL,
    `journal_issn` VARCHAR(20) NULL,
    `quartile` VARCHAR(5) NULL,
    `countries` VARCHAR(255) NULL,
    `corresponding_author_id` INT NULL,
    `funding_sponsor` TEXT NULL,
    `funding_no` VARCHAR(150) NULL,
    `funding_amount` DECIMAL(15,2) NULL,
    `publish_year` INT NOT NULL,
    `publish_date` DATE NULL,
    `doi` VARCHAR(150) UNIQUE NULL,
    `citation_count` INT DEFAULT 0,
    `source` VARCHAR(50) NOT NULL, -- 'orcid', 'pubmed', 'scopus', 'google_scholar', 'manual'
    `source_id` VARCHAR(100) NULL,
    `url` VARCHAR(255) NULL,
    `abstract` TEXT NULL,
    `keywords` TEXT NULL, -- Phase 6: author keywords from Scopus Abstract Retrieval API, for SDG keyword matching
    `openalex_id` VARCHAR(100) NULL, -- Phase 8: resolved OpenAlex work ID (NULL = not yet attempted or no match found)
    `openalex_checked_at` TIMESTAMP NULL, -- Phase 8: when OpenAlex resolution was last attempted for this publication
    `sdg_primary` VARCHAR(50) NULL,
    `sdg_secondary` VARCHAR(50) NULL,
    `sdg_tertiary` VARCHAR(50) NULL,
    `sdg_rationale` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `researcher_publications` (
    `researcher_id` INT NOT NULL,
    `publication_id` INT NOT NULL,
    PRIMARY KEY (`researcher_id`, `publication_id`),
    FOREIGN KEY (`researcher_id`) REFERENCES `researchers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`publication_id`) REFERENCES `publications`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) UNIQUE NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` VARCHAR(20) DEFAULT 'admin',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No default admin user is seeded here on purpose: a fixed, publicly-known
-- username/password (previously admin/admin1234, committed in this file in
-- plaintext-derivable form) is a standing credential-stuffing risk. The
-- first admin account is created interactively by install.php instead,
-- which only offers that form while `users` is empty.

-- Seed sample researchers for School of Medical Sciences (คณะวิทยาศาสตร์การแพทย์)
-- NOTE: sample/demo data — replace or remove before real production use.
INSERT INTO `researchers` (`title_th`, `first_name_th`, `last_name_th`, `title_en`, `first_name_en`, `last_name_en`, `orcid_id`, `scopus_author_id`, `pubmed_query`, `google_scholar_id`, `department`, `email`, `avatar_url`)
VALUES
('ดร.', 'สมจิตร์', 'ดีใจ', 'Dr.', 'Somchit', 'Deejai', '0000-0002-1825-0097', '57204928100', 'Somchit Deejai', 'scholar_id_1', 'ภาควิชาสรีรวิทยา', 'somchit.de@up.ac.th', ''),
('ผศ.ดร.', 'วิทยา', 'มีสุข', 'Asst. Prof. Dr.', 'Wittaya', 'Meesuk', '0000-0003-4567-8910', '12345678900', 'Wittaya Meesuk', 'scholar_id_2', 'ภาควิชากายวิภาคศาสตร์', 'wittaya.me@up.ac.th', '');

CREATE TABLE IF NOT EXISTS `corresponding_publications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `researcher_id` INT NOT NULL,
    `title` TEXT NOT NULL,
    `authors` TEXT NOT NULL,
    `url` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`researcher_id`) REFERENCES `researchers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SYNC-01..05: audit trail for every sync run (manual or cron-triggered).
-- One row per run. `status` moves running -> success|partial|failed.
CREATE TABLE IF NOT EXISTS `sync_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'running', -- running | success | partial | failed
    `triggered_by` VARCHAR(100) NULL, -- admin username, 'cli', or 'cron-token'
    `researchers_processed` INT NOT NULL DEFAULT 0,
    `publications_synced` INT NOT NULL DEFAULT 0,
    `records_skipped` INT NOT NULL DEFAULT 0, -- SYNC-03: incomplete Scopus records (no DOI / no author)
    `duplicate_scopus_ids_found` INT NOT NULL DEFAULT 0, -- SYNC-04
    `error_message` TEXT NULL,
    `details` TEXT NULL, -- JSON: per-researcher log lines, for admin review
    INDEX (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `journal_quartiles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `issn` VARCHAR(20) NOT NULL,
    `title` VARCHAR(255) NULL,
    `quartile` VARCHAR(5) NOT NULL,
    `sjr` DECIMAL(8,4) NULL,
    `source` VARCHAR(50) DEFAULT 'scimago',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`issn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
