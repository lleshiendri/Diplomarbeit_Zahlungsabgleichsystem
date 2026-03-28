<?php
/**
 * Matching + Notifications (schema-safe)
 * Late-fee urgent notifications: after the 10th only if invoice split >= school year total/10 (see maybeCreateLateFeeUrgent).
 *
 * NOTE: Matching decisions (student_id selection, history) are now delegated
 * to the pipeline in matching_engine.php. This file focuses on schema-safe
 * helpers and notifications only.
 *
 * DB schema: table/column names must match buchhaltung_16_1_2026 (see includes/schema_buchhaltung_16_1_2026.php).
 */

define('ENV_DEBUG', false);
define('CONFIRM_THRESHOLD', 70.0);
if (!defined('LATE_FEE_AMOUNT_LEK')) {
    define('LATE_FEE_AMOUNT_LEK', 500.0);
}

function dbg_log($msg) {
    if (ENV_DEBUG) error_log("[MATCH/NOTIF] " . $msg);
}

// Ensure the pipeline engine is available for all automatic matching.
if (!function_exists('runMatchingPipeline')) {
    require_once __DIR__ . '/matching_engine.php';
}

function toDateString($dt) {
    $ts = strtotime((string)$dt);
    if ($ts === false) return date('Y-m-d');
    return date('Y-m-d', $ts);
}

/* ===============================================================
   LEGACY MATCHING ALGORITHM (NO LONGER USED FOR AUTOMATIC MATCHING)
   =============================================================== */
function matchInvoiceToStudent($conn, $reference_number, $beneficiary, $reference) {
    // Kept only for backward compatibility / potential manual tools.
    // All automatic matching is delegated to runMatchingPipeline() in matching_engine.php.
    $result = ['student_id'=>null,'confidence'=>0.0,'matched_by'=>'none'];
    return $result;
}

/* ===============================================================
   SCHEMA DETECTION (CACHE)
   =============================================================== */
function getTableColumnsCached($conn, $tableName) {
    static $cache = [];
    if (isset($cache[$tableName])) return $cache[$tableName];

    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM `$tableName`");
    if ($res) {
        while ($row = $res->fetch_assoc()) $cols[] = $row['Field'];
        $res->free();
    }
    $cache[$tableName] = $cols;
    return $cols;
}

/* ===============================================================
   INVOICE META  ✅ now includes INVOICE_TAB.reference
   =============================================================== */
function getInvoiceMeta($conn, $invoice_id) {
    $meta = [
        'processing_date'  => null,
        'reference'        => null,
        'reference_number' => null
    ];

    $stmt = $conn->prepare("
        SELECT processing_date, reference, reference_number
        FROM INVOICE_TAB
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        dbg_log("getInvoiceMeta prepare failed: " . $conn->error);
        return $meta;
    }

    $stmt->bind_param("i", $invoice_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = ($res ? $res->fetch_assoc() : null)) {
        $meta['processing_date']  = $row['processing_date'] ?? null;
        $meta['reference']        = $row['reference'] ?? null;
        $meta['reference_number'] = $row['reference_number'] ?? null;
    }
    $stmt->close();
    return $meta;
}

/* ===============================================================
   MATCHING HISTORY
   =============================================================== */
function logMatchingAttempt($conn, $invoice_id, $student_id, $confidence, $matched_by, $is_confirmed) {
    $stmt = $conn->prepare("
        INSERT INTO MATCHING_HISTORY_TAB
            (invoice_id, student_id, confidence_score, matched_by, is_confirmed, created_at)
        VALUES
            (?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        dbg_log("logMatchingAttempt prepare failed: " . $conn->error);
        return false;
    }

    $student_id_param = ($student_id === null) ? null : (int)$student_id;
    $is_confirmed_int = $is_confirmed ? 1 : 0;

    $stmt->bind_param("iidsi", $invoice_id, $student_id_param, $confidence, $matched_by, $is_confirmed_int);
    $ok = $stmt->execute();
    if (!$ok) dbg_log("logMatchingAttempt execute failed: " . $stmt->error);
    $stmt->close();
    return $ok;
}

function isSeptemberDate($dt) {
    $ts = strtotime((string)$dt);
    if ($ts === false) return false;
    return ((int)date('n', $ts) === 9);
}

function hasPaidFullYearAmount($conn, $student_id, $dt) {
    $ts = strtotime((string)$dt);
    if ($ts === false) return false;

    $year = (int)date('Y', $ts);
    if ((int)date('n', $ts) < 9) $year -= 1;

    $syStart = "$year-09-01";
    $syEnd   = ($year + 1) . "-08-31";

    // Fetch annual limit
    $stmt = $conn->prepare("
        SELECT total_amount
        FROM SCHOOLYEAR_TAB
        WHERE schoolyear LIKE CONCAT(?, '%')
        ORDER BY schoolyear DESC
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param("s", $year);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !($row = $res->fetch_assoc())) { $stmt->close(); return false; }
    $limit = (float)$row['total_amount'];
    $stmt->close();

    // Sum paid in schoolyear
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount_total),0) AS paid
        FROM INVOICE_TAB
        WHERE student_id = ?
          AND processing_date IS NOT NULL
          AND DATE(processing_date) BETWEEN ? AND ?
    ");
    if (!$stmt) return false;
    $sid = (int)$student_id;
    $stmt->bind_param("iss", $sid, $syStart, $syEnd);
    $stmt->execute();
    $res = $stmt->get_result();
    $paid = ($res && ($r = $res->fetch_assoc())) ? (float)$r['paid'] : 0.0;
    $stmt->close();

    return ($paid >= $limit);
}

/* ===============================================================
   NOTIFICATIONS (SCHEMA-SAFE INSERT)
   =============================================================== */
function createNotificationOnce($conn, $urgency, $student_id, $invoice_reference, $time_from, $description) {
    if (isSeptemberDate($time_from)) return true;
    if (hasPaidFullYearAmount($conn, $student_id, $time_from)) return true;
    
    $cols = getTableColumnsCached($conn, "NOTIFICATION_TAB");

    // invoice_reference must never be empty
    $invoice_reference = trim((string)$invoice_reference);
    if ($invoice_reference === '') $invoice_reference = 'N/A';

    $hasInvoiceRef = in_array('invoice_reference', $cols, true);
    $hasUrgency    = in_array('urgency', $cols, true);

    // Dedupe: invoice_reference + urgency
    if ($hasInvoiceRef && $hasUrgency) {
        $check = $conn->prepare("
            SELECT 1
            FROM NOTIFICATION_TAB
            WHERE invoice_reference = ?
              AND urgency = ?
            LIMIT 1
        ");
        if ($check) {
            $check->bind_param("ss", $invoice_reference, $urgency);
            if ($check->execute()) {
                $res = $check->get_result();
                if ($res && $res->fetch_assoc()) {
                    $check->close();
                    return true;
                }
            } else {
                dbg_log("Notif dedupe execute failed: " . $check->error);
            }
            $check->close();
        }
    }

    $insertCols = [];
    $placeholders = [];
    $types = "";
    $params = [];

    if (in_array('student_id', $cols, true)) {
        $insertCols[] = 'student_id';
        $placeholders[] = '?';
        $types .= 'i';
        $params[] = ($student_id === null) ? null : (int)$student_id;
    }

    if ($hasInvoiceRef) {
        $insertCols[] = 'invoice_reference';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $invoice_reference;
    }

    if (in_array('description', $cols, true)) {
        $insertCols[] = 'description';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = (string)$description;
    }

    if (in_array('time_from', $cols, true)) {
        $insertCols[] = 'time_from';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = toDateString($time_from);
    }

    if (in_array('is_read', $cols, true)) {
        $insertCols[] = 'is_read';
        $placeholders[] = '0';
    }

    if ($hasUrgency) {
        $insertCols[] = 'urgency';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = (string)$urgency;
    }

    if (in_array('mail_status', $cols, true)) {
        $insertCols[] = 'mail_status';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = 'pending';
    }

    if (in_array('created_at', $cols, true)) {
        $insertCols[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    $sql = "INSERT INTO NOTIFICATION_TAB (" . implode(", ", $insertCols) . ")
            VALUES (" . implode(", ", $placeholders) . ")";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        dbg_log("Notif insert prepare failed: " . $conn->error);
        return false;
    }

    if ($types !== "") {
        $bindParams = [];
        $bindParams[] = $types;
        for ($i=0; $i<count($params); $i++) $bindParams[] = &$params[$i];
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }

    $ok = $stmt->execute();
    if (!$ok) dbg_log("Notif insert execute failed: " . $stmt->error);
    $stmt->close();
    return $ok;
}

/**
 * Whether a notification row already exists for this reference + urgency (dedupe).
 */
function notificationUrgencyExists(mysqli $conn, string $urgency, string $invoice_reference): bool {
    $invoice_reference = trim($invoice_reference);
    if ($invoice_reference === '') {
        return false;
    }
    $cols = getTableColumnsCached($conn, 'NOTIFICATION_TAB');
    if (!in_array('invoice_reference', $cols, true) || !in_array('urgency', $cols, true)) {
        return false;
    }
    $stmt = $conn->prepare(
        'SELECT 1 FROM NOTIFICATION_TAB WHERE invoice_reference = ? AND urgency = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $invoice_reference, $urgency);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc();
    $stmt->close();
    return (bool)$exists;
}

/**
 * Dedupe for cron-style notifications (same student + urgency + description).
 */
function notificationExistsExact(mysqli $conn, int $student_id, string $urgency, string $description): bool {
    $cols = getTableColumnsCached($conn, 'NOTIFICATION_TAB');
    foreach (['student_id', 'urgency', 'description'] as $c) {
        if (!in_array($c, $cols, true)) {
            return false;
        }
    }
    $stmt = $conn->prepare(
        'SELECT 1 FROM NOTIFICATION_TAB WHERE student_id = ? AND urgency = ? AND description = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iss', $student_id, $urgency, $description);
    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }
    $res = $stmt->get_result();
    $exists = $res && $res->fetch_assoc();
    $stmt->close();
    return (bool)$exists;
}

/**
 * Reference id for NOTIFICATION_TAB.invoice_reference when processing a student row.
 */
function getStudentReferenceOrFallback(mysqli $conn, int $student_id): string {
    if ($student_id <= 0) {
        return 'N/A';
    }
    $stmt = $conn->prepare('SELECT reference_id FROM STUDENT_TAB WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return 'STU-' . $student_id;
    }
    $stmt->bind_param('i', $student_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return 'STU-' . $student_id;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $ref = isset($row['reference_id']) ? trim((string)$row['reference_id']) : '';
    return $ref !== '' ? $ref : ('STU-' . $student_id);
}

/**
 * Apply a late fee: always add to additional_payments_status; add to left_to_pay only when
 * the invoice share is a school-sized payment (split >= school year total / 10).
 * Pass invoice_id = 0 when there is no invoice (e.g. monthly no-payment job): only additional_payments_status is updated.
 *
 * @return bool false if UPDATE failed
 */
function applyLateFeeToStudentBalances(mysqli $conn, int $student_id, int $invoice_id, float $late_fee_amount): bool {
    if ($student_id <= 0 || $late_fee_amount <= 0) {
        return false;
    }
    $qualifies_left = false;
    if ($invoice_id > 0) {
        $qualifies_left = studentInvoiceShareQualifiesForSchoolLateFee($conn, $student_id, $invoice_id);
    }
    $flag = $qualifies_left ? 1 : 0;
    $fee = $late_fee_amount;
    $stmt = $conn->prepare('
        UPDATE STUDENT_TAB
           SET left_to_pay = CASE WHEN ? = 1 THEN COALESCE(left_to_pay, 0) + ? ELSE left_to_pay END,
               additional_payments_status = COALESCE(additional_payments_status, 0) + ?
         WHERE id = ?
    ');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iddi', $flag, $fee, $fee, $student_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/* ===============================================================
   LATE FEE NOTIFICATION (Rule: payment after 10th of month = late)
   School-sized splits also increase left_to_pay; all late fees hit additional_payments_status.
   =============================================================== */

/**
 * School-year base fee threshold for late-fee eligibility: total_amount / 10.
 *
 * @return float|null threshold, or null if student has no school year or total_amount missing
 */
function computeSchoolBaseLateFeeThreshold(mysqli $conn, int $student_id): ?float {
    if ($student_id <= 0) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT sy.total_amount
          FROM SCHOOLYEAR_TAB sy
          INNER JOIN STUDENT_TAB st ON st.schoolyear_id = sy.id
         WHERE st.id = ?
         LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $student_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row || !isset($row['total_amount'])) {
        return null;
    }
    $total = (float)$row['total_amount'];
    if ($total <= 0) {
        return null;
    }
    return $total / 10.0;
}

/**
 * Per-student share for an invoice (aligned with INVOICE_SPLIT_CACHE / confirmed MATCHING_HISTORY split).
 *
 * @return float|null split amount, or null if invoice cannot be resolved
 */
function invoiceSplitAmountForLateFee(mysqli $conn, int $invoice_id): ?float {
    if ($invoice_id <= 0) {
        return null;
    }
    $esc = (int)$invoice_id;
    $r = @$conn->query("SELECT split_amount FROM INVOICE_SPLIT_CACHE WHERE invoice_id = {$esc} LIMIT 1");
    if ($r && ($row = $r->fetch_assoc()) && isset($row['split_amount']) && $row['split_amount'] !== null) {
        return round((float)$row['split_amount'], 2);
    }
    $stmt = $conn->prepare("
        SELECT i.amount_total AS atot,
               (SELECT COUNT(DISTINCT mh.student_id)
                  FROM MATCHING_HISTORY_TAB mh
                 WHERE mh.invoice_id = i.id
                   AND mh.student_id IS NOT NULL
                   AND mh.is_confirmed = 1) AS nconf,
               i.student_id AS inv_sid
          FROM INVOICE_TAB i
         WHERE i.id = ?
         LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $invoice_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return null;
    }
    $atot = (float)($row['atot'] ?? 0);
    $n = (int)($row['nconf'] ?? 0);
    $invSid = $row['inv_sid'] ?? null;
    if ($n > 0) {
        return round($atot / $n, 2);
    }
    if ($invSid !== null && (int)$invSid > 0) {
        return round($atot, 2);
    }
    return null;
}

/**
 * Late fee (after 10th) applies only when the invoice share is a school base payment:
 * split_amount >= SCHOOLYEAR_TAB.total_amount / 10 for the student's school year.
 */
function studentInvoiceShareQualifiesForSchoolLateFee(mysqli $conn, int $student_id, int $invoice_id): bool {
    $threshold = computeSchoolBaseLateFeeThreshold($conn, $student_id);
    if ($threshold === null) {
        return false;
    }
    $split = invoiceSplitAmountForLateFee($conn, $invoice_id);
    if ($split === null) {
        return false;
    }
    return $split >= $threshold;
}

/**
 * If the payment date is after the 10th of its month, record late fee on the student and create an urgent notification.
 * additional_payments_status always increases; left_to_pay increases only when split >= school year total / 10.
 * Deduplicated by invoice_reference + urgency (same as createNotificationOnce).
 *
 * @param mysqli $conn
 * @param int|null $student_id
 * @param string $invoice_reference
 * @param string $processing_date e.g. Y-m-d or Y-m-d H:i:s
 * @param int|null $invoice_id required for split vs school-fee guard; if missing, no late-fee handling
 * @return bool true if on time, already recorded, or blocked by rules; false on hard failure
 */
function maybeCreateLateFeeUrgent($conn, $student_id, $invoice_reference, $processing_date, $invoice_id = null) {
    $invoice_reference = trim((string)$invoice_reference);
    if ($invoice_reference === '') {
        return false;
    }

    $invId = $invoice_id !== null ? (int)$invoice_id : 0;
    if ($invId <= 0) {
        return false;
    }

    $sid = (int)$student_id;
    if ($sid <= 0) {
        return false;
    }

    $ts = strtotime((string)$processing_date);
    if ($ts === false) {
        return false;
    }

    $payDate = date('Y-m-d', $ts);
    $year = date('Y', $ts);
    $month = date('m', $ts);
    $deadline = $year . '-' . $month . '-10';

    if ($payDate <= $deadline) {
        return true;
    }

    if (isSeptemberDate($processing_date)) {
        return true;
    }
    if (hasPaidFullYearAmount($conn, $sid, $processing_date)) {
        return true;
    }
    if (notificationUrgencyExists($conn, 'urgent', $invoice_reference)) {
        return true;
    }

    $daysLate = (int)floor((strtotime($payDate) - strtotime($deadline)) / 86400);
    $desc = "Late fee: payment received on $payDate (deadline was 10th; {$daysLate} day(s) late). Ref: $invoice_reference";
    $time_from = date('Y-m-d', $ts);

    $conn->begin_transaction();
    if (!applyLateFeeToStudentBalances($conn, $sid, $invId, (float)LATE_FEE_AMOUNT_LEK)) {
        $conn->rollback();
        return false;
    }
    $notifOk = createNotificationOnce($conn, 'urgent', $student_id, $invoice_reference, $time_from, $desc);
    if (!$notifOk) {
        $conn->rollback();
        return false;
    }
    $conn->commit();
    return $notifOk;
}

/* ===============================================================
   MAIN PIPELINE
   =============================================================== */
function processInvoiceMatching($conn, $invoice_id, $reference_number, $beneficiary, $reference, $forceInfo = false) {

    $meta = getInvoiceMeta($conn, $invoice_id);
    $processing_date = $meta['processing_date'] ?: date('Y-m-d H:i:s');
    $time_from_date  = toDateString($processing_date);

    // ✅ invoice_reference MUST be the constant reference id (INVOICE_TAB.reference)
    $invoice_reference = trim((string)$reference_number);
    if ($invoice_reference === '') $invoice_reference = trim((string)($meta['reference_number'] ?? ''));
    if ($invoice_reference === '') {
        // last-resort fallback to keep DB inserts stable
        $invoice_reference = "INV-" . (int)$invoice_id;
    }

    // Delegate matching to the pipeline engine. This will set INVOICE_TAB.student_id
    // and insert MATCHING_HISTORY_TAB rows if the engine finds a match.
    if (function_exists('runMatchingPipeline')) {
        runMatchingPipeline((int)$invoice_id);
    }

    // Derive final decision from DB state after the pipeline ran.
    $student_id = null;
    $confidence = 0.0;
    $matched_by = 'none';
    $is_confirmed = false;

    // 1) Read student_id from invoice
    $stmt = $conn->prepare("SELECT student_id FROM INVOICE_TAB WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $invoice_id);
        $stmt->execute();
        $stmt->bind_result($sid);
        if ($stmt->fetch() && $sid !== null) {
            $student_id = (int)$sid;
        }
        $stmt->close();
    }

    // 2) Read latest history row, if table exists
    $history_ok = null;
    $colsHistory = getTableColumnsCached($conn, "MATCHING_HISTORY_TAB");
    if (!empty($colsHistory)) {
        $hasIsConfirmed = in_array('is_confirmed', $colsHistory, true);

        $selectCols = "student_id, confidence_score, matched_by";
        if ($hasIsConfirmed) {
            $selectCols .= ", is_confirmed";
        }

        $sqlHist = "
            SELECT {$selectCols}
            FROM MATCHING_HISTORY_TAB
            WHERE invoice_id = ?
            ORDER BY id DESC
            LIMIT 1
        ";
        $stmtH = $conn->prepare($sqlHist);
        if ($stmtH) {
            $stmtH->bind_param("i", $invoice_id);
            if ($stmtH->execute()) {
                $resH = $stmtH->get_result();
                if ($rowH = ($resH ? $resH->fetch_assoc() : null)) {
                    if ($rowH['student_id'] !== null) {
                        $student_id = (int)$rowH['student_id'];
                    }
                    $confidence = (float)($rowH['confidence_score'] ?? 0.0);
                    $matched_by = (string)($rowH['matched_by'] ?? 'none');

                    if ($hasIsConfirmed) {
                        $is_confirmed = ((int)($rowH['is_confirmed'] ?? 0)) === 1;
                    } else {
                        $is_confirmed = ($student_id !== null && $confidence >= CONFIRM_THRESHOLD);
                    }
                    $history_ok = true;
                }
            }
            $stmtH->close();
        }
    } else {
        // No history table: fall back to invoice student_id only.
        $is_confirmed = ($student_id !== null);
    }

    // Respect forceInfo behavior as before: allow callers to force confirmation.
    if ($forceInfo && $invoice_reference !== '' && $student_id !== null) {
        $is_confirmed = true;
    }

    $notif_info_ok = null;
    $notif_warn_ok = null;

    if ($is_confirmed) {
        $desc = "Confirmed: $invoice_reference matched to Student #$student_id (by $matched_by, " . round($confidence, 1) . "%)";
        $notif_info_ok = createNotificationOnce($conn, "info", $student_id, $invoice_reference, $time_from_date, $desc);
        // Late fee: if payment date is after 10th of month, create urgent notification (school-sized payments only)
        maybeCreateLateFeeUrgent($conn, $student_id, $invoice_reference, $processing_date, (int)$invoice_id);
    } else {

        $who = ($student_id !== null) ? "suggested Student #$student_id" : "no student suggested";
        $desc = "Unconfirmed: $invoice_reference ($who) (by $matched_by, " . round($confidence, 1) . "%)";
        $notif_warn_ok = createNotificationOnce($conn, "warning", $student_id, $invoice_reference, $time_from_date, $desc);
    }

    $result = [
        'success' => true,
        'invoice_id' => (int)$invoice_id,
        'invoice_reference' => $invoice_reference,
        'student_id' => $student_id,
        'confidence' => $confidence,
        'matched_by' => $matched_by,
        'confirmed' => $is_confirmed,
        'notif_info_ok' => $notif_info_ok,
        'notif_warn_ok' => $notif_warn_ok
    ];

    // #region agent log
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'pre-fix',
        'hypothesisId' => 'MF1',
        'location' => 'matching_functions.php:369',
        'message' => 'processInvoiceMatching result',
        'data' => $result,
        'timestamp' => round(microtime(true) * 1000)
    ]) . PHP_EOL;
    @file_put_contents(__DIR__ . '/.cursor/debug.log', $logEntry, FILE_APPEND);
    // #endregion

    return $result;
}
?>