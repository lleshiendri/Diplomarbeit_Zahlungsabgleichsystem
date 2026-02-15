<?php
/**
 * Debug harness for matching pipeline.
 * Safe: admin-only or guarded by auth_check.php and MATCHING_DEBUG flag.
 * GET/POST: invoice_id or transaction_id (int), persist=0|1 (default 0 = dry run).
 * Returns JSON: stage-by-stage outputs, timing, DB writes, SQL errors.
 */
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';

$debugAllowed = getenv('MATCHING_DEBUG') === 'true'
    || (defined('DEBUG') && DEBUG)
    || (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin');
if (!$debugAllowed) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Debug not allowed (set MATCHING_DEBUG=true or use Admin role)', 'allowed' => false]);
    exit;
}
if (!defined('MATCHING_DEBUG')) {
    define('MATCHING_DEBUG', true);
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../matching_engine.php';

$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : (isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null);
if ($invoice_id === null) {
    $invoice_id = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : (isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : null);
}
$persist = isset($_GET['persist']) ? (int)$_GET['persist'] : (isset($_POST['persist']) ? (int)$_POST['persist'] : 0);
$doPersist = ($persist === 1);

$report = [
    'invoice_id' => $invoice_id,
    'persist' => $doPersist,
    'stages' => [],
    'db_writes' => [],
    'sql_errors' => [],
    'duration_total_ms' => 0,
];

if ($invoice_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(array_merge($report, ['error' => 'Missing or invalid invoice_id/transaction_id']));
    exit;
}

$t0 = microtime(true);

try {
    $ctx = loadContext($conn);
    $report['stages'][] = [
        'name' => 'Stage0_loadContext',
        'input_summary' => [],
        'output_summary' => [
            'has_history' => $ctx['has_history'] ?? false,
            'invoice_columns' => array_keys($ctx['invoice_columns'] ?? []),
            'students_cached' => count($ctx['student_refs'] ?? []),
        ],
        'duration_ms' => round((microtime(true) - $t0) * 1000),
    ];

    $t1 = microtime(true);
    $txns = fetchWork($conn, $ctx, $invoice_id);
    $report['stages'][] = [
        'name' => 'Stage1_fetchWork',
        'input_summary' => ['transaction_id' => $invoice_id],
        'output_summary' => ['count' => count($txns), 'ids' => array_column($txns, 'id')],
        'duration_ms' => round((microtime(true) - $t1) * 1000),
    ];

    if (empty($txns)) {
        $report['stages'][] = ['name' => 'Stage2-6_skipped', 'reason' => 'no_invoices_fetched'];
        $report['duration_total_ms'] = round((microtime(true) - $t0) * 1000);
        header('Content-Type: application/json');
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    foreach ($txns as $txn) {
        $txnId = $txn['id'] ?? null;
        $t2 = microtime(true);
        $signals = extractSignals($txn, $ctx);
        $report['stages'][] = [
            'name' => 'Stage2_extractSignals',
            'input_summary' => ['txn_id' => $txnId],
            'output_summary' => [
                'ref_ids_count' => count($signals['ref_ids_found'] ?? []),
                'names_count' => count($signals['names_found'] ?? []),
            ],
            'duration_ms' => round((microtime(true) - $t2) * 1000),
        ];

        $t3 = microtime(true);
        $candidates = generateCandidates($txn, $signals, $ctx);
        $report['stages'][] = [
            'name' => 'Stage3_generateCandidates',
            'input_summary' => ['txn_id' => $txnId],
            'output_summary' => ['count' => count($candidates), 'matched_by' => array_values(array_unique(array_column($candidates, 'matched_by')))],
            'duration_ms' => round((microtime(true) - $t3) * 1000),
        ];

        $t4 = microtime(true);
        $decision = applyBusinessRules($txn, $signals, $candidates, $ctx);
        $report['stages'][] = [
            'name' => 'Stage4_applyBusinessRules',
            'input_summary' => ['txn_id' => $txnId],
            'output_summary' => [
                'matches_count' => count($decision['matches'] ?? []),
                'needs_review' => $decision['needs_review'] ?? false,
                'reason' => $decision['reason'] ?? null,
                'is_confirmed_count' => count(array_filter($decision['matches'] ?? [], fn($m) => !empty($m['is_confirmed']))),
            ],
            'duration_ms' => round((microtime(true) - $t4) * 1000),
        ];

        $t5 = microtime(true);
        $decision = historyAssistGate($conn, $txn, $signals, $decision, $ctx);
        $report['stages'][] = [
            'name' => 'Stage5_historyAssistGate',
            'input_summary' => ['txn_id' => $txnId],
            'output_summary' => [
                'matches_count' => count($decision['matches'] ?? []),
                'needs_review' => $decision['needs_review'] ?? false,
            ],
            'duration_ms' => round((microtime(true) - $t5) * 1000),
        ];

        if ($doPersist) {
            $t6 = microtime(true);
            persistPipelineResult($conn, $txn, $signals, $decision, $ctx);
            $report['stages'][] = [
                'name' => 'Stage6_persistPipelineResult',
                'input_summary' => ['txn_id' => $txnId],
                'output_summary' => ['persisted' => true],
                'duration_ms' => round((microtime(true) - $t6) * 1000),
            ];
            $report['db_writes'][] = ['invoice_id' => $txnId, 'stage' => 'Stage6'];
        } else {
            $report['stages'][] = [
                'name' => 'Stage6_persistPipelineResult',
                'input_summary' => ['txn_id' => $txnId],
                'output_summary' => ['skipped' => true, 'reason' => 'persist=0 (dry run)'],
                'duration_ms' => 0,
            ];
        }
    }

    if ($conn->error) {
        $report['sql_errors'][] = ['context' => 'after_pipeline', 'error' => $conn->error];
    }
} catch (Throwable $e) {
    $report['sql_errors'][] = ['context' => 'exception', 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
}

$report['duration_total_ms'] = round((microtime(true) - $t0) * 1000);
header('Content-Type: application/json');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
