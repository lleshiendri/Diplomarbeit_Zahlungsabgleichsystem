<?php
/**
 * Matching + Notifications (schema-safe)
 */

define('ENV_DEBUG', false);            // set true temporarily if you want debug output
define('CONFIRM_THRESHOLD', 70.0);

function dbg_log($msg) {
    if (ENV_DEBUG) {
        error_log("[MATCH/NOTIF] " . $msg);
    }
}

/* ===============================================================
   MATCHING ALGORITHM
   =============================================================== */
function matchInvoiceToStudent($conn, $reference_number, $beneficiary, $reference) {
    $result = [
        'student_id' => null,
        'confidence' => 0.0,
        'matched_by' => 'none'
    ];

    if (empty($reference_number) && empty($beneficiary) && empty($reference)) {
        return $result;
    }

    // Strategy 1: reference_number exact match on extern_key or second_ID
    if (!empty($reference_number)) {
        $stmt = $conn->prepare("
            SELECT id
            FROM STUDENT_TAB
            WHERE extern_key = ? OR second_ID = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("ss", $reference_number, $reference_number);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $result['student_id'] = (int)$row['id'];
                $result['confidence'] = 95.0;
                $result['matched_by'] = 'reference';
                $stmt->close();
                return $result;
            }
            $stmt->close();
        }
    }

    // Strategy 2: beneficiary last name match vs long_name
    if (!empty($beneficiary)) {
        $parts = preg_split('/\s+/', trim($beneficiary));
        $last_name = $parts ? end($parts) : '';

        if (!empty($last_name)) {
            $stmt = $conn->prepare("
                SELECT id, long_name
                FROM STUDENT_TAB
                WHERE long_name LIKE ?
                LIMIT 1
            ");
            if ($stmt) {
                $pattern = '%' . $last_name . '%';
                $stmt->bind_param("s", $pattern);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $long_name = (string)$row['long_name'];
                    similar_text(mb_strtolower($long_name), mb_strtolower($beneficiary), $percent);

                    $result['student_id'] = (int)$row['id'];
                    $result['confidence'] = min(90.0, max(60.0, (float)$percent));
                    $result['matched_by'] = 'name';
                    $stmt->close();
                    return $result;
                }
                $stmt->close();
            }
        }
    }

    // Strategy 3: reference field regex extraction
    if (!empty($reference)) {
        if (preg_match('/student[:\s]+([^\s]+)/i', $reference, $matches)) {
            $search_term = trim($matches[1]);

            $stmt = $conn->prepare("
                SELECT id
                FROM STUDENT_TAB
                WHERE long_name LIKE ? OR name LIKE ? OR forename LIKE ?
                LIMIT 1
            ");
            if ($stmt) {
                $pattern = '%' . $search_term . '%';
                $stmt->bind_param("sss", $pattern, $pattern, $pattern);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($row = $res->fetch_assoc()) {
                    $result['student_id'] = (int)$row['id'];
                    $result['confidence'] = 75.0;
                    $result['matched_by'] = 'regex';
                    $stmt->close();
                    return $result;
                }
                $stmt->close();
            }
        }
    }

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
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
        $res->free();
    }

    $cache[$tableName] = $cols;
    dbg_log("Columns for $tableName: " . implode(", ", $cols));
    return $cols;
}

/* ===============================================================
   INVOICE META
   =============================================================== */
function getInvoiceMeta($conn, $invoice_id) {
    $meta = [
        'processing_date'  => null,
        'reference_number' => null
    ];

    $stmt = $conn->prepare("
        SELECT processing_date, reference_number
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

/* ===============================================================
   NOTIFICATIONS (SCHEMA-SAFE INSERT)
   =============================================================== */
function createNotificationOnce($conn, $urgency, $student_id, $invoice_reference, $time_from, $description) {

    $cols = getTableColumnsCached($conn, "NOTIFICATION_TAB");

    // Normalize invoice_reference
$invoice_reference = trim((string)$invoice_reference);
if ($invoice_reference === '') {
    $invoice_reference = 'N/A';
}
    // Dedupe logic:
    // - Prefer (invoice_reference, urgency) if both columns exist and invoice_reference non-empty
    $hasInvoiceRef = in_array('invoice_reference', $cols, true);
    $hasUrgency    = in_array('urgency', $cols, true);

    if ($hasInvoiceRef && $hasUrgency && $invoice_reference !== '') {
        $check = $conn->prepare("
            SELECT 1
            FROM NOTIFICATION_TAB
            WHERE invoice_reference = ?
              AND urgency = ?
            LIMIT 1
        ");
        if (!$check) {
            dbg_log("Notif dedupe prepare failed: " . $conn->error);
        } else {
            $check->bind_param("ss", $invoice_reference, $urgency);
            if (!$check->execute()) {
                dbg_log("Notif dedupe execute failed: " . $check->error);
            } else {
                $res = $check->get_result();
                if ($res && $res->fetch_assoc()) {
                    $check->close();
                    return true; // already exists
                }
            }
            $check->close();
        }
    }

    // Build dynamic insert
    $insertCols = [];
    $placeholders = [];
    $types = "";
    $params = [];

    // student_id (nullable)
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
        $params[] = $description;
    }

    if (in_array('time_from', $cols, true)) {
        $insertCols[] = 'time_from';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $time_from;
    }

    if (in_array('is_read', $cols, true)) {
        $insertCols[] = 'is_read';
        $placeholders[] = '0';
    }

    if ($hasUrgency) {
        $insertCols[] = 'urgency';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = $urgency;
    }

    // mail_status optional
    if (in_array('mail_status', $cols, true)) {
        $insertCols[] = 'mail_status';
        $placeholders[] = "'pending'";
    }

    // created_at optional
    if (in_array('created_at', $cols, true)) {
        $insertCols[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    if (count($insertCols) === 0) {
        dbg_log("NOTIFICATION_TAB has no usable columns for insert.");
        return false;
    }

    $sql = "INSERT INTO NOTIFICATION_TAB (" . implode(", ", $insertCols) . ") VALUES (" . implode(", ", $placeholders) . ")";
    dbg_log("Notif insert SQL: " . $sql);

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        dbg_log("Notif insert prepare failed: " . $conn->error);
        return false;
    }

    // Bind params if any placeholders are '?'
    if ($types !== "") {
        // mysqli requires references
        $bindParams = [];
        $bindParams[] = $types;
        for ($i=0; $i<count($params); $i++) {
            $bindParams[] = &$params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
    }

    $ok = $stmt->execute();
    if (!$ok) {
        dbg_log("Notif insert execute failed: " . $stmt->error);
    }
    $stmt->close();

    return $ok;
}

/* ===============================================================
   LATE FEE (urgent) - uses invoice processing_date month
   =============================================================== */
function maybeCreateLateFeeUrgent($conn, $student_id, $invoice_reference, $processing_date) {
    if ($student_id === null) return false;

    $ts = strtotime((string)$processing_date);
    if ($ts === false) {
        dbg_log("LateFee: invalid processing_date=" . var_export($processing_date, true));
        return false;
    }

    $y = (int)date('Y', $ts);
    $m = (int)date('m', $ts);

    $deadline = sprintf('%04d-%02d-10', $y, $m);
    $month_str = sprintf('%04d-%02d', $y, $m);

    // First payment in that month
    $stmt = $conn->prepare("
        SELECT MIN(DATE(processing_date)) AS first_payment_date
        FROM INVOICE_TAB
        WHERE student_id = ?
          AND processing_date IS NOT NULL
          AND YEAR(processing_date) = ?
          AND MONTH(processing_date) = ?
    ");
    if (!$stmt) {
        dbg_log("LateFee prepare failed: " . $conn->error);
        return false;
    }

    $sid = (int)$student_id;
    $stmt->bind_param("iii", $sid, $y, $m);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $first_payment = $row['first_payment_date'] ?? null;

    dbg_log("LateFee check: sid=$sid month=$month_str first_payment=" . var_export($first_payment, true) . " deadline=$deadline");

    if (!$first_payment) return false;

    if ($first_payment <= $deadline) {
        dbg_log("LateFee: first_payment not late => no notification");
        return false;
    }

    // Rule A: create only once per student+month
    $check = $conn->prepare("
        SELECT 1
        FROM NOTIFICATION_TAB
        WHERE student_id = ?
          AND urgency = 'urgent'
          AND description LIKE CONCAT('Late fee ', ?, '%')
        LIMIT 1
    ");
    if ($check) {
        $likeMonth = $month_str;
        $check->bind_param("is", $sid, $likeMonth);
        $check->execute();
        $r = $check->get_result();
        if ($r && $r->fetch_assoc()) {
            $check->close();
            dbg_log("LateFee: notification already exists => skip");
            return true;
        }
        $check->close();
    }

    $desc = "Late fee $month_str: first payment after 10th (+500 LEK)";

// invoice_reference darf NIE leer sein
$invoice_reference = trim((string)$invoice_reference);
if ($invoice_reference === '') {
    $invoice_reference = "LATEFEE-$month_str-$sid";
}

// time_from bleibt DATE (YYYY-MM-DD)
$time_from = $first_payment;

$ok = createNotificationOnce($conn, "urgent", $sid, $invoice_reference, $time_from, $desc);
dbg_log("LateFee: createNotificationOnce returned " . var_export($ok, true));
return $ok;
}

/* ===============================================================
   MAIN PIPELINE
   =============================================================== */
function processInvoiceMatching($conn, $invoice_id, $reference_number, $beneficiary, $reference, $forceInfo = false) {

    $meta = getInvoiceMeta($conn, $invoice_id);

    $processing_date = $meta['processing_date'] ?: date('Y-m-d H:i:s');

    // Use reference_number as invoice_reference if available; else fallback
    $invoice_reference = $meta['reference_number'];
    if ($invoice_reference === null || trim((string)$invoice_reference) === '') {
        $invoice_reference = "INV-" . (int)$invoice_id;
    }

    // Run matching
    $match = matchInvoiceToStudent($conn, $reference_number, $beneficiary, $reference);

    $student_id = $match['student_id'];
    $confidence = (float)$match['confidence'];
    $matched_by = (string)$match['matched_by'];

    // ✅ Manual-add rule: always treat as confirmed for INFO when ref_number exists + student found
    if ($forceInfo && !empty($reference_number) && $student_id !== null) {
        $is_confirmed = true;
    } else {
        $is_confirmed = ($student_id !== null && $confidence >= CONFIRM_THRESHOLD);
    }

    // Log attempt
    logMatchingAttempt($conn, $invoice_id, $student_id, $confidence, $matched_by, $is_confirmed);

    $notif_info_ok = null;
    $notif_warn_ok = null;
    $notif_urgent_ok = null;

    if ($is_confirmed) {

        // Update invoice student_id
        $stmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ? WHERE id = ?");
        if ($stmt) {
            $sid = (int)$student_id;
            $stmt->bind_param("ii", $sid, $invoice_id);
            $stmt->execute();
            $stmt->close();
        } else {
            dbg_log("Update invoice prepare failed: " . $conn->error);
        }

        // INFO notification
        $desc = "Confirmed: $invoice_reference matched to Student #$student_id (by $matched_by, " . round($confidence, 1) . "%)";
        $notif_info_ok = createNotificationOnce($conn, "info", $student_id, $invoice_reference, $processing_date, $desc);

        // URGENT (late fee)
        $notif_urgent_ok = maybeCreateLateFeeUrgent($conn, $student_id, $invoice_reference, $processing_date);

    } else {

        // WARNING notification
        $who = ($student_id !== null) ? "suggested Student #$student_id" : "no student suggested";
        $desc = "Unconfirmed: $invoice_reference ($who) (by $matched_by, " . round($confidence, 1) . "%)";
        $notif_warn_ok = createNotificationOnce($conn, "warning", $student_id, $invoice_reference, $processing_date, $desc);
    }

    return [
        'success' => true,
        'invoice_id' => (int)$invoice_id,
        'invoice_reference' => $invoice_reference,
        'student_id' => $student_id,
        'confidence' => $confidence,
        'matched_by' => $matched_by,
        'confirmed' => $is_confirmed,
        'notif_info_ok' => $notif_info_ok,
        'notif_warn_ok' => $notif_warn_ok,
        'notif_urgent_ok' => $notif_urgent_ok
    ];
}
?>