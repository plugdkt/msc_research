<?php
// database/normalize_invalid_sdg_values.php
// One-time cleanup: some publications have sdg_primary/sdg_secondary values
// that aren't a real "SDG 1".."SDG 17" code (e.g. the literal Thai string
// "ไม่ทราบ" = "unknown"), stored by an earlier version of the CSV import
// that kept unrecognized values as-is instead of normalizing them to null.
// SDG-03 requires unclassified publications to be null, not an arbitrary
// string, so this rewrites anything invalid to null.

require_once __DIR__ . '/../config/db.php';

$valid_codes = [];
for ($i = 1; $i <= 17; $i++) {
    $valid_codes[] = "SDG {$i}";
}
$placeholders = implode(',', array_fill(0, count($valid_codes), '?'));

try {
    foreach (['sdg_primary', 'sdg_secondary', 'sdg_tertiary'] as $col) {
        $stmt = $pdo->prepare("
            SELECT id, `{$col}` AS value FROM `publications`
            WHERE `{$col}` IS NOT NULL AND `{$col}` != '' AND `{$col}` NOT IN ({$placeholders})
        ");
        $stmt->execute($valid_codes);
        $bad_rows = $stmt->fetchAll();

        if (empty($bad_rows)) {
            echo "No invalid values found in `{$col}`.\n";
            continue;
        }

        echo "Found " . count($bad_rows) . " invalid `{$col}` value(s), setting to NULL:\n";
        foreach ($bad_rows as $row) {
            echo "  id={$row['id']}: '{$row['value']}'\n";
        }

        $update = $pdo->prepare("
            UPDATE `publications` SET `{$col}` = NULL
            WHERE `{$col}` IS NOT NULL AND `{$col}` != '' AND `{$col}` NOT IN ({$placeholders})
        ");
        $update->execute($valid_codes);
        echo "Cleared {$update->rowCount()} row(s) in `{$col}`.\n";
    }
    echo "Done.\n";
} catch (PDOException $e) {
    die("Failed to normalize SDG columns: " . $e->getMessage() . "\n");
}
