<?php
/**
 * Matching + Notifications (schema-safe)
 * Late Fee Policy handled by MySQL EVENT (NOT in PHP).
 */

define('ENV_DEBUG', false);
define('CONFIRM_THRESHOLD', 70.0);

function dbg_log($msg) {
    if (ENV_DEBUG) error_log("[MATCH/NOTIF] " . $msg);
}

function toDateString($dt) {
    $ts = strtotime((string)$dt);
    if ($ts === false) return date('Y-m-d');
    return date('Y-m-d', $ts);
}

/* ===============================================================
   MATCHING ALGORITHM
   =============================================================== */
function matchInvoiceToStudent($conn, $reference_number, $beneficiary, $reference) {
    $result = ['student_id'=>null,'confidence'=>0.0,'matched_by'=>'none'];

    if (empty($reference_number) && empty($beneficiary) && empty($reference)) return $result;

    // ✅ Strategy 1: use the REAL constant reference id (INVOICE_TAB.reference) if present,
    // otherwise fallback to reference_number
    $ref_id = trim((string)$reference);
    if ($ref_id === '') $ref_id = trim((string)$reference_number);

    if ($ref_id !== '') {
        $stmt = $conn->prepare("
            SELECT id
            FROM STUDENT_TAB
            WHERE reference_id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $ref_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = ($res ? $res->fetch_assoc() : null)) {
                $result['student_id'] = (int)$row['id'];
                $result['confidence'] = 95.0;
                $result['matched_by'] = 'reference_id';
                $stmt->close();
                return $result;
            }
            $stmt->close();
        } else {
            dbg_log("Strategy1 prepare failed: " . $conn->error);
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
                if ($row = ($res ? $res->fetch_assoc() : null)) {
                    $long_name = (string)($row['long_name'] ?? '');
                    similar_text(mb_strtolower($long_name), mb_strtolower((string)$beneficiary), $percent);

                    $result['student_id'] = (int)$row['id'];
                    $result['confidence'] = min(90.0, max(60.0, (float)$percent));
                    $result['matched_by'] = 'name';
                    $stmt->close();
                    return $result;
                }
                $stmt->close();
            } else {
                dbg_log("Strategy2 prepare failed: " . $conn->error);
            }
        }
    }

    // Strategy 3: reference field regex extraction (optional)
    if (!empty($reference)) {
        if (preg_match('/student[:\s]+([^\s]+)/i', (string)$reference, $matches)) {
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
                if ($row = ($res ? $res->fetch_assoc() : null)) {
                    $result['student_id'] = (int)$row['id'];
                    $result['confidence'] = 75.0;
                    $result['matched_by'] = 'regex';
                    $stmt->close();
                    return $result;
                }
                $stmt->close();
            } else {
                dbg_log("Strategy3 prepare failed: " . $conn->error);
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

/* ===============================================================
   NOTIFICATIONS (SCHEMA-SAFE INSERT)
   =============================================================== */
function createNotificationOnce($conn, $urgency, $student_id, $invoice_reference, $time_from, $description) {
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

/* ===============================================================
   MAIN PIPELINE
   =============================================================== */
function processInvoiceMatching($conn, $invoice_id, $reference_number, $beneficiary, $reference, $forceInfo = false) {

    $meta = getInvoiceMeta($conn, $invoice_id);
    $processing_date = $meta['processing_date'] ?: date('Y-m-d H:i:s');
    $time_from_date  = toDateString($processing_date);

    // ✅ invoice_reference MUST be the constant reference id (INVOICE_TAB.reference)
    $invoice_reference = trim((string)$reference);
    if ($invoice_reference === '') $invoice_reference = trim((string)($meta['reference'] ?? ''));
    if ($invoice_reference === '') {
        // last-resort fallback to keep DB inserts stable
        $invoice_reference = "INV-" . (int)$invoice_id;
    }

    $match = matchInvoiceToStudent($conn, $reference_number, $beneficiary, $reference);

    $student_id = $match['student_id'];
    $confidence = (float)$match['confidence'];
    $matched_by = (string)$match['matched_by'];

    if ($forceInfo && $invoice_reference !== '' && $student_id !== null) {
        $is_confirmed = true;
    } else {
        $is_confirmed = ($student_id !== null && $confidence >= CONFIRM_THRESHOLD);
    }

    logMatchingAttempt($conn, $invoice_id, $student_id, $confidence, $matched_by, $is_confirmed);

    $notif_info_ok = null;
    $notif_warn_ok = null;

    if ($is_confirmed) {

        $stmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ? WHERE id = ?");
        if ($stmt) {
            $sid = (int)$student_id;
            $stmt->bind_param("ii", $sid, $invoice_id);
            $stmt->execute();
            $stmt->close();
        }

        $desc = "Confirmed: $invoice_reference matched to Student #$student_id (by $matched_by, " . round($confidence, 1) . "%)";
        $notif_info_ok = createNotificationOnce($conn, "info", $student_id, $invoice_reference, $time_from_date, $desc);

    } else {

        $who = ($student_id !== null) ? "suggested Student #$student_id" : "no student suggested";
        $desc = "Unconfirmed: $invoice_reference ($who) (by $matched_by, " . round($confidence, 1) . "%)";
        $notif_warn_ok = createNotificationOnce($conn, "warning", $student_id, $invoice_reference, $time_from_date, $desc);
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
        'notif_warn_ok' => $notif_warn_ok
    ];
}
?>