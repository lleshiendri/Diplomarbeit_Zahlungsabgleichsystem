<?php
/**
 * Validate that the connected database (buchhaltung_16_1_2026 or buchhaltungsql1)
 * has the tables and columns expected by matching_engine.php and matching_functions.php.
 * Run from CLI: php validate_schema.php
 * Or via browser (with auth): ensure db_connect uses the target DB.
 */

require_once __DIR__ . '/db_connect.php';
$schema = require __DIR__ . '/includes/schema_buchhaltung_16_1_2026.php';

$dbRes = @$conn->query("SELECT DATABASE()");
$dbName = $dbRes && $row = $dbRes->fetch_row() ? $row[0] : '?';
echo "Database: " . ($dbName ?: '(unknown)') . "\n";
echo str_repeat("-", 60) . "\n";

$allOk = true;
unset($schema['database']);
foreach ($schema as $table => $expectedCols) {
    $tableEsc = $conn->real_escape_string($table);
    $res = @$conn->query("SHOW TABLES LIKE '$tableEsc'");
    if (!$res || $res->num_rows === 0) {
        echo "TABLE MISSING: $table\n";
        $allOk = false;
        continue;
    }
    $colsRes = @$conn->query("SHOW COLUMNS FROM `$table`");
    if (!$colsRes) {
        echo "TABLE $table: DESCRIBE failed - " . $conn->error . "\n";
        $allOk = false;
        continue;
    }
    $actual = [];
    while ($row = $colsRes->fetch_assoc()) {
        $actual[$row['Field']] = true;
    }
    $expected = array_keys($expectedCols);
    $missing = array_diff($expected, array_keys($actual));
    $extra = array_diff(array_keys($actual), $expected);
    if (count($missing) > 0) {
        echo "TABLE $table - MISSING COLUMNS: " . implode(', ', $missing) . "\n";
        $allOk = false;
    }
    if (count($extra) > 0 && count($missing) === 0) {
        echo "TABLE $table - OK (extra columns in DB: " . implode(', ', $extra) . ")\n";
    } elseif (count($missing) === 0) {
        echo "TABLE $table - OK\n";
    }
}
echo str_repeat("-", 60) . "\n";
echo $allOk ? "All expected tables and columns present.\n" : "Fix missing tables/columns above.\n";
