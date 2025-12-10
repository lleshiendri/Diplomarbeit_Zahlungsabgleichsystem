<?php

/**
 * MATCHING_HISTORY_TAB Table Creation SQL:
 * 
 * CREATE TABLE MATCHING_HISTORY_TAB (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     transaction_id INT NOT NULL,
 *     student_id INT NULL,
 *     confidence_score FLOAT DEFAULT 100,
 *     matched_by VARCHAR(50) NOT NULL,
 *     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 * );
 * 
 * Note: matched_by values: 'reference', 'fallback', 'manual', 'unconfirmed'
 * 
 * AUTOMATIC MATCHING SYSTEM:
 * This function is called automatically after transaction inserts in:
 * - add_transactions.php (manual transaction entry)
 * - import_files.php (bulk CSV import)
 * 
 * All matching logic runs ONLY in PHP. No database triggers or stored procedures are used.
 * This is the single source of truth for transaction-to-student matching.
 */

require_once __DIR__ . '/db_connect.php';

/**
 * Attempts to match a transaction with a student based on reference ID prefix.
 * 
 * @param int $transactionId The ID of the transaction in INVOICE_TAB
 * @param mysqli $conn The database connection object
 * @return array Returns ['success' => true, 'student_id' => X, 'guardian_id' => Y] if matched, ['success' => false] if not
 */
function attemptMatch($transactionId, $conn) {
    // Load transaction data from INVOICE_TAB (including amount_total)
    $stmt = $conn->prepare("SELECT reference, beneficiary, amount_total FROM INVOICE_TAB WHERE id = ?");
    if (!$stmt) {
        return ['success' => false];
    }
    
    $stmt->bind_param("i", $transactionId);
    $stmt->execute();
    $result = $stmt->get_result();
    $transaction = $result->fetch_assoc();
    $stmt->close();
    
    if (!$transaction) {
        return ['success' => false];
    }
    
    // Try prefix-based matching first
    // Check both 'reference' and 'beneficiary' fields for reference ID
    $description = $transaction['reference'] ?? '';
    if (empty($description)) {
        $description = $transaction['beneficiary'] ?? '';
    }
    
    // Extract reference ID from description
    $referenceId = extractReferenceIdFromDescription($description);
    
    if ($referenceId === null) {
        // No reference found, return false for now (fallback rules will be added later)
        return ['success' => false];
    }
    
    // Match student by reference ID
    $studentId = matchStudentByReferenceId($referenceId, $conn);
    
    if ($studentId === null) {
        return ['success' => false];
    }
    
    // Try to find legal guardian for this student
    $guardianId = null;
    $guardianStmt = $conn->prepare("SELECT legal_guardian_id FROM LEGAL_GUARDIAN_STUDENT_TAB WHERE student_id = ? LIMIT 1");
    if ($guardianStmt) {
        $guardianStmt->bind_param("i", $studentId);
        $guardianStmt->execute();
        $guardianResult = $guardianStmt->get_result();
        if ($guardianRow = $guardianResult->fetch_assoc()) {
            $guardianId = (int)$guardianRow['legal_guardian_id'];
        }
        $guardianStmt->close();
    }
    
    // Fetch transaction amount
    $amount = (float)($transaction['amount_total'] ?? 0);
    
    // Update INVOICE_TAB with student_id, legal_guardian_id, and amount_paid
    if ($guardianId !== null) {
        $updateStmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ?, legal_guardian_id = ?, amount_paid = ? WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param("iidi", $studentId, $guardianId, $amount, $transactionId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    } else {
        $updateStmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ?, legal_guardian_id = NULL, amount_paid = ? WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param("idi", $studentId, $amount, $transactionId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }
    
    // Log the match in MATCHING_HISTORY_TAB (if table exists)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'MATCHING_HISTORY_TAB'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $historyStmt = $conn->prepare("
            INSERT INTO MATCHING_HISTORY_TAB (transaction_id, student_id, matched_by, confidence_score)
            VALUES (?, ?, 'reference', 100)
        ");
        if ($historyStmt) {
            $historyStmt->bind_param("ii", $transactionId, $studentId);
            $historyStmt->execute();
            $historyStmt->close();
        }
    }
    
    return ['success' => true, 'student_id' => $studentId, 'guardian_id' => $guardianId];
}

/**
 * Extracts a reference ID from a transaction description.
 * Looks for prefixes like STF-, APM-, or other patterns dynamically.
 * Extracts the reference ID pattern (letters+numbers matching student_tab.reference_id format).
 * 
 * @param string $description The transaction description/reference field
 * @return string|null The extracted reference ID with HTL- prefix, or null if not found
 */
function extractReferenceIdFromDescription($description) {
    if (empty($description)) {
        return null;
    }
    
    // Remove extra whitespace
    $description = trim($description);
    
    // Reference ID format from reference_id_generator.php: HTL-ABCXYZ123-A5
    // Where:
    //   - HTL- is the prefix
    //   - ABC (3 letters from firstname)
    //   - XYZ (3 letters from lastname)
    //   - 123 (student ID - variable length)
    //   - -A5 (checksum: letter + digit)
    
    // Transactions may have additional prefixes like STF- or APM- before HTL-
    // Examples: "STF-HTLAXXYY123-A5" or "APM-HTLAXXYY123-A5"
    // We need to extract just the HTL- part which matches STUDENT_TAB.reference_id
    
    // First, try to find HTL- directly in the description
    if (preg_match('/HTL-([A-Z]{3}[A-Z]{3}\d+-[A-Z]\d+)/i', $description, $matches)) {
        return 'HTL-' . $matches[1];
    }
    
    // If HTL- not found, look for pattern after known prefixes (STF-, APM-)
    // Pattern: PREFIX-HTLAXXYY123-A5
    $prefixPattern = '/(?:STF|APM|HTL)-HTL-([A-Z]{3}[A-Z]{3}\d+-[A-Z]\d+)/i';
    if (preg_match($prefixPattern, $description, $matches)) {
        return 'HTL-' . $matches[1];
    }
    
    // Try dynamic prefix detection: any 2-5 uppercase letters followed by HTL-
    $dynamicPattern = '/[A-Z]{2,5}-HTL-([A-Z]{3}[A-Z]{3}\d+-[A-Z]\d+)/i';
    if (preg_match($dynamicPattern, $description, $matches)) {
        return 'HTL-' . $matches[1];
    }
    
    // Also try to match reference ID pattern without HTL- prefix
    // Pattern: ABCXYZ123-A5 (3 letters, 3 letters, digits, hyphen, letter, digit)
    // This would match the core reference ID part
    $refIdPattern = '/([A-Z]{3}[A-Z]{3}\d+-[A-Z]\d+)/i';
    if (preg_match($refIdPattern, $description, $matches)) {
        // Check if there's a prefix before it
        $beforeMatch = substr($description, 0, strpos($description, $matches[1]));
        // If we have STF- or APM- before it, assume it's meant to be HTL-
        if (preg_match('/(?:STF|APM|HTL)-?\s*$/i', trim($beforeMatch))) {
            return 'HTL-' . $matches[1];
        }
        // Otherwise, assume it might already be a reference ID without HTL- prefix
        // Return with HTL- prefix to match database format
        return 'HTL-' . $matches[1];
    }
    
    return null;
}

/**
 * Matches a student by their reference ID.
 * The referenceId parameter should already be in the format HTL-ABCXYZ123-A5
 * (as returned by extractReferenceIdFromDescription).
 * 
 * @param string $referenceId The reference ID to search for (format: HTL-ABCXYZ123-A5)
 * @return int|null The student ID if found, null otherwise
 */
function matchStudentByReferenceId($referenceId, $conn) {
    if (empty($referenceId)) {
        return null;
    }
    
    // Normalize to uppercase for comparison
    $referenceId = strtoupper(trim($referenceId));
    
    // Query STUDENT_TAB for matching reference_id
    // The reference_id in STUDENT_TAB is stored as: HTL-ABCXYZ123-A5
    $stmt = $conn->prepare("SELECT id FROM STUDENT_TAB WHERE reference_id = ?");
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("s", $referenceId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row) {
        return (int)$row['id'];
    }
    
    return null;
}

