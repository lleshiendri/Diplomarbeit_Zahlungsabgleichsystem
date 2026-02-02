<?php

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
 */
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

    // Load invoice data (ADD reference_number so we can store it into NOTIFICATION_TAB.invoice_reference)
    $stmt = $conn->prepare("
        SELECT id, reference_number, reference, description, beneficiary, amount_total, processing_date
        FROM INVOICE_TAB
        WHERE id = ?
    ");
    if (!$stmt) {
        return ['success' => false];
    }

    $stmt->bind_param("i", $invoiceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        // Optional: warning without student
        $invoiceRef = null;
        if (!notif_exists($conn, $invoiceRef, 'warning')) {
            notif_insert($conn, null, $invoiceRef, 'warning', "Unconfirmed transaction: invoice not found", date('Y-m-d H:i:s'));
        }
        return ['success' => false];
    }

    // invoice_reference for notifications = INVOICE_TAB.reference_number
    $invoiceRef = $invoice['reference_number'] ?? null;

    // Use processing_date if available for time_from, otherwise NOW
    $timeFrom = !empty($invoice['processing_date'])
        ? date('Y-m-d H:i:s', strtotime($invoice['processing_date']))
        : date('Y-m-d H:i:s');

    // Combine fields for extraction
    $descriptionText = trim(($invoice['reference'] ?? '') . ' ' . ($invoice['beneficiary'] ?? '') . ' ' . ($invoice['description'] ?? ''));

    // Extract reference ID
    $referenceId = null;
    $words = preg_split('/\s+/', $descriptionText);

    foreach ($words as $word) {
        $word = trim($word);
        if (preg_match('/^[A-Za-z0-9]+-[A-Za-z0-9]+/', $word)) {
            $referenceId = strtoupper($word);
            break;
        }
    }

    if ($referenceId === null) {
        // WARNING: unconfirmed because no reference id in text
        if (!notif_exists($conn, $invoiceRef, 'warning')) {
            $desc = "Unconfirmed transaction: no reference ID found";
            notif_insert($conn, null, $invoiceRef, 'warning', $desc, $timeFrom);
        }
        return ['success' => false];
    }

    // Match student by reference_id
    $studentStmt = $conn->prepare("SELECT id FROM STUDENT_TAB WHERE reference_id = ? LIMIT 1");
    if (!$studentStmt) {
        return ['success' => false];
    }

    $studentStmt->bind_param("s", $referenceId);
    $studentStmt->execute();
    $studentResult = $studentStmt->get_result();
    $studentRow = $studentResult->fetch_assoc();
    $studentStmt->close();

    if (!$studentRow) {
        // WARNING: reference exists but no student matched
        if (!notif_exists($conn, $invoiceRef, 'warning')) {
            $desc = "Unconfirmed transaction: reference '$referenceId' not assigned to a student";
            notif_insert($conn, null, $invoiceRef, 'warning', $desc, $timeFrom);
        }
        return ['success' => false];
    }

    $studentId = (int)$studentRow['id'];

    // BEFORE confirming: check if it was unconfirmed (student_id was NULL)
    $oldStudentId = null;
    $checkStmt = $conn->prepare("SELECT student_id FROM INVOICE_TAB WHERE id = ? LIMIT 1");
    $checkStmt->bind_param("i", $invoiceId);
    $checkStmt->execute();
    $checkStmt->bind_result($oldStudentId);
    $checkStmt->fetch();
    $checkStmt->close();

    // Confirm invoice: set student_id
    $updateStmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ? WHERE id = ?");
    if (!$updateStmt) {
        return ['success' => false];
    }

    $updateStmt->bind_param("ii", $studentId, $invoiceId);
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        return ['success' => false];
    }
    $updateStmt->close();

    // Insert into MATCHING_HISTORY_TAB (confirmed)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'MATCHING_HISTORY_TAB'");
    if ($tableCheck && $tableCheck->num_rows > 0) {

        // Check if is_confirmed column exists (only once)
        $hasIsConfirmed = false;
        $colRes = $conn->query("SHOW COLUMNS FROM MATCHING_HISTORY_TAB LIKE 'is_confirmed'");
        if ($colRes && $colRes->num_rows > 0) $hasIsConfirmed = true;

        if ($hasIsConfirmed) {
            $historyStmt = $conn->prepare("
                INSERT INTO MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by, is_confirmed)
                VALUES (?, ?, 100, 'reference', 1)
            ");
        } else {
            $historyStmt = $conn->prepare("
                INSERT INTO MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by)
                VALUES (?, ?, 100, 'reference')
            ");
        }

        if ($historyStmt) {
            $historyStmt->bind_param("ii", $invoiceId, $studentId);
            $historyStmt->execute();
            $historyStmt->close();
        }
    }

    // --- AFTER confirming: create INFO (always if missing) + run late-fee safely ---
    if (!notif_exists($conn, $invoiceRef, 'info')) {
        $desc = "Transaction confirmed (reference '$referenceId')";
        notif_insert($conn, $studentId, $invoiceRef, 'info', $desc, $timeFrom);
    }

    // Late fee check can be called safely every time (procedure already dedupes by month)
    $callStmt = $conn->prepare("CALL sp_apply_late_fee_for_student_month(?)");
    if ($callStmt) {
        $callStmt->bind_param("i", $studentId);
        $callStmt->execute();
        $callStmt->close();
    }

    // Update student financial fields
    $invoiceAmount = (float)($invoice['amount_total'] ?? 0);
    if ($invoiceAmount > 0) {
        $studentFinStmt = $conn->prepare("SELECT left_to_pay, amount_paid FROM STUDENT_TAB WHERE id = ?");
        if ($studentFinStmt) {
            $studentFinStmt->bind_param("i", $studentId);
            $studentFinStmt->execute();
            $studentFinResult = $studentFinStmt->get_result();
            $studentFinRow = $studentFinResult->fetch_assoc();
            $studentFinStmt->close();

            if ($studentFinRow) {
                $currentLeftToPay = (float)($studentFinRow['left_to_pay'] ?? 0);
                $currentAmountPaid = (float)($studentFinRow['amount_paid'] ?? 0);

                $newLeftToPay = max(0, $currentLeftToPay - $invoiceAmount);
                $newAmountPaid = $currentAmountPaid + $invoiceAmount;

                $updateStudentStmt = $conn->prepare("UPDATE STUDENT_TAB SET left_to_pay = ?, amount_paid = ? WHERE id = ?");
                if ($updateStudentStmt) {
                    $updateStudentStmt->bind_param("ddi", $newLeftToPay, $newAmountPaid, $studentId);
                    $updateStudentStmt->execute();
                    $updateStudentStmt->close();
                }
            }
        }
    }
    
    // Insert into MATCHING_HISTORY_TAB if table exists (only after successful match and update)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'MATCHING_HISTORY_TAB'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $historyStmt = $conn->prepare("
            INSERT INTO MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by)
            VALUES (?, ?, 100, 'reference')
        ");
        if ($historyStmt) {
            $historyStmt->bind_param("ii", $invoiceId, $studentId);
            $historyStmt->execute();
            $historyStmt->close();
        }
    }
    
    return ['success' => true, 'student_id' => $studentId, 'reference' => $referenceId];
}
