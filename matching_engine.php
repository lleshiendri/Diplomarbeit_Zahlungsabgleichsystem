<?php
declare(strict_types=1);

/**
 * PIPELINE CONTRACT
 * - Deterministic staged pipeline; same input => same output.
 * - Only Stage 6 (persistPipelineResult) writes to the database.
 * - All other stages are pure transformations and log their inputs/outputs when DEBUG is true.
 * - Stages: 0) loadContext → 1) fetchWork → 2) extractSignals → 3) generateCandidates
 *           → 4) applyBusinessRules → 5) historyAssistGate → 5b) historyMemoryFallbackStage → 6) persistPipelineResult.
 *
 * DB schema: table/column names must match buchhaltung_16_1_2026 (see includes/schema_buchhaltung_16_1_2026.php).
 */

// Toggle with env MATCHING_DEBUG=true or override here.
if (!defined('DEBUG')) {
    define('DEBUG', getenv('MATCHING_DEBUG') === 'true');
}

// Database connection is expected to be provided by including code.
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/db_connect.php';
}

// ------------------------
// Logging helpers
// ------------------------
function debugLog(string $stage, string $label, array $data = []): void
{
    if (!DEBUG) {
        return;
    }
    error_log("[PIPELINE][$stage][$label] " . json_encode($data, JSON_UNESCAPED_UNICODE));
}

// ------------------------
// Generic helpers
// ------------------------
function tableExists(mysqli $conn, string $table): bool
{
    // SHOW TABLES does not support ? placeholder in MySQL; use escaped pattern.
    $pattern = $conn->real_escape_string($table);
    $res = @$conn->query("SHOW TABLES LIKE '" . $pattern . "'");
    return ($res && $res->num_rows > 0);
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    // Only run if table exists, to avoid setting $conn->error on missing table.
    $pattern = $conn->real_escape_string($table);
    $tres = @$conn->query("SHOW TABLES LIKE '" . $pattern . "'");
    if (!$tres || $tres->num_rows === 0) {
        return false;
    }
    // SHOW COLUMNS: table name in backticks, column pattern escaped (no ? support in all MySQL versions).
    $tableEsc = '`' . str_replace('`', '``', $table) . '`';
    $colEsc = $conn->real_escape_string($column);
    $res = @$conn->query("SHOW COLUMNS FROM " . $tableEsc . " LIKE '" . $colEsc . "'");
    return ($res && $res->num_rows > 0);
}

function normalizeText(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Normalize a string for memory-fallback key comparison (deterministic, strict).
 * Trims, lowercases, collapses spaces, removes common punctuation.
 */
function normalizeMemoryKey(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[\.,;:\/\\\\\-_\(\)\[\]\{\}\'"]/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/**
 * Return true if normalized beneficiary is too generic for memory fallback (avoid false positives).
 */
function isGenericBeneficiary(string $norm): bool
{
    if (mb_strlen($norm) < 6) return true;
    $generic = ['bank', 'payment', 'transfer', 'school', 'htl', 'institution', 'office', 'accounting', 'finance'];
    $normLower = $norm;
    foreach ($generic as $g) {
        if (strpos($normLower, $g) !== false) return true;
    }
    return false;
}

function stripPrefix(string $ref, string $prefix = 'HTL-'): string
{
    return (stripos($ref, $prefix) === 0) ? substr($ref, strlen($prefix)) : $ref;
}

function extractReferenceIds(string $text): array
{
    $found = [];
    $seen = [];
    if (preg_match_all('/[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+/', $text, $matches)) {
        foreach ($matches[0] as $raw) {
            $norm = strtoupper(trim($raw));
            if (!isset($seen[$norm])) {
                $seen[$norm] = true;
                $found[] = $norm;
            }
        }
    }
    if (count($found) > 4) {
        debugLog('extractSignals', 'ref_cap', ['requested' => count($found), 'kept' => 4]);
        $found = array_slice($found, 0, 4);
    }
    return $found;
}

function extractNamesFromText(string $text): array
{
    $parts = preg_split('/\s+/', $text);
    $names = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if (mb_strlen($p) >= 3) {
            $names[] = $p;
        }
    }
    return array_values(array_unique($names));
}

function similarityLetters(string $a, string $b, string $prefix = 'HTL-'): float
{
    $a = stripPrefix($a, $prefix);
    $b = stripPrefix($b, $prefix);
    $maxLen = max(strlen($a), strlen($b));
    if ($maxLen === 0) return 0.0;
    $minLen = min(strlen($a), strlen($b));
    $match = 0;
    for ($i = 0; $i < $minLen; $i++) {
        if ($a[$i] === $b[$i]) $match++;
    }
    return $match / $maxLen;
}

/**
 * Fuzzy confidence with denominator = length of DB reference (spec: total_letters_of_db_reference).
 * Returns 0..1 (multiply by 100 for confidence_score).
 */
function similarityLettersDbRefDenom(string $inputRef, string $dbRef, string $prefix = 'HTL-'): float
{
    $a = stripPrefix($inputRef, $prefix);
    $b = stripPrefix($dbRef, $prefix);
    $dbLen = strlen($b);
    if ($dbLen === 0) return 0.0;
    $minLen = min(strlen($a), $dbLen);
    $match = 0;
    for ($i = 0; $i < $minLen; $i++) {
        if ($a[$i] === $b[$i]) $match++;
    }
    return $match / $dbLen;
}

/** MATCHING_HISTORY_TAB.matched_by ENUM: only these 6 values allowed in DB */
const MATCHED_BY_ENUM_VALUES = ['reference', 'fallback', 'manual', 'confirmed', 'reference_fuzzy', 'name_suggest'];

/**
 * Map internal match type to MATCHING_HISTORY_TAB.matched_by ENUM.
 * Returns only: 'reference','fallback','manual','confirmed','reference_fuzzy','name_suggest'
 */
function matchedByToEnum(string $internal): string
{
    $map = [
        'ref_exact'       => 'reference',
        'ref_fuzzy'       => 'reference_fuzzy',
        'name_exact'      => 'name_suggest',
        'name_fuzzy'      => 'name_suggest',
        'history_assist'  => 'fallback',
        'history_memory'  => 'fallback',
        'manual'          => 'manual',
        'reference'       => 'reference',
        'reference_fuzzy' => 'reference_fuzzy',
        'name_suggest'    => 'name_suggest',
        'confirmed'       => 'confirmed',
        'fallback'        => 'fallback',
    ];
    $value = $map[$internal] ?? 'fallback';
    return in_array($value, MATCHED_BY_ENUM_VALUES, true) ? $value : 'fallback';
}

function rankCandidates(array $candidates): array
{
    usort($candidates, function ($a, $b) {
        if ($a['confidence'] !== $b['confidence']) {
            return $b['confidence'] <=> $a['confidence'];
        }
        $priority = ['ref_exact' => 4, 'ref_fuzzy' => 3, 'name_exact' => 2, 'name_fuzzy' => 1, 'history_assist' => 0];
        $pa = $priority[$a['matched_by']] ?? -1;
        $pb = $priority[$b['matched_by']] ?? -1;
        if ($pa !== $pb) return $pb <=> $pa;
        $exactness = (strpos($a['matched_by'], 'exact') !== false) <=> (strpos($b['matched_by'], 'exact') !== false);
        if ($exactness !== 0) return -$exactness;
        return $a['student_id'] <=> $b['student_id'];
    });
    return $candidates;
}

// ------------------------
// Pipeline stages
// ------------------------
function loadContext(mysqli $conn): array
{
    $ctx = [
        'prefix' => 'HTL-',
        'has_history' => tableExists($conn, 'MATCHING_HISTORY_TAB'),
        'invoice_columns' => [],
        'student_refs' => [],
    ];

    $invoiceCols = ['id', 'amount_total', 'reference', 'description', 'beneficiary', 'created_at', 'reference_text', 'student_id'];
    foreach ($invoiceCols as $col) {
        $ctx['invoice_columns'][$col] = columnExists($conn, 'INVOICE_TAB', $col);
    }

    $refs = [];
    $stmt = $conn->prepare("SELECT id, reference_id, forename, name, long_name FROM STUDENT_TAB");
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $refs[] = [
                'student_id' => (int)$row['id'],
                'reference_id' => trim((string)($row['reference_id'] ?? '')),
                'forename' => $row['forename'] ?? '',
                'name' => $row['name'] ?? '',
                'long_name' => $row['long_name'] ?? '',
            ];
        }
        $stmt->close();
    }
    $ctx['student_refs'] = $refs;

    debugLog('Stage0', 'output', ['has_history' => $ctx['has_history'], 'columns' => $ctx['invoice_columns'], 'students_cached' => count($refs)]);
    return $ctx;
}

function fetchWork(mysqli $conn, array $ctx, ?int $transactionId = null): array
{
    $cols = ['id'];
    foreach (['amount_total', 'reference', 'description', 'beneficiary', 'created_at', 'reference_text'] as $col) {
        if ($ctx['invoice_columns'][$col] ?? false) $cols[] = $col;
    }
    $select = implode(', ', $cols);
    $sql = "SELECT $select FROM INVOICE_TAB WHERE 1=1";
    // Only filter by unassigned when fetching bulk work; when a specific invoice id is requested, fetch it regardless
    if ($transactionId === null && ($ctx['invoice_columns']['student_id'] ?? false)) {
        $sql .= " AND (student_id IS NULL OR student_id = 0)";
    }
    if ($transactionId !== null) {
        $sql .= " AND id = ?";
    }
    $sql .= " ORDER BY id ASC";

    $stmt = $conn->prepare($sql);
    if ($transactionId !== null) {
        $stmt->bind_param("i", $transactionId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    debugLog('Stage1', 'output', ['count' => count($rows)]);

    // #region agent log
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'pre-fix',
        'hypothesisId' => 'H2',
        'location' => 'matching_engine.php:193',
        'message' => 'fetchWork result',
        'data' => [
            'transactionId' => $transactionId,
            'rowCount' => count($rows),
            'firstIds' => array_slice(array_map(fn($r) => $r['id'] ?? null, $rows), 0, 5),
        ],
        'timestamp' => round(microtime(true) * 1000)
    ]) . PHP_EOL;
    @file_put_contents(__DIR__ . '/.cursor/debug.log', $logEntry, FILE_APPEND);
    // #endregion

    return $rows;
}

function extractSignals(array $txn, array $ctx): array
{
    $textParts = [];
    foreach (['reference_text', 'reference', 'description', 'beneficiary'] as $col) {
        if (isset($txn[$col])) {
            $textParts[] = (string)$txn[$col];
        }
    }
    $rawText = trim(implode(' ', $textParts));
    $normalized = normalizeText($rawText);
    $refs = extractReferenceIds($rawText);
    $names = extractNamesFromText($normalized);

    $signals = [
        'ref_ids_found' => $refs,
        'names_found' => $names,
        'normalized_text' => $normalized,
    ];

    debugLog('Stage2', 'input', ['txn' => $txn['id'] ?? null]);
    debugLog('Stage2', 'output', $signals);
    return $signals;
}

function generateCandidates(array $txn, array $signals, array $ctx): array
{
    $candidates = [];
    $studentRefs = $ctx['student_refs'];

    // A) ref_exact
    foreach ($signals['ref_ids_found'] as $ref) {
        foreach ($studentRefs as $s) {
            if (!empty($s['reference_id']) && strtoupper($s['reference_id']) === $ref) {
                $candidates[] = [
                    'student_id' => $s['student_id'],
                    'matched_by' => 'ref_exact',
                    'confidence' => 1.00,
                    'evidence' => $ref,
                ];
            }
        }
    }

    // B) ref_fuzzy only if no ref_exact (confidence = matching_letters / total_letters_of_db_reference)
    if (empty($candidates)) {
        foreach ($signals['ref_ids_found'] as $ref) {
            foreach ($studentRefs as $s) {
                $candRef = $s['reference_id'] ?? '';
                if (empty($candRef)) continue;
                $score = similarityLettersDbRefDenom($ref, $candRef, $ctx['prefix']);
                $candidates[] = [
                    'student_id' => $s['student_id'],
                    'matched_by' => 'ref_fuzzy',
                    'confidence' => $score, // 0..1
                    'evidence' => $candRef,
                ];
            }
        }
    }

    // Name helpers
    $normNames = array_map('normalizeText', array_filter(array_map(function ($s) {
        $parts = [];
        if (!empty($s['forename']) && !empty($s['name'])) {
            $parts[] = trim($s['forename'] . ' ' . $s['name']);
        }
        if (!empty($s['long_name'])) {
            $parts[] = $s['long_name'];
        }
        return implode(' ', $parts);
    }, $studentRefs)));

    // C) name_exact: only when BOTH forename and surname appear in text (avoid "per"/"von" matching wrong person)
    $nameExactStudentIds = [];
    $normText = $signals['normalized_text'];
    $genericWords = ['per', 'von', 'der', 'die', 'und', 'and', 'for', 'by', 'the', 'für', 'an', 'in', 'zu', 'bei','dhe','nga','pagesa','shkolle'];
    foreach ($studentRefs as $s) {
        $forename = normalizeText(trim($s['forename'] ?? ''));
        $surname = normalizeText(trim($s['name'] ?? ''));
        if ($forename === '' || $surname === '') continue;
        if (in_array($forename, $genericWords, true) || in_array($surname, $genericWords, true)) continue;
        $fullName = trim($forename . ' ' . $surname);
        if (mb_strlen($fullName) < 6) continue;
        if (strpos($normText, $forename) === false || strpos($normText, $surname) === false) continue;
        $nameExactStudentIds[$s['student_id']] = true;
        $candidates[] = [
            'student_id' => $s['student_id'],
            'matched_by' => 'name_exact',
            'confidence' => 0.90,
            'evidence' => $fullName,
        ];
    }

    // D) name_fuzzy: only for students NOT already name_exact; score = word overlap, capped so never confirmable
    $normText = $signals['normalized_text'];
    foreach ($studentRefs as $s) {
        if (isset($nameExactStudentIds[$s['student_id']])) continue;
        $fullName = normalizeText(trim(($s['forename'] ?? '') . ' ' . ($s['name'] ?? '')));
        if (!$fullName) continue;
        $nameWords = array_filter(explode(' ', $fullName), fn($w) => mb_strlen($w) >= 2);
        if (empty($nameWords)) continue;
        $found = 0;
        foreach ($nameWords as $nw) {
            if (strpos($normText, $nw) !== false) $found++;
        }
        $ratio = $found / count($nameWords);
        if ($ratio > 0) {
            // Cap at 0.65 so name_fuzzy is always suggestion-only (never >= 0.90 confirm threshold)
            $confidence = min(0.65, $ratio * 0.90);
            $candidates[] = [
                'student_id' => $s['student_id'],
                'matched_by' => 'name_fuzzy',
                'confidence' => $confidence,
                'evidence' => $fullName,
            ];
        }
    }

    debugLog('Stage3', 'input', ['txn' => $txn['id'] ?? null, 'signals' => $signals]);
    debugLog('Stage3', 'output', ['count' => count($candidates)]);

    // #region agent log
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'pre-fix',
        'hypothesisId' => 'H5',
        'location' => 'matching_engine.php:262',
        'message' => 'generateCandidates result',
        'data' => [
            'invoiceId' => $txn['id'] ?? null,
            'candidateCount' => count($candidates),
            'sample' => array_slice(array_map(function ($c) {
                return [
                    'student_id' => $c['student_id'],
                    'matched_by' => $c['matched_by'],
                    'confidence' => $c['confidence'],
                ];
            }, $candidates), 0, 5),
        ],
        'timestamp' => round(microtime(true) * 1000)
    ]) . PHP_EOL;
    @file_put_contents(__DIR__ . '/.cursor/debug.log', $logEntry, FILE_APPEND);
    // #endregion

    return $candidates;
}

function applyBusinessRules(array $txn, array $signals, array $candidates, array $ctx): array
{
    $ranked = rankCandidates($candidates);
    $decision = [
        'matches' => [],
        'needs_review' => false,
        'reason' => null,
    ];

    // Multi-ref split rule: ONLY ref_exact (fuzzy must not confirm or subtract left_to_pay)
    $resolvedFromRefs = array_filter($ranked, function ($c) {
        return $c['matched_by'] === 'ref_exact';
    });
    $resolvedStudents = array_unique(array_map(fn($c) => $c['student_id'], $resolvedFromRefs));
    if (count($signals['ref_ids_found']) >= 2 && count($resolvedStudents) === count($signals['ref_ids_found']) && count($signals['ref_ids_found']) <= 4) {
        $splitAmount = (float)($txn['amount_total'] ?? 0);
        $share = (count($resolvedStudents) > 0) ? $splitAmount / count($resolvedStudents) : 0;
        foreach ($resolvedStudents as $sid) {
            $decision['matches'][] = [
                'student_id' => $sid,
                'share_amount' => $share,
                'confidence' => 1.0,
                'matched_by' => 'ref_exact',
                'evidence' => 'multi-ref-split',
                'is_confirmed' => true,
            ];
        }
    } elseif (!empty($ranked)) {
        $top = $ranked[0];
        $confidence = (float)($top['confidence'] ?? 0);
        $matchedBy = $top['matched_by'] ?? '';
        // is_confirmed = 0 for ref_fuzzy, name_fuzzy, or any match with confidence <= 90 (0.90)
        $isConfirmed = false;
        if (!in_array($matchedBy, ['ref_fuzzy', 'name_fuzzy'], true) && $confidence > 0.90) {
            if ($matchedBy === 'ref_exact' || $matchedBy === 'name_exact') {
                $isConfirmed = true;
            }
        }
        $decision['matches'][] = [
            'student_id' => $top['student_id'],
            'share_amount' => (float)($txn['amount_total'] ?? 0),
            'confidence' => $top['confidence'],
            'matched_by' => $top['matched_by'],
            'evidence' => $top['evidence'],
            'is_confirmed' => $isConfirmed,
        ];
    } else {
        $decision['needs_review'] = true;
        $decision['reason'] = 'no_candidates';
    }

    debugLog('Stage4', 'output', $decision);

    // #region agent log
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'pre-fix',
        'hypothesisId' => 'H5',
        'location' => 'matching_engine.php:322',
        'message' => 'applyBusinessRules decision',
        'data' => [
            'invoiceId' => $txn['id'] ?? null,
            'matches' => $decision['matches'],
            'needs_review' => $decision['needs_review'],
            'reason' => $decision['reason'],
        ],
        'timestamp' => round(microtime(true) * 1000)
    ]) . PHP_EOL;
    @file_put_contents(__DIR__ . '/.cursor/debug.log', $logEntry, FILE_APPEND);
    // #endregion

    return $decision;
}

function historyAssistGate(mysqli $conn, array $txn, array $signals, array $decision, array $ctx): array
{
    $needsAssist = empty($decision['matches']) || ($decision['matches'][0]['confidence'] ?? 0) < 0.70;
    if (!$needsAssist || !$ctx['has_history']) {
        debugLog('Stage5', 'skip', ['needs_assist' => $needsAssist, 'has_history' => $ctx['has_history']]);
        return $decision;
    }

    // MATCHING_HISTORY_TAB may not have reference_text/beneficiary (e.g. buchhaltung_16_1_2026)
    $hasRefText = columnExists($conn, 'MATCHING_HISTORY_TAB', 'reference_text');
    $hasBeneficiary = columnExists($conn, 'MATCHING_HISTORY_TAB', 'beneficiary');
    if (!$hasRefText || !$hasBeneficiary) {
        debugLog('Stage5', 'skip', ['reason' => 'MATCHING_HISTORY_TAB has no reference_text/beneficiary']);
        return $decision;
    }

    $stmt = $conn->prepare("
        SELECT student_id, matched_by, confidence_score 
        FROM MATCHING_HISTORY_TAB
        WHERE (reference_text = ? OR beneficiary = ?)
        ORDER BY confidence_score DESC, student_id ASC
        LIMIT 3
    ");
    $refText = $signals['normalized_text'];
    $benef = $txn['beneficiary'] ?? '';
    if ($stmt) {
        $stmt->bind_param("ss", $refText, $benef);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $decision['matches'][] = [
                'student_id' => (int)$row['student_id'],
                'share_amount' => (float)($txn['amount_total'] ?? 0),
                'confidence' => min(1.0, max($decision['matches'][0]['confidence'] ?? 0, ($row['confidence_score'] ?? 50) / 100 + 0.05)),
                'matched_by' => 'history_assist',
                'evidence' => $row['matched_by'] ?? 'history',
                'is_confirmed' => false,
            ];
        }
        $stmt->close();
    }

    if (empty($decision['matches']) || ($decision['matches'][0]['confidence'] ?? 0) < 0.70) {
        $decision['needs_review'] = true;
        $decision['reason'] = 'low_confidence';
    }

    debugLog('Stage5', 'output', $decision);
    return $decision;
}

/**
 * Last-resort memory fallback: suggest student from most recent CONFIRMED history
 * where the prior invoice shared the same reference, reference_number, or beneficiary.
 * Only runs when there are ZERO candidates from all prior stages (true last resort). No schema changes; read-only queries.
 */
function historyMemoryFallbackStage(mysqli $conn, array $txn, array $signals, array $decision, array $ctx): array
{
    $invoiceId = (int)($txn['id'] ?? 0);
    if (!$invoiceId || !$ctx['has_history']) {
        debugLog('Stage5b', 'skip', ['reason' => 'no_invoice_id_or_no_history']);
        return $decision;
    }

    // Completely last resort: only run when NO candidate was produced by any prior stage (3, 4, 5)
    if (!empty($decision['matches'])) {
        debugLog('Stage5b', 'skip', ['reason' => 'candidates_already_exist', 'count' => count($decision['matches'])]);
        return $decision;
    }

    $hasRef = columnExists($conn, 'INVOICE_TAB', 'reference');
    $hasRefNum = columnExists($conn, 'INVOICE_TAB', 'reference_number');
    $hasBen = columnExists($conn, 'INVOICE_TAB', 'beneficiary');
    if (!$hasRef && !$hasRefNum && !$hasBen) {
        debugLog('Stage5b', 'skip', ['reason' => 'no_key_columns']);
        return $decision;
    }

    // Load current invoice keys for comparison (reference_number may not be in $txn)
    $currentRef = $hasRef ? trim((string)($txn['reference'] ?? '')) : '';
    $currentRefNum = '';
    if ($hasRefNum) {
        $sel = $conn->prepare("SELECT reference_number FROM INVOICE_TAB WHERE id = ? LIMIT 1");
        if ($sel) {
            $sel->bind_param("i", $invoiceId);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            $currentRefNum = $row ? trim((string)($row['reference_number'] ?? '')) : '';
            $sel->close();
        }
    }
    $currentBen = $hasBen ? trim((string)($txn['beneficiary'] ?? '')) : '';

    $normRef = normalizeMemoryKey($currentRef);
    $normRefNum = normalizeMemoryKey($currentRefNum);
    $normBen = normalizeMemoryKey($currentBen);

    // Fetch recent confirmed history with linked invoice keys (read-only)
    $hasCreatedAt = columnExists($conn, 'MATCHING_HISTORY_TAB', 'created_at');
    $orderBy = $hasCreatedAt ? 'h.created_at DESC, h.id DESC' : 'h.id DESC';
    $selCols = ['h.invoice_id', 'h.student_id', 'h.id AS history_id'];
    if ($hasRef) $selCols[] = 'i.reference';
    if ($hasRefNum) $selCols[] = 'i.reference_number';
    if ($hasBen) $selCols[] = 'i.beneficiary';
    $sql = "SELECT " . implode(', ', $selCols) . "
            FROM MATCHING_HISTORY_TAB h
            INNER JOIN INVOICE_TAB i ON i.id = h.invoice_id
            WHERE h.is_confirmed = 1
            ORDER BY {$orderBy}
            LIMIT 50";
    $res = $conn->query($sql);
    if (!$res) {
        debugLog('Stage5b', 'skip', ['reason' => 'query_failed']);
        return $decision;
    }
    $historyRows = [];
    while ($row = $res->fetch_assoc()) {
        $historyRows[] = $row;
    }
    $res->free();

    debugLog('Stage5b', 'history_loaded', ['count' => count($historyRows)]);

    $existingStudentIds = array_map(fn($m) => (int)$m['student_id'], $decision['matches']);
    $maxExistingConf = !empty($decision['matches']) ? max(array_map(fn($m) => (float)($m['confidence'] ?? 0), $decision['matches'])) : 0;

    // Try key types in priority order: reference (highest), reference_number, beneficiary (lowest)
    $keyTypes = [
        ['key' => 'reference', 'norm' => $normRef, 'cap' => 0.75, 'minLen' => 6],
        ['key' => 'reference_number', 'norm' => $normRefNum, 'cap' => 0.70, 'minLen' => 6],
        ['key' => 'beneficiary', 'norm' => $normBen, 'cap' => 0.60, 'minLen' => 6],
    ];

    foreach ($keyTypes as $keySpec) {
        $keyName = $keySpec['key'];
        $normCurrent = $keySpec['norm'];
        $cap = $keySpec['cap'];
        $minLen = $keySpec['minLen'];

        if ($normCurrent === '') continue;
        if (mb_strlen($normCurrent) < $minLen) continue;
        if ($keyName === 'beneficiary' && isGenericBeneficiary($normCurrent)) continue;

        $matchingRows = [];
        foreach ($historyRows as $hr) {
            $col = $keyName === 'reference' ? 'reference' : ($keyName === 'reference_number' ? 'reference_number' : 'beneficiary');
            if (!isset($hr[$col])) continue;
            $normHist = normalizeMemoryKey(trim((string)$hr[$col]));
            if ($normHist === $normCurrent) {
                $matchingRows[] = $hr;
            }
        }

        if (count($matchingRows) === 0) continue;

        $first = $matchingRows[0];
        $suggestedStudentId = (int)$first['student_id'];
        $historyInvoiceId = (int)$first['invoice_id'];
        $historyId = (int)($first['history_id'] ?? 0);

        // Ambiguity: do we have multiple different student_ids in recent matches for this key?
        $recentStudentIds = array_slice(array_unique(array_map(fn($r) => (int)$r['student_id'], $matchingRows)), 0, 5);
        $ambiguous = (count($recentStudentIds) > 1);
        $lastThreeConsistent = false;
        if (count($matchingRows) >= 3) {
            $three = array_slice($matchingRows, 0, 3);
            $lastThreeConsistent = (count(array_unique(array_map(fn($r) => (int)$r['student_id'], $three))) === 1);
        }

        $confidence = $cap;
        if ($lastThreeConsistent && $confidence < 0.85) $confidence = min(0.85, $confidence + 0.05);

        $isConfirmed = false;
        if ($keyName === 'reference' && count($matchingRows) >= 3 && !$ambiguous && $lastThreeConsistent) {
            $isConfirmed = true;
        }

        // Do not add if we already have this student_id
        if (in_array($suggestedStudentId, $existingStudentIds, true)) continue;
        // Do not add if existing suggestion has higher confidence
        if ($maxExistingConf >= $confidence) continue;

        $evidence = "Memory fallback: based on last confirmed match by {$keyName} (history invoice_id={$historyInvoiceId}, student_id={$suggestedStudentId})";

        $decision['matches'][] = [
            'student_id' => $suggestedStudentId,
            'share_amount' => (float)($txn['amount_total'] ?? 0),
            'confidence' => $confidence,
            'matched_by' => 'history_memory',
            'evidence' => $evidence,
            'is_confirmed' => $isConfirmed,
        ];

        debugLog('Stage5b', 'added', [
            'key_type' => $keyName,
            'history_invoice_id' => $historyInvoiceId,
            'student_id' => $suggestedStudentId,
            'confidence' => $confidence,
            'is_confirmed' => $isConfirmed,
            'ambiguous' => $ambiguous,
            'rows_scanned' => count($historyRows),
        ]);

        return $decision;
    }

    debugLog('Stage5b', 'no_match', ['norm_ref_len' => mb_strlen($normRef), 'norm_refnum_len' => mb_strlen($normRefNum), 'norm_ben_len' => mb_strlen($normBen)]);
    return $decision;
}

/*
 * MANUAL TEST PLAN (memory fallback stage):
 * - Perfect reference-id match exists -> fallback must NOT run (Stage 3/4 add candidate; we skip when matches not empty).
 * - No candidates -> fallback suggests based on last confirmed history (same reference/reference_number/beneficiary).
 * - Beneficiary repeats for different students -> fallback must not auto-confirm (suggest only or skip; ambiguity check).
 * - Existing suggestion with higher confidence -> N/A (we only run when there are zero candidates).
 * - Invoice already confirmed -> fallback does nothing (persist skips; fetchWork may not return assigned invoices).
 */

/**
 * Split amount in cents into N shares so sum equals total (no lost cents).
 * Returns array of N amounts in original units (e.g. euros).
 */
function splitAmountNoCentsLost(float $totalAmount, int $n): array
{
    if ($n <= 0) return [];
    $cents = (int)round($totalAmount * 100);
    $perShare = (int)floor($cents / $n);
    $remainder = $cents - $perShare * $n;
    $shares = [];
    for ($i = 0; $i < $n; $i++) {
        $c = $perShare + ($i < $remainder ? 1 : 0);
        $shares[] = $c / 100.0;
    }
    return $shares;
}

function persistPipelineResult(mysqli $conn, array $txn, array $signals, array $decision, array $ctx): void
{
    $invoiceId = (int)($txn['id'] ?? 0);
    debugLog('Stage6', 'input', ['txn' => $invoiceId, 'decision' => $decision]);

    // --- Idempotency: skip if invoice already processed (confirmed) ---
    if ($invoiceId > 0 && ($ctx['invoice_columns']['student_id'] ?? false)) {
        $check = $conn->prepare("SELECT student_id FROM INVOICE_TAB WHERE id = ? LIMIT 1");
        if ($check) {
            $check->bind_param("i", $invoiceId);
            $check->execute();
            $res = $check->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $check->close();
            if ($row && isset($row['student_id']) && $row['student_id'] !== null && (int)$row['student_id'] > 0) {
                debugLog('Stage6', 'skip_idempotent', ['invoice_id' => $invoiceId, 'reason' => 'invoice already has student_id']);
                return;
            }
        }
    }
    if ($ctx['has_history']) {
        $hasIsConfirmed = columnExists($conn, 'MATCHING_HISTORY_TAB', 'is_confirmed');
        $checkHist = $conn->prepare(
            $hasIsConfirmed
                ? "SELECT 1 FROM MATCHING_HISTORY_TAB WHERE invoice_id = ? AND is_confirmed = 1 LIMIT 1"
                : "SELECT 1 FROM MATCHING_HISTORY_TAB WHERE invoice_id = ? LIMIT 1"
        );
        if ($checkHist) {
            $checkHist->bind_param("i", $invoiceId);
            $checkHist->execute();
            $res = $checkHist->get_result();
            if ($res && $res->fetch_assoc()) {
                $checkHist->close();
                debugLog('Stage6', 'skip_idempotent', ['invoice_id' => $invoiceId, 'reason' => 'confirmed history already exists']);
                return;
            }
            $checkHist->close();
        }
    }

    $confirmedMatches = array_filter($decision['matches'], fn($m) => !empty($m['is_confirmed']));
    $totalAmount = (float)($txn['amount_total'] ?? 0);

    // Insert history (all matches; only columns that exist: reference_text/beneficiary optional in MATCHING_HISTORY_TAB)
    if ($ctx['has_history']) {
        $hasIsConfirmedCol = columnExists($conn, 'MATCHING_HISTORY_TAB', 'is_confirmed');
        $hasRefTextCol = columnExists($conn, 'MATCHING_HISTORY_TAB', 'reference_text');
        $hasBeneficiaryCol = columnExists($conn, 'MATCHING_HISTORY_TAB', 'beneficiary');
        $cols = ['invoice_id', 'student_id', 'confidence_score', 'matched_by'];
        $vals = ['?', '?', '?', '?'];
        $types = 'iids';
        $paramsBase = [];
        if ($hasRefTextCol) { $cols[] = 'reference_text'; $vals[] = '?'; $types .= 's'; }
        if ($hasBeneficiaryCol) { $cols[] = 'beneficiary'; $vals[] = '?'; $types .= 's'; }
        if ($hasIsConfirmedCol) { $cols[] = 'is_confirmed'; $vals[] = '?'; $types .= 'i'; }
        $sql = "INSERT INTO MATCHING_HISTORY_TAB (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $ins = $conn->prepare($sql);
        if ($ins) {
            foreach ($decision['matches'] as $match) {
                $confidenceScore = (float)($match['confidence'] ?? 0) * 100;
                $studentId = (int)$match['student_id'];
                $matchedBy = matchedByToEnum($match['matched_by'] ?? '');
                $params = [$invoiceId, $studentId, $confidenceScore, $matchedBy];
                if ($hasRefTextCol) $params[] = $signals['normalized_text'] ?? null;
                if ($hasBeneficiaryCol) $params[] = $txn['beneficiary'] ?? null;
                if ($hasIsConfirmedCol) $params[] = !empty($match['is_confirmed']) ? 1 : 0;
                $bindParams = array_merge([$types], $params);
                $refs = [];
                foreach ($bindParams as $k => $v) $refs[$k] = &$bindParams[$k];
                call_user_func_array([$ins, 'bind_param'], $refs);
                $ins->execute();
            }
            $ins->close();
        }
    }

    // Only for confirmed matches: update invoice and STUDENT_TAB (left_to_pay down, amount_paid up by same share)
    if (!empty($confirmedMatches) && $totalAmount > 0) {
        $n = count($confirmedMatches);
        $shares = splitAmountNoCentsLost($totalAmount, $n);
        $hasLeftToPay = columnExists($conn, 'STUDENT_TAB', 'left_to_pay');
        $hasAmountPaid = columnExists($conn, 'STUDENT_TAB', 'amount_paid');

        if ($ctx['invoice_columns']['student_id'] ?? false) {
            $primary = $confirmedMatches[array_key_first($confirmedMatches)];
            $stmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $primary['student_id'], $invoiceId);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Only confirmed matches: subtract left_to_pay and add same amount to amount_paid (do NOT touch for is_confirmed=false)
        if ($hasLeftToPay) {
            $idx = 0;
            foreach ($confirmedMatches as $match) {
                if (empty($match['is_confirmed'])) {
                    continue; // safety: never change balance for unconfirmed suggestions
                }
                $sid = (int)$match['student_id'];
                $share = $shares[$idx] ?? $match['share_amount'] ?? ($totalAmount / $n);
                $idx++;
                if ($hasAmountPaid) {
                    $upd = $conn->prepare("UPDATE STUDENT_TAB SET left_to_pay = GREATEST(0, COALESCE(left_to_pay, 0) - ?), amount_paid = COALESCE(amount_paid, 0) + ? WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param("ddi", $share, $share, $sid);
                        $upd->execute();
                        $upd->close();
                    }
                } else {
                    $upd = $conn->prepare("UPDATE STUDENT_TAB SET left_to_pay = GREATEST(0, COALESCE(left_to_pay, 0) - ?) WHERE id = ?");
                    if ($upd) {
                        $upd->bind_param("di", $share, $sid);
                        $upd->execute();
                        $upd->close();
                    }
                }
            }
        }
    } elseif ($decision['needs_review']) {
        if (columnExists($conn, 'INVOICE_TAB', 'needs_review')) {
            $stmt = $conn->prepare("UPDATE INVOICE_TAB SET needs_review = 1 WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $invoiceId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    debugLog('Stage6', 'output', ['persisted' => true]);
}

// ------------------------
// Orchestration entry point
// ------------------------
/**
 * @param int|null $transactionId Run for this invoice/transaction id only, or null for all unassigned
 * @param bool     $dryRun        If true, run stages 0-5 only; do not persist (no DB writes)
 */
function runMatchingPipeline(?int $transactionId = null, bool $dryRun = false): void
{
    global $conn;
    // #region agent log
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'pre-fix',
        'hypothesisId' => 'H1',
        'location' => 'matching_engine.php:458',
        'message' => 'runMatchingPipeline entry',
        'data' => ['transactionId' => $transactionId, 'dryRun' => $dryRun],
        'timestamp' => round(microtime(true) * 1000)
    ]) . PHP_EOL;
    @file_put_contents(__DIR__ . '/.cursor/debug.log', $logEntry, FILE_APPEND);
    // #endregion

    $ctx = loadContext($conn);
    $txns = fetchWork($conn, $ctx, $transactionId);

    foreach ($txns as $txn) {
        $signals = extractSignals($txn, $ctx);
        $candidates = generateCandidates($txn, $signals, $ctx);
        $decision = applyBusinessRules($txn, $signals, $candidates, $ctx);
        $decision = historyAssistGate($conn, $txn, $signals, $decision, $ctx);
        $decision = historyMemoryFallbackStage($conn, $txn, $signals, $decision, $ctx);
        if (!$dryRun) {
            persistPipelineResult($conn, $txn, $signals, $decision, $ctx);
        } else {
            debugLog('Stage6', 'skipped_dry_run', ['txn_id' => $txn['id'] ?? null]);
        }
    }
}

// If invoked via CLI or directly with ?run_pipeline=1
if (php_sapi_name() === 'cli' || isset($_GET['run_pipeline'])) {
    $id = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : null;
    $dryRun = isset($_GET['dry_run']) && ($_GET['dry_run'] === '1' || $_GET['dry_run'] === 'true');
    runMatchingPipeline($id, $dryRun);
}

/**
 * MATCHING ENGINE - Reference-Based Matching Only
 */

function notif_has_mail_status(mysqli $conn): bool {
    static $cached = null;
    if ($cached !== null) return $cached;

    $res = $conn->query("SHOW COLUMNS FROM NOTIFICATION_TAB LIKE 'mail_status'");
    $cached = ($res && $res->num_rows > 0);
    return $cached;
}

/**
 * Dedupe notifications by (invoice_reference, urgency)
 * invoice_reference is the human identifier (INVOICE_TAB.reference_number)
 * 
 * This is the single source of truth for reference-based transaction-to-student matching.
 * All matching logic runs in PHP only - no database triggers or stored procedures.
 * 
 * Called automatically after transaction inserts in:
 * - add_transactions.php (manual entry)
 * - import_files.php (bulk CSV import)
 * 
 * AJAX Support:
 * - Call with ?action=get_fuzzy_suggestions&invoice_id=X to get fuzzy reference suggestions
 */

// Handle AJAX request for fuzzy suggestions (only when accessed directly via GET/POST, not when included)
// Check if this is an AJAX request by looking for the action parameter
if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = isset($_GET['action']) ? $_GET['action'] : $_POST['action'];
    if ($action === 'get_fuzzy_suggestions') {
        // Only process if this file is being accessed directly (not included)
        // Check by seeing if we can send headers (if headers already sent, we're included)
        if (!headers_sent()) {
            header('Content-Type: application/json');
            
            $invoiceIdParam = isset($_GET['invoice_id']) ? $_GET['invoice_id'] : (isset($_POST['invoice_id']) ? $_POST['invoice_id'] : null);
            if (!isset($invoiceIdParam) || !is_numeric($invoiceIdParam)) {
                echo json_encode(['error' => 'Invalid invoice_id']);
                exit;
            }
            
            $invoiceId = (int)$invoiceIdParam;
            
            // Load database connection
            require_once __DIR__ . '/db_connect.php';
            
            $suggestions = getFuzzySuggestions($invoiceId, $conn);
            
            echo json_encode($suggestions);
            exit;
        }
    }
}

// (removed duplicate extractReferenceIds definition; pipeline helper at top is used instead)

/**
 * Computes confidence score between two reference IDs using fuzzy matching
 * 
 * Algorithm:
 * - Removes "HTL-" prefix (first 4 characters) from both strings
 * - Counts matching letters at same positions
 * - Confidence = (matching_letters / max_length) * 100
 * 
 * @param string $unknownRef The unknown reference ID to match
 * @param string $candidateRef The candidate reference ID from database
 * @return array ['confidence' => float, 'length_diff' => int, 'ref' => string]
 */
function computeFuzzyConfidence($unknownRef, $candidateRef) {
    // Remove "HTL-" prefix (first 4 characters) from both
    $unknownWithoutPrefix = substr($unknownRef, 4);
    $candidateWithoutPrefix = substr($candidateRef, 4);
    
    // Compute matching letters at same positions
    $matchingLetters = 0;
    $minLen = min(strlen($unknownWithoutPrefix), strlen($candidateWithoutPrefix));
    
    for ($i = 0; $i < $minLen; $i++) {
        if ($unknownWithoutPrefix[$i] === $candidateWithoutPrefix[$i]) {
            $matchingLetters++;
        }
    }
    
    // Denominator is the length of the LONGEST string (after prefix removal)
    $maxLen = max(strlen($unknownWithoutPrefix), strlen($candidateWithoutPrefix));
    
    // Calculate confidence score
    $confidence = ($maxLen > 0) ? ($matchingLetters / $maxLen) * 100 : 0;
    
    // Length difference for tie-breaking
    $lengthDiff = abs(strlen($unknownWithoutPrefix) - strlen($candidateWithoutPrefix));
    
    return [
        'confidence' => $confidence,
        'length_diff' => $lengthDiff,
        'ref' => $candidateRef
    ];
}

/**
 * Finds the best fuzzy match for an unknown reference ID
 * 
 * @param string $unknownRef The unknown reference ID (must start with "HTL-")
 * @param mysqli $conn Database connection
 * @param float $minConfidence Minimum confidence threshold (default 55)
 * @return array|null ['student_id' => int, 'reference' => string, 'confidence' => float] or null
 */
function findBestFuzzyMatch($unknownRef, $conn, $minConfidence = 55.0) {
    // Only process references starting with "HTL-"
    if (substr($unknownRef, 0, 4) !== 'HTL-') {
        return null;
    }
    
    // Fetch all candidate reference_ids starting with "HTL-"
    $stmt = $conn->prepare("SELECT id, reference_id FROM STUDENT_TAB WHERE reference_id LIKE 'HTL-%'");
    if (!$stmt) {
        return null;
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $candidates = [];
    
    while ($row = $result->fetch_assoc()) {
        $candidates[] = [
            'student_id' => (int)$row['id'],
            'reference_id' => $row['reference_id']
        ];
    }
    $stmt->close();
    
    if (empty($candidates)) {
        return null;
    }
    
    // Compute confidence for each candidate
    $scoredCandidates = [];
    foreach ($candidates as $candidate) {
        $score = computeFuzzyConfidence($unknownRef, $candidate['reference_id']);
        $scoredCandidates[] = [
            'student_id' => $candidate['student_id'],
            'reference' => $candidate['reference_id'],
            'confidence' => $score['confidence'],
            'length_diff' => $score['length_diff']
        ];
    }
    
    // Sort by: highest confidence, then smallest length_diff, then lexicographically smallest reference
    usort($scoredCandidates, function($a, $b) {
        // Primary: highest confidence
        if (abs($a['confidence'] - $b['confidence']) > 0.01) {
            return $b['confidence'] <=> $a['confidence'];
        }
        // Tie-breaker 1: smallest length difference
        if ($a['length_diff'] !== $b['length_diff']) {
            return $a['length_diff'] <=> $b['length_diff'];
        }
        // Tie-breaker 2: lexicographically smallest reference
        return strcmp($a['reference'], $b['reference']);
    });
    
    // Return best match if above threshold
    if (!empty($scoredCandidates) && $scoredCandidates[0]['confidence'] >= $minConfidence) {
        return [
            'student_id' => $scoredCandidates[0]['student_id'],
            'reference' => $scoredCandidates[0]['reference'],
            'confidence' => $scoredCandidates[0]['confidence']
        ];
    }
    
    return null;
}

/**
 * Normalizes text for name matching
 * - lowercase
 * - replace punctuation with spaces
 * - collapse multiple spaces
 * 
 * @param string $text Input text
 * @return string Normalized text
 */
function normalizeTextForMatching($text) {
    // Convert to lowercase
    $normalized = mb_strtolower($text, 'UTF-8');
    
    // Replace punctuation with spaces
    $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
    
    // Collapse multiple spaces
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    
    // Trim
    return trim($normalized);
}

/**
 * Checks if normalized invoice text contains both first and last name
 * 
 * @param string $normalizedInvoiceText Normalized invoice text
 * @param string $firstName First name (normalized)
 * @param string $lastName Last name (normalized)
 * @return bool True if both names are found (as substrings, allowing for concatenation)
 */
function containsBothNames($normalizedInvoiceText, $firstName, $lastName) {
    // Check if both first and last name exist as substrings (allows for concatenated names)
    $firstNameFound = !empty($firstName) && strpos($normalizedInvoiceText, $firstName) !== false;
    $lastNameFound = !empty($lastName) && strpos($normalizedInvoiceText, $lastName) !== false;
    
    return $firstNameFound && $lastNameFound;
}

/**
 * Finds name-based suggestions for an invoice with no reference IDs
 * 
 * @param int $invoiceId The invoice ID
 * @param string $normalizedInvoiceText Normalized invoice text
 * @param mysqli $conn Database connection
 * @return array|null ['student_id' => int, 'matched_name' => string, 'entity_type' => 'student'|'guardian'] or null
 */
function findNameBasedSuggestion($invoiceId, $normalizedInvoiceText, $conn) {
    $candidates = [];
    
    // 1. Check student names
    // STUDENT_TAB has: forename (first), name (last), long_name (full)
    $studentStmt = $conn->prepare("SELECT id, forename, name, long_name FROM STUDENT_TAB WHERE forename IS NOT NULL AND name IS NOT NULL");
    if ($studentStmt) {
        $studentStmt->execute();
        $studentResult = $studentStmt->get_result();
        
        while ($row = $studentResult->fetch_assoc()) {
            $studentId = (int)$row['id'];
            $forename = trim($row['forename'] ?? '');
            $name = trim($row['name'] ?? '');
            $longName = trim($row['long_name'] ?? '');
            
            if (empty($forename) || empty($name)) {
                continue;
            }
            
            // Normalize names
            $normalizedForename = normalizeTextForMatching($forename);
            $normalizedName = normalizeTextForMatching($name);
            $normalizedLongName = normalizeTextForMatching($longName);
            
            // Check if invoice contains both first and last name
            if (containsBothNames($normalizedInvoiceText, $normalizedForename, $normalizedName)) {
                // Prefer full name match if available
                $matchedName = !empty($normalizedLongName) && strpos($normalizedInvoiceText, $normalizedLongName) !== false 
                    ? $longName 
                    : "$forename $name";
                
                $matchLength = strlen($matchedName);
                
                $candidates[] = [
                    'student_id' => $studentId,
                    'matched_name' => $matchedName,
                    'entity_type' => 'student',
                    'match_length' => $matchLength,
                    'is_full_name' => !empty($normalizedLongName) && strpos($normalizedInvoiceText, $normalizedLongName) !== false
                ];
            }
        }
        $studentStmt->close();
    }
    
    // 2. Check guardian names
    // LEGAL_GUARDIAN_TAB has: first_name, last_name
    $guardianStmt = $conn->prepare("SELECT id, first_name, last_name FROM LEGAL_GUARDIAN_TAB WHERE first_name IS NOT NULL AND last_name IS NOT NULL");
    if ($guardianStmt) {
        $guardianStmt->execute();
        $guardianResult = $guardianStmt->get_result();
        
        while ($row = $guardianResult->fetch_assoc()) {
            $guardianId = (int)$row['id'];
            $firstName = trim($row['first_name'] ?? '');
            $lastName = trim($row['last_name'] ?? '');
            
            if (empty($firstName) || empty($lastName)) {
                continue;
            }
            
            // Normalize names
            $normalizedFirstName = normalizeTextForMatching($firstName);
            $normalizedLastName = normalizeTextForMatching($lastName);
            
            // Check if invoice contains both first and last name
            if (containsBothNames($normalizedInvoiceText, $normalizedFirstName, $normalizedLastName)) {
                $matchedName = "$firstName $lastName";
                $matchLength = strlen($matchedName);
                
                // Resolve guardian to student(s) by matching last name
                // Find students with matching last name
                $studentFromGuardianStmt = $conn->prepare("SELECT id FROM STUDENT_TAB WHERE name = ? ORDER BY id ASC LIMIT 1");
                if ($studentFromGuardianStmt) {
                    $studentFromGuardianStmt->bind_param("s", $lastName);
                    $studentFromGuardianStmt->execute();
                    $studentFromGuardianResult = $studentFromGuardianStmt->get_result();
                    
                    if ($studentRow = $studentFromGuardianResult->fetch_assoc()) {
                        $candidates[] = [
                            'student_id' => (int)$studentRow['id'],
                            'matched_name' => $matchedName,
                            'entity_type' => 'guardian',
                            'match_length' => $matchLength,
                            'is_full_name' => true
                        ];
                    }
                    $studentFromGuardianStmt->close();
                }
            }
        }
        $guardianStmt->close();
    }
    
    if (empty($candidates)) {
        return null;
    }
    
    // Select best candidate:
    // 1. Prioritize exact full-name containment (is_full_name = true)
    // 2. If tied, longest matched name
    // 3. If still tied, smallest student_id (deterministic)
    usort($candidates, function($a, $b) {
        // Primary: full name match
        if ($a['is_full_name'] !== $b['is_full_name']) {
            return $b['is_full_name'] <=> $a['is_full_name'];
        }
        // Secondary: longest match
        if ($a['match_length'] !== $b['match_length']) {
            return $b['match_length'] <=> $a['match_length'];
        }
        // Tertiary: smallest student_id
        return $a['student_id'] <=> $b['student_id'];
    });
    
    return $candidates[0];
}

/**
 * Finds fuzzy name-based suggestions when exact name matching fails
 * Uses partial matching (e.g., only last name, or partial first name match)
 * 
 * @param int $invoiceId The invoice ID
 * @param string $normalizedInvoiceText Normalized invoice text
 * @param mysqli $conn Database connection
 * @return array|null ['student_id' => int, 'matched_name' => string, 'entity_type' => 'student'|'guardian', 'confidence' => float] or null
 */
function findFuzzyNameSuggestion($invoiceId, $normalizedInvoiceText, $conn) {
    $candidates = [];
    
    // Split invoice text into words for partial matching
    $invoiceWords = explode(' ', $normalizedInvoiceText);
    $invoiceWordsSet = array_flip($invoiceWords);
    
    // 1. Check student names (partial matching)
    $studentStmt = $conn->prepare("SELECT id, forename, name, long_name FROM STUDENT_TAB WHERE forename IS NOT NULL AND name IS NOT NULL");
    if ($studentStmt) {
        $studentStmt->execute();
        $studentResult = $studentStmt->get_result();
        
        while ($row = $studentResult->fetch_assoc()) {
            $studentId = (int)$row['id'];
            $forename = trim($row['forename'] ?? '');
            $name = trim($row['name'] ?? '');
            $longName = trim($row['long_name'] ?? '');
            
            if (empty($forename) || empty($name)) {
                continue;
            }
            
            // Normalize names
            $normalizedForename = normalizeTextForMatching($forename);
            $normalizedName = normalizeTextForMatching($name);
            $normalizedLongName = normalizeTextForMatching($longName);
            
            // Calculate partial match score
            $score = 0;
            $matchedParts = [];
            
            // Check if last name matches (as word or substring)
            if (isset($invoiceWordsSet[$normalizedName]) || strpos($normalizedInvoiceText, $normalizedName) !== false) {
                $score += 20;
                $matchedParts[] = $name;
            }
            
            // Check if first name matches (as word or substring)
            if (isset($invoiceWordsSet[$normalizedForename]) || strpos($normalizedInvoiceText, $normalizedForename) !== false) {
                $score += 15;
                $matchedParts[] = $forename;
            }
            
            // Check if full name matches as substring
            if (!empty($normalizedLongName) && strpos($normalizedInvoiceText, $normalizedLongName) !== false) {
                $score += 10; // Bonus for full name match
            }
            
            // Only consider if we have at least last name match
            if ($score >= 20) {
                $matchedName = !empty($matchedParts) ? implode(' ', $matchedParts) : $name;
                $matchLength = strlen($matchedName);
                
                $candidates[] = [
                    'student_id' => $studentId,
                    'matched_name' => $matchedName,
                    'entity_type' => 'student',
                    'match_length' => $matchLength,
                    'score' => $score,
                    'is_full_name' => !empty($normalizedLongName) && strpos($normalizedInvoiceText, $normalizedLongName) !== false
                ];
            }
        }
        $studentStmt->close();
    }
    
    // 2. Check guardian names (partial matching)
    $guardianStmt = $conn->prepare("SELECT id, first_name, last_name FROM LEGAL_GUARDIAN_TAB WHERE first_name IS NOT NULL AND last_name IS NOT NULL");
    if ($guardianStmt) {
        $guardianStmt->execute();
        $guardianResult = $guardianStmt->get_result();
        
        while ($row = $guardianResult->fetch_assoc()) {
            $guardianId = (int)$row['id'];
            $firstName = trim($row['first_name'] ?? '');
            $lastName = trim($row['last_name'] ?? '');
            
            if (empty($firstName) || empty($lastName)) {
                continue;
            }
            
            // Normalize names
            $normalizedFirstName = normalizeTextForMatching($firstName);
            $normalizedLastName = normalizeTextForMatching($lastName);
            
            // Calculate partial match score
            $score = 0;
            $matchedParts = [];
            
            // Check if last name matches
            if (isset($invoiceWordsSet[$normalizedLastName]) || strpos($normalizedInvoiceText, $normalizedLastName) !== false) {
                $score += 20;
                $matchedParts[] = $lastName;
            }
            
            // Check if first name matches
            if (isset($invoiceWordsSet[$normalizedFirstName]) || strpos($normalizedInvoiceText, $normalizedFirstName) !== false) {
                $score += 15;
                $matchedParts[] = $firstName;
            }
            
            // Only consider if we have at least last name match
            if ($score >= 20) {
                $matchedName = !empty($matchedParts) ? implode(' ', $matchedParts) : $lastName;
                $matchLength = strlen($matchedName);
                
                // Resolve guardian to student(s) by matching last name
                $studentFromGuardianStmt = $conn->prepare("SELECT id FROM STUDENT_TAB WHERE name = ? ORDER BY id ASC LIMIT 1");
                if ($studentFromGuardianStmt) {
                    $studentFromGuardianStmt->bind_param("s", $lastName);
                    $studentFromGuardianStmt->execute();
                    $studentFromGuardianResult = $studentFromGuardianStmt->get_result();
                    
                    if ($studentRow = $studentFromGuardianResult->fetch_assoc()) {
                        $candidates[] = [
                            'student_id' => (int)$studentRow['id'],
                            'matched_name' => $matchedName,
                            'entity_type' => 'guardian',
                            'match_length' => $matchLength,
                            'score' => $score,
                            'is_full_name' => false
                        ];
                    }
                    $studentFromGuardianStmt->close();
                }
            }
        }
        $guardianStmt->close();
    }
    
    if (empty($candidates)) {
        return null;
    }
    
    // Select best candidate:
    // 1. Highest score
    // 2. Full name match (is_full_name = true)
    // 3. Longest matched name
    // 4. Smallest student_id (deterministic)
    usort($candidates, function($a, $b) {
        // Primary: highest score
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        // Secondary: full name match
        if ($a['is_full_name'] !== $b['is_full_name']) {
            return $b['is_full_name'] <=> $a['is_full_name'];
        }
        // Tertiary: longest match
        if ($a['match_length'] !== $b['match_length']) {
            return $b['match_length'] <=> $a['match_length'];
        }
        // Quaternary: smallest student_id
        return $a['student_id'] <=> $b['student_id'];
    });
    
    $best = $candidates[0];
    // Convert score to confidence (20-45 range, capped at 45 for fuzzy)
    $confidence = min(45, max(20, $best['score']));
    
    return [
        'student_id' => $best['student_id'],
        'matched_name' => $best['matched_name'],
        'entity_type' => $best['entity_type'],
        'confidence' => $confidence
    ];
}

/**
 * Gets fuzzy suggestions for unmatched reference IDs in an invoice
 * 
 * @param int $invoiceId The invoice ID
 * @param mysqli $conn Database connection
 * @return array ['suggestions' => array, 'status' => string]
 */
function getFuzzySuggestions($invoiceId, $conn) {
    // #region agent log
    // Use main directory instead of .cursor subdirectory (permissions issue on shared hosting)
    $logFile = __DIR__ . '/debug_matching.log';
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'fuzzy-debug',
        'hypothesisId' => 'F0',
        'location' => 'matching_engine.php:197',
        'message' => 'getFuzzySuggestions called',
        'data' => ['invoiceId' => $invoiceId],
        'timestamp' => round(microtime(true) * 1000)
    ]) . "\n";
    $writeResult = @file_put_contents($logFile, $logEntry, FILE_APPEND);
    if (!$writeResult) {
        error_log("FUZZY DEBUG ERROR: Failed to write to log file: $logFile (check permissions)");
    }
    error_log("FUZZY DEBUG: getFuzzySuggestions called for invoice_id=$invoiceId, writeResult=" . ($writeResult ? 'SUCCESS' : 'FAILED'));
    // #endregion
    
    // Load invoice data
    $stmt = $conn->prepare("SELECT id, reference, description, beneficiary FROM INVOICE_TAB WHERE id = ?");
    if (!$stmt) {
        // #region agent log
        $logEntry = json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'fuzzy-debug',
            'hypothesisId' => 'F0',
            'location' => 'matching_engine.php:199',
            'message' => 'Failed to prepare invoice SELECT',
            'data' => ['invoiceId' => $invoiceId, 'error' => $conn->error],
            'timestamp' => round(microtime(true) * 1000)
        ]) . "\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        // #endregion
        return ['suggestions' => [], 'status' => 'error', 'message' => 'Failed to load invoice'];
    }
    
    $stmt->bind_param("i", $invoiceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    $stmt->close();
    
    if (!$invoice) {
        return ['suggestions' => [], 'status' => 'error', 'message' => 'Invoice not found'];
    }
    
    // Combine text fields and extract reference IDs
    $descriptionText = trim(($invoice['reference'] ?? '') . ' ' . ($invoice['description'] ?? '') . ' ' . ($invoice['beneficiary'] ?? ''));
    $referenceIds = extractReferenceIds($descriptionText);
    
    // If no reference IDs found, try both name-based matching and fuzzy reference matching (last resort)
    if (empty($referenceIds)) {
        $suggestions = [];
        $historyTableExists = false;
        $historyHasIsConfirmed = false;
        $tableCheck = $conn->query("SHOW TABLES LIKE 'MATCHING_HISTORY_TAB'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $historyTableExists = true;
            $historyHasIsConfirmed = columnExists($conn, 'MATCHING_HISTORY_TAB', 'is_confirmed');
        }
        
        // 1. Try exact name-based matching (name_suggest)
        $normalizedInvoiceText = normalizeTextForMatching($descriptionText);
        $nameSuggestion = findNameBasedSuggestion($invoiceId, $normalizedInvoiceText, $conn);
        
        if ($nameSuggestion) {
            $suggestion = [
                'input_reference' => null,
                'suggested_reference' => null,
                'suggested_student_id' => $nameSuggestion['student_id'],
                'confidence_score' => 50,
                'method' => 'name_suggest',
                'status' => 'suggested',
                'matched_entity_type' => $nameSuggestion['entity_type'],
                'matched_name' => $nameSuggestion['matched_name']
            ];
            $suggestions[] = $suggestion;
            
            // Log to MATCHING_HISTORY_TAB (with duplicate prevention)
            if ($historyTableExists) {
                $checkHistoryStmt = $conn->prepare("
                    SELECT id FROM MATCHING_HISTORY_TAB 
                    WHERE invoice_id = ? AND student_id = ? AND matched_by = 'name_suggest'
                    LIMIT 1
                ");
                if ($checkHistoryStmt) {
                    $checkHistoryStmt->bind_param("ii", $invoiceId, $nameSuggestion['student_id']);
                    $checkHistoryStmt->execute();
                    $checkHistoryResult = $checkHistoryStmt->get_result();
                    $exists = $checkHistoryResult->fetch_assoc();
                    $checkHistoryStmt->close();
                    
                    // Only insert if it doesn't exist
                    if (!$exists) {
                        $cols = "invoice_id, student_id, confidence_score, matched_by";
                        $vals = "?, ?, 50, 'name_suggest'";
                        if ($historyHasIsConfirmed) { $cols .= ", is_confirmed"; $vals .= ", 0"; }
                        $historyStmt = $conn->prepare("INSERT INTO MATCHING_HISTORY_TAB ($cols) VALUES ($vals)");
                        if ($historyStmt) {
                            $historyStmt->bind_param("ii", $invoiceId, $nameSuggestion['student_id']);
                            $historyStmt->execute();
                            $historyStmt->close();
                        }
                    }
                }
            }
        } else {
            // 1b. If exact name matching failed, try fuzzy name matching (name_fuzzy)
            $fuzzyNameSuggestion = findFuzzyNameSuggestion($invoiceId, $normalizedInvoiceText, $conn);
            
            if ($fuzzyNameSuggestion) {
                $suggestion = [
                    'input_reference' => null,
                    'suggested_reference' => null,
                    'suggested_student_id' => $fuzzyNameSuggestion['student_id'],
                    'confidence_score' => round($fuzzyNameSuggestion['confidence'], 2),
                    'method' => 'name_fuzzy',
                    'status' => 'suggested',
                    'matched_entity_type' => $fuzzyNameSuggestion['entity_type'],
                    'matched_name' => $fuzzyNameSuggestion['matched_name']
                ];
                $suggestions[] = $suggestion;
                
                // Log to MATCHING_HISTORY_TAB (with duplicate prevention)
                if ($historyTableExists) {
                    $checkHistoryStmt = $conn->prepare("
                        SELECT id FROM MATCHING_HISTORY_TAB 
                        WHERE invoice_id = ? AND student_id = ? AND matched_by = 'name_suggest'
                        LIMIT 1
                    ");
                    if ($checkHistoryStmt) {
                        $checkHistoryStmt->bind_param("ii", $invoiceId, $fuzzyNameSuggestion['student_id']);
                        $checkHistoryStmt->execute();
                        $checkHistoryResult = $checkHistoryStmt->get_result();
                        $exists = $checkHistoryResult->fetch_assoc();
                        $checkHistoryStmt->close();
                        
                        // Only insert if it doesn't exist (ENUM: name_fuzzy stored as name_suggest)
                        if (!$exists) {
                            $cols = "invoice_id, student_id, confidence_score, matched_by";
                            $vals = "?, ?, ?, 'name_suggest'";
                            if ($historyHasIsConfirmed) { $cols .= ", is_confirmed"; $vals .= ", 0"; }
                            $historyStmt = $conn->prepare("INSERT INTO MATCHING_HISTORY_TAB ($cols) VALUES ($vals)");
                            if ($historyStmt) {
                                $confidenceScore = round($fuzzyNameSuggestion['confidence'], 2);
                                $historyStmt->bind_param("iid", $invoiceId, $fuzzyNameSuggestion['student_id'], $confidenceScore);
                                $historyStmt->execute();
                                $historyStmt->close();
                            }
                        }
                    }
                }
            }
        }
        
        // 2. Also try fuzzy reference matching on potential reference patterns
        // Try a more lenient extraction: look for any HTL-* patterns even if they don't match strict format
        if (preg_match_all('/HTL-[A-Za-z0-9\-]+/i', $descriptionText, $potentialRefs)) {
            foreach ($potentialRefs[0] as $potentialRef) {
                $normalizedPotentialRef = strtoupper(trim($potentialRef));
                
                // Skip if it's already in our extracted list (shouldn't happen, but safety check)
                if (in_array($normalizedPotentialRef, $referenceIds)) {
                    continue;
                }
                
                // Check if exact match exists
                $checkExactStmt = $conn->prepare("SELECT id FROM STUDENT_TAB WHERE reference_id = ? LIMIT 1");
                if ($checkExactStmt) {
                    $checkExactStmt->bind_param("s", $normalizedPotentialRef);
                    $checkExactStmt->execute();
                    $checkExactResult = $checkExactStmt->get_result();
                    if ($checkExactResult->fetch_assoc()) {
                        $checkExactStmt->close();
                        continue; // Skip exact matches
                    }
                    $checkExactStmt->close();
                }
                
                // Try fuzzy matching
                $fuzzyMatch = findBestFuzzyMatch($normalizedPotentialRef, $conn, 55.0);
                
                if ($fuzzyMatch) {
                    $suggestion = [
                        'input_reference' => $normalizedPotentialRef,
                        'suggested_reference' => $fuzzyMatch['reference'],
                        'suggested_student_id' => $fuzzyMatch['student_id'],
                        'confidence_score' => round($fuzzyMatch['confidence'], 2),
                        'method' => 'reference_fuzzy',
                        'status' => 'suggested'
                    ];
                    $suggestions[] = $suggestion;
                    
                    // Log to MATCHING_HISTORY_TAB (with duplicate prevention)
                    if ($historyTableExists) {
                        $checkHistoryStmt = $conn->prepare("
                            SELECT id FROM MATCHING_HISTORY_TAB 
                            WHERE invoice_id = ? AND student_id = ? AND matched_by = 'reference_fuzzy'
                            LIMIT 1
                        ");
                        if ($checkHistoryStmt) {
                            $checkHistoryStmt->bind_param("ii", $invoiceId, $fuzzyMatch['student_id']);
                            $checkHistoryStmt->execute();
                            $checkHistoryResult = $checkHistoryStmt->get_result();
                            $exists = $checkHistoryResult->fetch_assoc();
                            $checkHistoryStmt->close();
                            
                            // Only insert if it doesn't exist (suggestion only: is_confirmed=0)
                            if (!$exists) {
                                $cols = "invoice_id, student_id, confidence_score, matched_by";
                                $vals = "?, ?, ?, 'reference_fuzzy'";
                                if ($historyHasIsConfirmed) { $cols .= ", is_confirmed"; $vals .= ", 0"; }
                                $historyStmt = $conn->prepare("INSERT INTO MATCHING_HISTORY_TAB ($cols) VALUES ($vals)");
                                if ($historyStmt) {
                                    $confidenceScore = round($fuzzyMatch['confidence'], 2);
                                    $historyStmt->bind_param("iid", $invoiceId, $fuzzyMatch['student_id'], $confidenceScore);
                                    $historyStmt->execute();
                                    $historyStmt->close();
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Return suggestions (can include name_suggest, name_fuzzy, and reference_fuzzy)
        if (!empty($suggestions)) {
            return ['suggestions' => $suggestions, 'status' => 'success'];
        } else {
            return ['suggestions' => [], 'status' => 'no_references'];
        }
    }
    
    // Check which references have exact matches
    $exactMatches = [];
    foreach ($referenceIds as $refId) {
        $checkStmt = $conn->prepare("SELECT id FROM STUDENT_TAB WHERE reference_id = ? LIMIT 1");
        if ($checkStmt) {
            $checkStmt->bind_param("s", $refId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            if ($checkResult->fetch_assoc()) {
                $exactMatches[$refId] = true;
            }
            $checkStmt->close();
        }
    }
    
    // Get fuzzy suggestions for unmatched references
    $suggestions = [];
    $historyTableExists = false;
    $historyHasIsConfirmed = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'MATCHING_HISTORY_TAB'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $historyTableExists = true;
        $historyHasIsConfirmed = columnExists($conn, 'MATCHING_HISTORY_TAB', 'is_confirmed');
    }
    
    // #region agent log
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'fuzzy-debug',
        'hypothesisId' => 'F0',
        'location' => 'matching_engine.php:268',
        'message' => 'Table check result',
        'data' => ['historyTableExists' => $historyTableExists, 'referenceIds' => $referenceIds, 'exactMatches' => array_keys($exactMatches), 'totalRefs' => count($referenceIds), 'totalExact' => count($exactMatches)],
        'timestamp' => round(microtime(true) * 1000)
    ]) . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
    // #endregion
    
    error_log("FUZZY DEBUG: invoice_id=$invoiceId, total_refs=" . count($referenceIds) . ", exact_matches=" . count($exactMatches) . ", unmatched=" . (count($referenceIds) - count($exactMatches)) . ", historyTableExists=" . ($historyTableExists ? 'YES' : 'NO'));
    
    $fuzzyMatchCount = 0;
    $fuzzyMatchInsertedCount = 0;
    
    foreach ($referenceIds as $refId) {
        // Skip if exact match exists
        if (isset($exactMatches[$refId])) {
            error_log("FUZZY DEBUG: Skipping $refId - has exact match");
            continue;
        }
        
        error_log("FUZZY DEBUG: Processing unmatched reference: $refId");
        
        // Find best fuzzy match
        $fuzzyMatch = findBestFuzzyMatch($refId, $conn, 55.0);
        
        if ($fuzzyMatch) {
            $fuzzyMatchCount++;
            error_log("FUZZY DEBUG: Found fuzzy match for $refId -> {$fuzzyMatch['reference']} (confidence={$fuzzyMatch['confidence']}, student_id={$fuzzyMatch['student_id']})");
            
            $suggestion = [
                'input_reference' => $refId,
                'suggested_reference' => $fuzzyMatch['reference'],
                'suggested_student_id' => $fuzzyMatch['student_id'],
                'confidence_score' => round($fuzzyMatch['confidence'], 2),
                'method' => 'reference_fuzzy',
                'status' => 'suggested'
            ];
            $suggestions[] = $suggestion;
            
            // Log to MATCHING_HISTORY_TAB (with duplicate prevention)
            if ($historyTableExists) {
                // #region agent log
                // Use main directory instead of .cursor subdirectory (permissions issue on shared hosting)
                $logFile = __DIR__ . '/debug_matching.log';
                $logEntry = json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'fuzzy-debug',
                    'hypothesisId' => 'F1',
                    'location' => 'matching_engine.php:265',
                    'message' => 'Attempting MATCHING_HISTORY_TAB insert for fuzzy match',
                    'data' => ['invoiceId' => $invoiceId, 'studentId' => $fuzzyMatch['student_id'], 'confidence' => $fuzzyMatch['confidence'], 'historyTableExists' => $historyTableExists],
                    'timestamp' => round(microtime(true) * 1000)
                ]) . "\n";
                @file_put_contents($logFile, $logEntry, FILE_APPEND);
                // #endregion
                
                // Check if entry already exists
                $checkHistoryStmt = $conn->prepare("
                    SELECT id FROM MATCHING_HISTORY_TAB 
                    WHERE invoice_id = ? AND student_id = ? AND matched_by = 'reference_fuzzy'
                    LIMIT 1
                ");
                
                if (!$checkHistoryStmt) {
                    // #region agent log
                    $logEntry = json_encode([
                        'sessionId' => 'debug-session',
                        'runId' => 'fuzzy-debug',
                        'hypothesisId' => 'F2',
                        'location' => 'matching_engine.php:273',
                        'message' => 'Failed to prepare duplicate check statement',
                        'data' => ['invoiceId' => $invoiceId, 'studentId' => $fuzzyMatch['student_id'], 'error' => $conn->error],
                        'timestamp' => round(microtime(true) * 1000)
                    ]) . "\n";
                    @file_put_contents($logFile, $logEntry, FILE_APPEND);
                    // #endregion
                } else {
                    $checkHistoryStmt->bind_param("ii", $invoiceId, $fuzzyMatch['student_id']);
                    $checkExecSuccess = $checkHistoryStmt->execute();
                    $checkHistoryResult = $checkHistoryStmt->get_result();
                    $exists = $checkHistoryResult->fetch_assoc();
                    $checkHistoryStmt->close();
                    
                    // #region agent log
                    $logEntry = json_encode([
                        'sessionId' => 'debug-session',
                        'runId' => 'fuzzy-debug',
                        'hypothesisId' => 'F3',
                        'location' => 'matching_engine.php:315',
                        'message' => 'Duplicate check result',
                        'data' => ['invoiceId' => $invoiceId, 'studentId' => $fuzzyMatch['student_id'], 'exists' => ($exists !== null), 'checkExecSuccess' => $checkExecSuccess, 'existingRow' => $exists],
                        'timestamp' => round(microtime(true) * 1000)
                    ]) . "\n";
                    @file_put_contents($logFile, $logEntry, FILE_APPEND);
                    // #endregion
                    
                    // Only insert if it doesn't exist
                    if ($exists) {
                        // Duplicate found - log and skip insert
                        error_log("FUZZY MATCH: Duplicate entry found - skipping INSERT for invoice_id=$invoiceId, student_id={$fuzzyMatch['student_id']}");
                    }
                    
                    if (!$exists) {
                        // #region agent log
                        $logEntry = json_encode([
                            'sessionId' => 'debug-session',
                            'runId' => 'fuzzy-debug',
                            'hypothesisId' => 'F3',
                            'location' => 'matching_engine.php:370',
                            'message' => 'No duplicate found, proceeding with INSERT',
                            'data' => ['invoiceId' => $invoiceId, 'studentId' => $fuzzyMatch['student_id']],
                            'timestamp' => round(microtime(true) * 1000)
                        ]) . "\n";
                        @file_put_contents($logFile, $logEntry, FILE_APPEND);
                        // #endregion
                        error_log("FUZZY MATCH: No duplicate found for invoice_id=$invoiceId, student_id={$fuzzyMatch['student_id']}, proceeding with INSERT");
                        $cols = "invoice_id, student_id, confidence_score, matched_by";
                        $vals = "?, ?, ?, 'reference_fuzzy'";
                        if ($historyHasIsConfirmed) { $cols .= ", is_confirmed"; $vals .= ", 0"; }
                        $historyStmt = $conn->prepare("INSERT INTO MATCHING_HISTORY_TAB ($cols) VALUES ($vals)");
                        
                        if (!$historyStmt) {
                            // #region agent log
                            $logEntry = json_encode([
                                'sessionId' => 'debug-session',
                                'runId' => 'fuzzy-debug',
                                'hypothesisId' => 'F4',
                                'location' => 'matching_engine.php:282',
                                'message' => 'Failed to prepare INSERT statement',
                                'data' => ['invoiceId' => $invoiceId, 'studentId' => $fuzzyMatch['student_id'], 'error' => $conn->error],
                                'timestamp' => round(microtime(true) * 1000)
                            ]) . "\n";
                            @file_put_contents($logFile, $logEntry, FILE_APPEND);
                            // #endregion
                        } else {
                            // Get the actual confidence score from fuzzy match
                            // Round to 2 decimals, then convert to integer (0-100 scale) if column is INT
                            // Otherwise keep as float if column is DECIMAL/FLOAT
                            $confidenceScoreFloat = round($fuzzyMatch['confidence'], 2);
                            $confidenceScoreInt = (int)round($fuzzyMatch['confidence']);
                            
                            // #region agent log
                            $logEntry = json_encode([
                                'sessionId' => 'debug-session',
                                'runId' => 'fuzzy-debug',
                                'hypothesisId' => 'F8',
                                'location' => 'matching_engine.php:408',
                                'message' => 'Preparing to insert confidence_score',
                                'data' => ['invoiceId' => $invoiceId, 'studentId' => $fuzzyMatch['student_id'], 'rawConfidence' => $fuzzyMatch['confidence'], 'floatConfidence' => $confidenceScoreFloat, 'intConfidence' => $confidenceScoreInt],
                                'timestamp' => round(microtime(true) * 1000)
                            ]) . "\n";
                            @file_put_contents($logFile, $logEntry, FILE_APPEND);
                            // #endregion
                            
                            // Verify the confidence value before binding
                            error_log("FUZZY MATCH: About to insert confidence_score (float=$confidenceScoreFloat, int=$confidenceScoreInt, raw={$fuzzyMatch['confidence']}) for invoice_id=$invoiceId, student_id={$fuzzyMatch['student_id']}");
                            error_log("FUZZY MATCH: Full fuzzyMatch array: " . json_encode($fuzzyMatch));
                            
                            // Ensure confidence is a valid number between 0-100
                            if ($confidenceScoreInt < 0 || $confidenceScoreInt > 100) {
                                error_log("FUZZY MATCH ERROR: Invalid confidence score $confidenceScoreInt, clamping to 0-100");
                                $confidenceScoreInt = max(0, min(100, $confidenceScoreInt));
                            }
                            
                            $zero = 0;
                            $bindSuccess = $historyHasIsConfirmed
                                ? $historyStmt->bind_param("iiii", $invoiceId, $fuzzyMatch['student_id'], $confidenceScoreInt, $zero)
                                : $historyStmt->bind_param("iii", $invoiceId, $fuzzyMatch['student_id'], $confidenceScoreInt);
                            
                            // Verify bind was successful
                            if (!$bindSuccess) {
                                error_log("FUZZY MATCH ERROR: bind_param failed - trying to bind invoice_id=$invoiceId (int), student_id={$fuzzyMatch['student_id']} (int), confidence=$confidenceScoreInt (int)");
                            } else {
                                error_log("FUZZY MATCH: bind_param succeeded with confidence=$confidenceScoreInt");
                            }
                            
                            if (!$bindSuccess) {
                                // #region agent log
                                $logEntry = json_encode([
                                    'sessionId' => 'debug-session',
                                    'runId' => 'fuzzy-debug',
                                    'hypothesisId' => 'F5',
                                    'location' => 'matching_engine.php:432',
                                    'message' => 'Failed to bind parameters',
                                    'data' => ['invoiceId' => $invoiceId, 'studentId' => $fuzzyMatch['student_id'], 'confidenceScoreInt' => $confidenceScoreInt, 'rawConfidence' => $fuzzyMatch['confidence'], 'error' => $historyStmt->error],
                                    'timestamp' => round(microtime(true) * 1000)
                                ]) . "\n";
                                @file_put_contents($logFile, $logEntry, FILE_APPEND);
                                // #endregion
                                $historyStmt->close();
                            } else {
                                $insertSuccess = $historyStmt->execute();
                                $insertError = $historyStmt->error;
                                $insertErrno = $historyStmt->errno;
                                $insertId = $historyStmt->insert_id;
                                $affectedRows = $historyStmt->affected_rows;
                                $historyStmt->close();
                                
                                // Verify the INSERT actually worked by querying the database
                                $verifyStmt = $conn->prepare("
                                    SELECT id, confidence_score, matched_by 
                                    FROM MATCHING_HISTORY_TAB 
                                    WHERE invoice_id = ? AND student_id = ? AND matched_by = 'reference_fuzzy'
                                    LIMIT 1
                                ");
                                $verifyExists = false;
                                $verifyConfidence = null;
                                if ($verifyStmt) {
                                    $verifyStmt->bind_param("ii", $invoiceId, $fuzzyMatch['student_id']);
                                    $verifyStmt->execute();
                                    $verifyResult = $verifyStmt->get_result();
                                    $verifyRow = $verifyResult->fetch_assoc();
                                    $verifyStmt->close();
                                    if ($verifyRow) {
                                        $verifyExists = true;
                                        $verifyConfidence = $verifyRow['confidence_score'];
                                    }
                                }
                                
                                // #region agent log
                                $logEntry = json_encode([
                                    'sessionId' => 'debug-session',
                                    'runId' => 'fuzzy-debug',
                                    'hypothesisId' => 'F6',
                                    'location' => 'matching_engine.php:460',
                                    'message' => $insertSuccess ? 'MATCHING_HISTORY_TAB INSERT successful' : 'MATCHING_HISTORY_TAB INSERT failed',
                                    'data' => ['invoiceId' => $invoiceId, 'studentId' => $fuzzyMatch['student_id'], 'confidenceScoreInt' => $confidenceScoreInt, 'rawConfidence' => $fuzzyMatch['confidence'], 'success' => $insertSuccess, 'error' => $insertError, 'errno' => $insertErrno, 'insertId' => $insertId, 'affectedRows' => $affectedRows, 'verifyExists' => $verifyExists, 'verifyConfidence' => $verifyConfidence],
                                    'timestamp' => round(microtime(true) * 1000)
                                ]) . "\n";
                                @file_put_contents($logFile, $logEntry, FILE_APPEND);
                                // #endregion
                                
                                // Backup logging via error_log (PHP error log) - this should work even if file write fails
                                if ($insertSuccess) {
                                    error_log("FUZZY MATCH INSERT SUCCESS: invoice_id=$invoiceId, student_id={$fuzzyMatch['student_id']}, confidence_int=$confidenceScoreInt, confidence_raw={$fuzzyMatch['confidence']}, insert_id=$insertId, affected_rows=$affectedRows, verified_in_db=" . ($verifyExists ? 'YES' : 'NO') . ", db_confidence=$verifyConfidence");
                                } else {
                                    error_log("FUZZY MATCH INSERT FAILED: invoice_id=$invoiceId, student_id={$fuzzyMatch['student_id']}, confidence_int=$confidenceScoreInt, confidence_raw={$fuzzyMatch['confidence']}, error=$insertError, errno=$insertErrno");
                                }
                                
                                // If INSERT succeeded but verification failed, log warning
                                if ($insertSuccess && !$verifyExists) {
                                    error_log("FUZZY MATCH WARNING: INSERT reported success but row not found in database! invoice_id=$invoiceId, student_id={$fuzzyMatch['student_id']}");
                                } else if ($insertSuccess && $verifyExists) {
                                    $fuzzyMatchInsertedCount++;
                                    error_log("FUZZY MATCH SUCCESS: Entry verified in database! invoice_id=$invoiceId, student_id={$fuzzyMatch['student_id']}, confidence=$verifyConfidence");
                                }
                                
                                // If confidence doesn't match, log warning
                                if ($verifyExists && $verifyConfidence != $confidenceScoreInt) {
                                    error_log("FUZZY MATCH WARNING: Confidence mismatch! Expected=$confidenceScoreInt, Found in DB=$verifyConfidence");
                                }
                            }
                        }
                    }
                }
            } else {
                // #region agent log
                // Use main directory instead of .cursor subdirectory (permissions issue on shared hosting)
                $logFile = __DIR__ . '/debug_matching.log';
                $logEntry = json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'fuzzy-debug',
                    'hypothesisId' => 'F7',
                    'location' => 'matching_engine.php:266',
                    'message' => 'MATCHING_HISTORY_TAB does not exist',
                    'data' => ['invoiceId' => $invoiceId],
                    'timestamp' => round(microtime(true) * 1000)
                ]) . "\n";
                @file_put_contents($logFile, $logEntry, FILE_APPEND);
                // #endregion
            }
        } else {
            // No fuzzy match found above threshold
            error_log("FUZZY DEBUG: No fuzzy match found for $refId (below 55% threshold or no candidates)");
            $suggestions[] = [
                'input_reference' => $refId,
                'suggested_reference' => null,
                'suggested_student_id' => null,
                'confidence_score' => 0,
                'method' => 'reference_fuzzy',
                'status' => 'unmatched'
            ];
        }
    }
    
    error_log("FUZZY DEBUG SUMMARY: invoice_id=$invoiceId, fuzzy_matches_found=$fuzzyMatchCount, fuzzy_matches_inserted=$fuzzyMatchInsertedCount, total_suggestions=" . count($suggestions));
    
    return ['suggestions' => $suggestions, 'status' => 'success'];
}

/**
 * Attempts to match an invoice with 0-4 students based on reference IDs
 * 
 * @param int $invoiceId The ID of the invoice in INVOICE_TAB
 * @param mysqli $conn Database connection object
 * @return array Returns ['success' => true, 'student_id' => X, 'reference' => Y, 'total_matches' => N] if matched, 
 *               ['success' => false] if not. Creates one MATCHING_HISTORY_TAB entry per matched student.
 */
/**
 * Record a manual confirmation (UI: user assigned invoice to student).
 * Single place for writing MATCHING_HISTORY_TAB for confirmations; keeps matching in engine.
 */
function recordManualConfirmation(mysqli $conn, int $invoice_id, int $student_id, float $confidence = 100.0, string $matched_by = 'manual'): bool
{
    $hasIsConfirmed = columnExists($conn, 'MATCHING_HISTORY_TAB', 'is_confirmed');
    $sql = "INSERT INTO MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by" . ($hasIsConfirmed ? ", is_confirmed" : "") . ")
            VALUES (?, ?, ?, ?" . ($hasIsConfirmed ? ", 1" : "") . ")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("iids", $invoice_id, $student_id, $confidence, $matched_by);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

function notif_exists(mysqli $conn, ?string $invoiceReference, string $urgency): bool {
    if ($invoiceReference === null || $invoiceReference === '') return false;

    $stmt = $conn->prepare("SELECT id FROM NOTIFICATION_TAB WHERE invoice_reference = ? AND urgency = ? LIMIT 1");
    if (!$stmt) return false;

    $stmt->bind_param("ss", $invoiceReference, $urgency);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function notif_insert(mysqli $conn, ?int $studentId, ?string $invoiceReference, string $urgency, string $description, string $timeFrom): void {
    $hasMail = notif_has_mail_status($conn);

    // if invoice ref is missing, still insert (UI will show —) but it shouldn't crash
    $invoiceReference = ($invoiceReference !== null && $invoiceReference !== '') ? $invoiceReference : null;

    if ($hasMail) {
        $stmt = $conn->prepare("
            INSERT INTO NOTIFICATION_TAB (student_id, invoice_reference, description, time_from, is_read, urgency, mail_status)
            VALUES (?, ?, ?, ?, 0, ?, 'none')
        ");
        if (!$stmt) return;

        // student_id can be NULL
        if ($studentId === null) {
            $null = null;
            $stmt->bind_param("issss", $null, $invoiceReference, $description, $timeFrom, $urgency);
        } else {
            $stmt->bind_param("issss", $studentId, $invoiceReference, $description, $timeFrom, $urgency);
        }
    } else {
        $stmt = $conn->prepare("
            INSERT INTO NOTIFICATION_TAB (student_id, invoice_reference, description, time_from, is_read, urgency)
            VALUES (?, ?, ?, ?, 0, ?)
        ");
        if (!$stmt) return;

        if ($studentId === null) {
            $null = null;
            $stmt->bind_param("issss", $null, $invoiceReference, $description, $timeFrom, $urgency);
        } else {
            $stmt->bind_param("issss", $studentId, $invoiceReference, $description, $timeFrom, $urgency);
        }
    }

    $stmt->execute();
    $stmt->close();
}

function attemptReferenceMatch($invoiceId, $conn) {
    // Legacy entry point kept for backward compatibility.
    // The new system uses the pipeline (runMatchingPipeline) instead.
    error_log("ATTEMPT_REFERENCE_MATCH: legacy function called for invoice_id=$invoiceId – delegating to pipeline");
    if (function_exists('runMatchingPipeline')) {
        runMatchingPipeline((int)$invoiceId);
        return ['success' => true];
    }
    return ['success' => false];
}

/*
 * MANUAL TEST CASES FOR STEP 1 (Reference-ID Extraction)
 * 
 * Test Case 1: Invoice text contains 1 ref exactly
 *   Input: "HTL-XXXYYY-A7"
 *   Expected: extractReferenceIds() returns ["HTL-XXXYYY-A7"]
 *   Expected: exact match works if student exists with reference_id = "HTL-XXXYYY-A7"
 * 
 * Test Case 2: Invoice text contains 2-4 refs mixed with punctuation
 *   Input: "Ref: HTL-ABC123-A7, (HTL-DEF456-B8) and also HTL-GHI789-C9. Final: HTL-JKL012-D0"
 *   Expected: extractReferenceIds() returns ["HTL-ABC123-A7", "HTL-DEF456-B8", "HTL-GHI789-C9", "HTL-JKL012-D0"]
 *   Expected: matching iterates through all 4 in order, first exact hit wins
 * 
 * Test Case 3: Invoice text contains no refs
 *   Input: "Payment for school fees without reference"
 *   Expected: extractReferenceIds() returns []
 *   Expected: attemptReferenceMatch() returns ['success' => false], no match path unchanged
 * 
 * Test Case 4: Invoice text contains repeated same ref
 *   Input: "HTL-ABC123-A7 mentioned again HTL-ABC123-A7 and once more HTL-ABC123-A7"
 *   Expected: extractReferenceIds() returns ["HTL-ABC123-A7"] (only once, first appearance preserved)
 *   Expected: exact match works normally
 * 
 * FUZZY MATCHING TEST CASES
 * 
 * Test Case 5: Unknown ref with fuzzy match
 *   Input: Unknown ref "HTL-XXXYZZ-A6", DB has "HTL-XXXYYY-A7"
 *   Expected: After removing "HTL-" prefix:
 *     - unknown: "XXXYZZ-A6" (length 9)
 *     - candidate: "XXXYYY-A7" (length 9)
 *     - matching letters at positions: X(0), X(1), X(2), Y(3), Z(4) vs Y(4) = 4 matches
 *     - confidence = (4 / 9) * 100 = 44.44 (below 55 threshold) OR
 *     - If "XXXYZZ-A6" vs "XXXYYY-A7": positions 0,1,2 match = 3, max len 9, confidence = 33.33
 *   Note: Actual calculation depends on exact character-by-character comparison
 *   Expected: Suggestion returned if confidence >= 55, otherwise status = 'unmatched'
 * 
 * Test Case 6: Exact match present
 *   Input: Invoice contains "HTL-ABC123-A7" which exists exactly in DB
 *   Expected: This reference is NOT included in fuzzy suggestions (exact matches are skipped)
 * 
 * Test Case 7: Denominator uses longest length after prefix removal
 *   Input: Unknown "HTL-ABC" (length 3 after prefix), Candidate "HTL-ABCDEF" (length 6 after prefix)
 *   Expected: Denominator = 6 (longest), matching letters counted up to min(3,6) = 3
 *   Expected: confidence = (matching_letters / 6) * 100
 * 
 * Test Case 8: Re-running AJAX on same invoice
 *   Input: Call get_fuzzy_suggestions twice with same invoice_id
 *   Expected: First call creates MATCHING_HISTORY_TAB entries
 *   Expected: Second call does NOT create duplicate entries (checked by invoice_id, student_id, matched_by)
 * 
 * Test Case 9: Tie-breaking rules
 *   Input: Multiple candidates with same confidence score
 *   Expected: Select candidate with smallest length difference
 *   Expected: If still tied, select lexicographically smallest reference_id
 * 
 * NAME-BASED MATCHING TEST CASES (Last Resort)
 * 
 * Test Case 10: No reference IDs; invoice contains student full name
 *   Input: Invoice text "Payment for John Smith" (no reference IDs)
 *   Expected: extractReferenceIds() returns []
 *   Expected: Name-based matching finds student with forename="John", name="Smith"
 *   Expected: Suggestion returned with confidence_score=50, method='name_suggest', matched_entity_type='student'
 *   Expected: MATCHING_HISTORY_TAB entry created with confidence=50, matched_by='name_suggest'
 * 
 * Test Case 11: No reference IDs; invoice contains parent/guardian full name
 *   Input: Invoice text "Payment from Maria Müller" (no reference IDs)
 *   Expected: extractReferenceIds() returns []
 *   Expected: Name-based matching finds guardian with first_name="Maria", last_name="Müller"
 *   Expected: Guardian resolves to student(s) with matching last name
 *   Expected: Suggestion returned with confidence_score=50, method='name_suggest', matched_entity_type='guardian'
 *   Expected: MATCHING_HISTORY_TAB entry created for resolved student with confidence=50
 * 
 * Test Case 12: No reference IDs and no names found
 *   Input: Invoice text "Payment for services" (no reference IDs, no recognizable names)
 *   Expected: extractReferenceIds() returns []
 *   Expected: Name-based matching finds no matches
 *   Expected: Returns status='no_references', suggestions=[], no MATCHING_HISTORY_TAB entry
 * 
 * Test Case 13: Re-running name-based suggestion
 *   Input: Call get_fuzzy_suggestions twice with same invoice_id that has no references but contains a name
 *   Expected: First call creates MATCHING_HISTORY_TAB entry with matched_by='name_suggest'
 *   Expected: Second call does NOT create duplicate entry (checked by invoice_id, student_id, matched_by='name_suggest')
 * 
 * Test Case 14: Name matching prioritization
 *   Input: Invoice contains "Max Mustermann" and both student "Max Mustermann" and guardian "Max Mustermann" exist
 *   Expected: Student match is preferred (both first and last name match)
 *   Expected: If multiple students match, longest full name wins, then smallest student_id
 * 
 * Test Case 15: Fuzzy name matching (name_fuzzy)
 *   Input: Invoice text "Payment from Smith" (no reference IDs, only last name)
 *   Expected: extractReferenceIds() returns []
 *   Expected: Exact name matching (name_suggest) fails (requires both first and last name)
 *   Expected: Fuzzy name matching finds student/guardian with last name "Smith"
 *   Expected: Suggestion returned with confidence_score=20-45 (based on partial match), method='name_fuzzy'
 *   Expected: MATCHING_HISTORY_TAB entry created with matched_by='name_fuzzy'
 * 
 * Test Case 16: Fuzzy name matching with partial first name
 *   Input: Invoice text "Payment from Joh Smith" (no reference IDs, partial first name)
 *   Expected: extractReferenceIds() returns []
 *   Expected: Exact name matching fails
 *   Expected: Fuzzy name matching finds student with forename starting with "Joh" and name="Smith"
 *   Expected: Confidence score calculated based on partial matches (last name + partial first name)
 *   Expected: Suggestion returned with method='name_fuzzy'
 */
