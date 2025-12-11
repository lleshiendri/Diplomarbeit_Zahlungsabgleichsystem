<?php

/**
 * MATCHING ENGINE - Reference-Based Matching Only
 * 
 * This is the single source of truth for reference-based transaction-to-student matching.
 * All matching logic runs in PHP only - no database triggers or stored procedures.
 * 
 * Called automatically after transaction inserts in:
 * - add_transactions.php (manual entry)
 * - import_files.php (bulk CSV import)
 */

/**
 * Attempts to match an invoice with a student based on reference ID
 * 
 * @param int $invoiceId The ID of the invoice in INVOICE_TAB
 * @param mysqli $conn Database connection object
 * @return array Returns ['success' => true, 'student_id' => X, 'reference' => Y] if matched, 
 *               ['success' => false] if not
 */
function attemptReferenceMatch($invoiceId, $conn) {
    // Load invoice data - using 'reference' column (contains description text) and amount_total
    $stmt = $conn->prepare("SELECT id, reference, description, beneficiary, amount_total FROM INVOICE_TAB WHERE id = ?");
    if (!$stmt) {
        return ['success' => false];
    }
    
    $stmt->bind_param("i", $invoiceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    $stmt->close();
    
    if (!$invoice) {
        return ['success' => false];
    }
    
    // Combine reference and beneficiary fields for extraction
    $descriptionText = trim(($invoice['reference'] ?? '') . ' ' . ($invoice['beneficiary'] ?? '').' ' . ($invoice['description'] ?? ''));
    
    // Extract reference ID from combined text
    $referenceId = null;
    $words = preg_split('/\s+/', $descriptionText);
    
    // Look for the first token matching pattern: [A-Za-z0-9]+-[A-Za-z0-9]+
    foreach ($words as $word) {
        $word = trim($word);
        if (preg_match('/^[A-Za-z0-9]+-[A-Za-z0-9]+/', $word)) {
            $referenceId = strtoupper($word);
            break;
        }
    }
    
    if ($referenceId === null) {
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
        return ['success' => false];
    }
    
    $studentId = (int)$studentRow['id'];
    
    // Update INVOICE_TAB.student_id
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
    
    // Update student's financial fields after successful match
    $invoiceAmount = (float)($invoice['amount_total'] ?? 0);
    if ($invoiceAmount > 0) {
        // Fetch student's current financial data
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
                
                // Calculate new values: reduce left_to_pay and increase amount_paid
                $newLeftToPay = max(0, $currentLeftToPay - $invoiceAmount);
                $newAmountPaid = $currentAmountPaid + $invoiceAmount;
                
                // Update student record with new financial values
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
