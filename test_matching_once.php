<?php
/**
 * Run the matching pipeline once for a single invoice (dry run by default).
 * Usage: php test_matching_once.php [invoice_id] [persist=0|1]
 * Example: php test_matching_once.php 42
 *          php test_matching_once.php 42 1
 *
 * Prints OK and stage summary, or the first failure (stage name + error).
 * To use buchhaltung_16_1_2026: define DB_NAME before running, e.g. php -d auto_prepend_file=prepend_db.php test_matching_once.php 42
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$invoiceId = isset($argv[1]) ? (int)$argv[1] : 0;
$persist = isset($argv[2]) ? (int)$argv[2] : 0;
if ($invoiceId <= 0) {
    fwrite(STDERR, "Usage: php test_matching_once.php <invoice_id> [persist=0|1]\n");
    exit(1);
}

// Use same DB as db_connect (optional: set DB_NAME in prepend or here)
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/db_connect.php';
}
if (!function_exists('loadContext')) {
    require_once __DIR__ . '/matching_engine.php';
}

echo "Database: " . (defined('DB_NAME') ? DB_NAME : '(default)') . "\n";
echo "Invoice ID: $invoiceId | Persist: " . ($persist ? 'yes' : 'no (dry run)') . "\n";
echo str_repeat("-", 50) . "\n";

$failedAt = null;
$errorMsg = null;
$stageName = null;

try {
    $ctx = loadContext($conn);
    $stageName = 'Stage0_loadContext';
    if ($conn->error) {
        $failedAt = $stageName;
        $errorMsg = $conn->error;
        throw new RuntimeException($conn->error);
    }
    echo "Stage0_loadContext: OK (students=" . count($ctx['student_refs'] ?? []) . ", has_history=" . ($ctx['has_history'] ? 'yes' : 'no') . ")\n";

    $txns = fetchWork($conn, $ctx, $invoiceId);
    $stageName = 'Stage1_fetchWork';
    if ($conn->error) {
        $failedAt = $stageName;
        $errorMsg = $conn->error;
        throw new RuntimeException($conn->error);
    }
    if (empty($txns)) {
        echo "Stage1_fetchWork: no invoice found for id=$invoiceId (or already assigned).\n";
        echo "Either use an existing unassigned invoice id or run without student_id filter.\n";
        exit(0);
    }
    echo "Stage1_fetchWork: OK (fetched " . count($txns) . " row(s))\n";

    $txn = $txns[0];
    $signals = extractSignals($txn, $ctx);
    $stageName = 'Stage2_extractSignals';
    echo "Stage2_extractSignals: OK (refs=" . count($signals['ref_ids_found'] ?? []) . ")\n";

    $candidates = generateCandidates($txn, $signals, $ctx);
    $stageName = 'Stage3_generateCandidates';
    echo "Stage3_generateCandidates: OK (candidates=" . count($candidates) . ")\n";

    $decision = applyBusinessRules($txn, $signals, $candidates, $ctx);
    $stageName = 'Stage4_applyBusinessRules';
    $confirmed = count(array_filter($decision['matches'] ?? [], fn($m) => !empty($m['is_confirmed'])));
    echo "Stage4_applyBusinessRules: OK (matches=" . count($decision['matches'] ?? []) . ", confirmed=$confirmed)\n";

    $decision = historyAssistGate($conn, $txn, $signals, $decision, $ctx);
    $stageName = 'Stage5_historyAssistGate';
    if ($conn->error) {
        $failedAt = $stageName;
        $errorMsg = $conn->error;
        throw new RuntimeException($conn->error);
    }
    echo "Stage5_historyAssistGate: OK\n";

    if ($persist) {
        persistPipelineResult($conn, $txn, $signals, $decision, $ctx);
        $stageName = 'Stage6_persistPipelineResult';
        if ($conn->error) {
            $failedAt = $stageName;
            $errorMsg = $conn->error;
            throw new RuntimeException($conn->error);
        }
        echo "Stage6_persistPipelineResult: OK (wrote to DB)\n";
    } else {
        echo "Stage6: skipped (dry run)\n";
    }
} catch (Throwable $e) {
    $failedAt = $failedAt ?: $stageName ?: 'unknown';
    $errorMsg = $errorMsg ?: $e->getMessage();
    fwrite(STDERR, "\nFAILED at: $failedAt\n");
    fwrite(STDERR, "Error: $errorMsg\n");
    if ($e->getMessage() !== $errorMsg) {
        fwrite(STDERR, "Exception: " . $e->getMessage() . "\n");
        fwrite(STDERR, "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n");
    }
    exit(1);
}

echo str_repeat("-", 50) . "\n";
echo "OK – pipeline completed.\n";
exit(0);
