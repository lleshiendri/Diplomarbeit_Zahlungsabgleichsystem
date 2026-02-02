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

/**
 * Extracts up to 4 reference IDs from invoice text
 * 
 * @param string $text Combined text from invoice fields (reference, description, beneficiary)
 * @return array Array of normalized reference IDs (0-4 items), in order of first appearance
 */
function extractReferenceIds($text) {
    $extracted = [];
    $seen = [];
    
    // Pattern: alphanumeric segments separated by hyphens, allowing punctuation/whitespace around
    // Matches patterns like: HTL-ABC123-A7, HTL-XXXYYY-A7, etc.
    // The pattern allows for 2+ segments separated by hyphens
    if (preg_match_all('/[A-Za-z0-9]+(?:-[A-Za-z0-9]+)+/', $text, $matches)) {
        foreach ($matches[0] as $match) {
            // Normalize: trim punctuation/whitespace and uppercase
            $normalized = strtoupper(trim($match));
            
            // Ensure uniqueness and preserve order of first appearance
            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $extracted[] = $normalized;
                
                // Cap at 4 references
                if (count($extracted) >= 4) {
                    break;
                }
            }
        }
    }
    
    return $extracted;
}

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
        $tableCheck = $conn->query("SHOW TABLES LIKE 'MATCHING_HISTORY_TAB'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            $historyTableExists = true;
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
                        $historyStmt = $conn->prepare("
                            INSERT INTO MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by)
                            VALUES (?, ?, 50, 'name_suggest')
                        ");
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
                        WHERE invoice_id = ? AND student_id = ? AND matched_by = 'name_fuzzy'
                        LIMIT 1
                    ");
                    if ($checkHistoryStmt) {
                        $checkHistoryStmt->bind_param("ii", $invoiceId, $fuzzyNameSuggestion['student_id']);
                        $checkHistoryStmt->execute();
                        $checkHistoryResult = $checkHistoryStmt->get_result();
                        $exists = $checkHistoryResult->fetch_assoc();
                        $checkHistoryStmt->close();
                        
                        // Only insert if it doesn't exist
                        if (!$exists) {
                            $historyStmt = $conn->prepare("
                                INSERT INTO MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by)
                                VALUES (?, ?, ?, 'name_fuzzy')
                            ");
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
                            
                            // Only insert if it doesn't exist
                            if (!$exists) {
                                $historyStmt = $conn->prepare("
                                    INSERT INTO MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by)
                                    VALUES (?, ?, ?, 'reference_fuzzy')
                                ");
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
    $tableCheck = $conn->query("SHOW TABLES LIKE 'MATCHING_HISTORY_TAB'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $historyTableExists = true;
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
                        $historyStmt = $conn->prepare("
                            INSERT INTO MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by)
                            VALUES (?, ?, ?, 'reference_fuzzy')
                        ");
                        
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
                            
                            // Try "iii" first - if column is INT, this should work
                            // If that fails, we'll try "iid" for DECIMAL/FLOAT columns
                            $bindSuccess = $historyStmt->bind_param("iii", $invoiceId, $fuzzyMatch['student_id'], $confidenceScoreInt);
                            
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
function attemptReferenceMatch($invoiceId, $conn) {
    // Test that this function is being called - log immediately
    error_log("ATTEMPT_REFERENCE_MATCH: Function called for invoice_id=$invoiceId");
    
    // #region agent log
    // Use main directory instead of .cursor subdirectory (permissions issue on shared hosting)
    $logFile = __DIR__ . '/debug_matching.log';
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'pre-fix',
        'hypothesisId' => 'A',
        'location' => 'matching_engine.php:536',
        'message' => 'attemptReferenceMatch Function entry',
        'data' => ['invoiceId' => $invoiceId],
        'timestamp' => round(microtime(true) * 1000)
    ]) . "\n";
    $writeResult = @file_put_contents($logFile, $logEntry, FILE_APPEND);
    if (!$writeResult) {
        error_log("ATTEMPT_REFERENCE_MATCH: FAILED to write to log file: $logFile");
    }
    // #endregion
    
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
    
    // Combine reference, description, and beneficiary fields for extraction
    $descriptionText = trim(($invoice['reference'] ?? '') . ' ' . ($invoice['description'] ?? '') . ' ' . ($invoice['beneficiary'] ?? ''));
    
    // Extract up to 4 reference IDs from the combined text
    $referenceIds = extractReferenceIds($descriptionText);
    
    // #region agent log
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'pre-fix',
        'hypothesisId' => 'C',
        'location' => 'matching_engine.php:97',
        'message' => 'Extracted reference IDs',
        'data' => ['referenceIds' => $referenceIds, 'count' => count($referenceIds), 'descriptionText' => $descriptionText],
        'timestamp' => round(microtime(true) * 1000)
    ]) . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
    // #endregion
    
    if (empty($referenceIds)) {
        return ['success' => false];
    }
    
    // Collect all matches (0-4 students per invoice)
    $matches = []; // Array of ['student_id' => X, 'reference' => Y]
    $seenStudentIds = []; // Track which students we've already matched to avoid duplicates
    
    foreach ($referenceIds as $referenceId) {
        // #region agent log
        $logEntry = json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'post-fix',
            'hypothesisId' => 'A',
            'location' => 'matching_engine.php:119',
            'message' => 'Checking reference ID',
            'data' => ['referenceId' => $referenceId, 'index' => array_search($referenceId, $referenceIds)],
            'timestamp' => round(microtime(true) * 1000)
        ]) . "\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        // #endregion
        
        // Match student by reference_id using existing exact-match logic
        $studentStmt = $conn->prepare("SELECT id FROM STUDENT_TAB WHERE reference_id = ? LIMIT 1");
        if (!$studentStmt) {
            continue;
        }
        
        $studentStmt->bind_param("s", $referenceId);
        $studentStmt->execute();
        $studentResult = $studentStmt->get_result();
        $studentRow = $studentResult->fetch_assoc();
        $studentStmt->close();
        
        // Collect all matches (no break - continue checking all references)
        // Deduplicate: only add each student once (first reference that matches wins)
        if ($studentRow) {
            $matchedStudentId = (int)$studentRow['id'];
            
            // Only add if we haven't seen this student_id before
            if (!isset($seenStudentIds[$matchedStudentId])) {
                $seenStudentIds[$matchedStudentId] = true;
                $matches[] = ['student_id' => $matchedStudentId, 'reference' => $referenceId];
                
                // #region agent log
                $logEntry = json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'post-fix',
                    'hypothesisId' => 'A',
                    'location' => 'matching_engine.php:146',
                    'message' => 'Match found, continuing loop',
                    'data' => ['studentId' => $matchedStudentId, 'referenceId' => $referenceId, 'totalMatches' => count($matches)],
                    'timestamp' => round(microtime(true) * 1000)
                ]) . "\n";
                @file_put_contents($logFile, $logEntry, FILE_APPEND);
                // #endregion
            } else {
                // #region agent log
                $logEntry = json_encode([
                    'sessionId' => 'debug-session',
                    'runId' => 'post-fix',
                    'hypothesisId' => 'A',
                    'location' => 'matching_engine.php:160',
                    'message' => 'Duplicate student match skipped',
                    'data' => ['studentId' => $matchedStudentId, 'referenceId' => $referenceId],
                    'timestamp' => round(microtime(true) * 1000)
                ]) . "\n";
                @file_put_contents($logFile, $logEntry, FILE_APPEND);
                // #endregion
            }
        }
    }
    
    // If no matches found, still try fuzzy matching before returning failure
    if (empty($matches)) {
        // #region agent log
        $logEntry = json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'post-fix',
            'hypothesisId' => 'A',
            'location' => 'matching_engine.php:586',
            'message' => 'No exact matches found, attempting fuzzy matching',
            'data' => ['totalRefsChecked' => count($referenceIds)],
            'timestamp' => round(microtime(true) * 1000)
        ]) . "\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        // #endregion
        
        // Try fuzzy matching even when no exact matches found
        getFuzzySuggestions($invoiceId, $conn);
        
        return ['success' => false];
    }
    
    // Update INVOICE_TAB.student_id with first match (for backward compatibility)
    // If multiple matches exist, the primary student_id is the first one
    $firstStudentId = $matches[0]['student_id'];
    $updateStmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ? WHERE id = ?");
    if ($updateStmt) {
        $updateStmt->bind_param("ii", $firstStudentId, $invoiceId);
        $updateStmt->execute();
        $updateStmt->close();
    }
    
    // Process each match: update financials and create history entries
    $invoiceAmount = (float)($invoice['amount_total'] ?? 0);
    $historyEntriesCreated = 0;
    
    // Divide amount equally among all matched students
    $matchCount = count($matches);
    $amountPerStudent = ($matchCount > 0 && $invoiceAmount > 0) ? ($invoiceAmount / $matchCount) : 0;
    
    // #region agent log
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'post-fix',
        'hypothesisId' => 'E',
        'location' => 'matching_engine.php:210',
        'message' => 'Amount division calculated',
        'data' => ['invoiceAmount' => $invoiceAmount, 'matchCount' => $matchCount, 'amountPerStudent' => $amountPerStudent],
        'timestamp' => round(microtime(true) * 1000)
    ]) . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
    // #endregion
    
    // Check if MATCHING_HISTORY_TAB exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'MATCHING_HISTORY_TAB'");
    $historyTableExists = ($tableCheck && $tableCheck->num_rows > 0);
    
    foreach ($matches as $match) {
        $currentStudentId = $match['student_id'];
        $currentReference = $match['reference'];
        
        // Update student's financial fields after successful match
        // Use divided amount per student when multiple matches exist
        if ($amountPerStudent > 0) {
            // Fetch student's current financial data
            $studentFinStmt = $conn->prepare("SELECT left_to_pay, amount_paid FROM STUDENT_TAB WHERE id = ?");
            if ($studentFinStmt) {
                $studentFinStmt->bind_param("i", $currentStudentId);
                $studentFinStmt->execute();
                $studentFinResult = $studentFinStmt->get_result();
                $studentFinRow = $studentFinResult->fetch_assoc();
                $studentFinStmt->close();
                
                if ($studentFinRow) {
                    $currentLeftToPay = (float)($studentFinRow['left_to_pay'] ?? 0);
                    $currentAmountPaid = (float)($studentFinRow['amount_paid'] ?? 0);
                    
                    // Calculate new values: reduce left_to_pay and increase amount_paid
                    // Use divided amount per student
                    $newLeftToPay = max(0, $currentLeftToPay - $amountPerStudent);
                    $newAmountPaid = $currentAmountPaid + $amountPerStudent;
                    
                    // Update student record with new financial values
                    $updateStudentStmt = $conn->prepare("UPDATE STUDENT_TAB SET left_to_pay = ?, amount_paid = ? WHERE id = ?");
                    if ($updateStudentStmt) {
                        $updateStudentStmt->bind_param("ddi", $newLeftToPay, $newAmountPaid, $currentStudentId);
                        $updateStudentStmt->execute();
                        $updateStudentStmt->close();
                        
                        // #region agent log
                        $logEntry = json_encode([
                            'sessionId' => 'debug-session',
                            'runId' => 'post-fix',
                            'hypothesisId' => 'E',
                            'location' => 'matching_engine.php:250',
                            'message' => 'Student financials updated with divided amount',
                            'data' => ['studentId' => $currentStudentId, 'amountPerStudent' => $amountPerStudent, 'newLeftToPay' => $newLeftToPay, 'newAmountPaid' => $newAmountPaid],
                            'timestamp' => round(microtime(true) * 1000)
                        ]) . "\n";
                        @file_put_contents($logFile, $logEntry, FILE_APPEND);
                        // #endregion
                    }
                }
            }
        }
        
        // Insert into MATCHING_HISTORY_TAB for each match
        if ($historyTableExists) {
            $historyStmt = $conn->prepare("
                INSERT INTO MATCHING_HISTORY_TAB (invoice_id, student_id, confidence_score, matched_by)
                VALUES (?, ?, 100, 'reference')
            ");
            if ($historyStmt) {
                $historyStmt->bind_param("ii", $invoiceId, $currentStudentId);
                $insertSuccess = $historyStmt->execute();
                $insertError = $historyStmt->error;
                $historyStmt->close();
                
                if ($insertSuccess) {
                    $historyEntriesCreated++;
                    
                    // #region agent log
                    $logEntry = json_encode([
                        'sessionId' => 'debug-session',
                        'runId' => 'post-fix',
                        'hypothesisId' => 'B',
                        'location' => 'matching_engine.php:260',
                        'message' => 'MATCHING_HISTORY_TAB entry created',
                        'data' => ['invoiceId' => $invoiceId, 'studentId' => $currentStudentId, 'reference' => $currentReference, 'entriesCreated' => $historyEntriesCreated],
                        'timestamp' => round(microtime(true) * 1000)
                    ]) . "\n";
                    @file_put_contents($logFile, $logEntry, FILE_APPEND);
                    // #endregion
                } else {
                    // #region agent log
                    $logEntry = json_encode([
                        'sessionId' => 'debug-session',
                        'runId' => 'post-fix',
                        'hypothesisId' => 'B',
                        'location' => 'matching_engine.php:273',
                        'message' => 'MATCHING_HISTORY_TAB insert failed',
                        'data' => ['invoiceId' => $invoiceId, 'studentId' => $currentStudentId, 'error' => $insertError],
                        'timestamp' => round(microtime(true) * 1000)
                    ]) . "\n";
                    @file_put_contents($logFile, $logEntry, FILE_APPEND);
                    // #endregion
                }
            }
        }
    }
    
    // #region agent log
    $logEntry = json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'post-fix',
        'hypothesisId' => 'A',
        'location' => 'matching_engine.php:799',
        'message' => 'Function exit',
        'data' => ['success' => true, 'totalMatches' => count($matches), 'totalRefsChecked' => count($referenceIds), 'historyEntriesCreated' => $historyEntriesCreated, 'matches' => $matches],
        'timestamp' => round(microtime(true) * 1000)
    ]) . "\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
    // #endregion
    
    // After exact matching, generate fuzzy suggestions for unmatched references
    // This ensures fuzzy suggestions are logged to MATCHING_HISTORY_TAB automatically
    error_log("ATTEMPT_REFERENCE_MATCH: Calling getFuzzySuggestions for invoice_id=$invoiceId (had " . count($matches) . " exact matches)");
    getFuzzySuggestions($invoiceId, $conn);
    
    // Return success with first match info for backward compatibility
    return ['success' => true, 'student_id' => $firstStudentId, 'reference' => $matches[0]['reference'], 'total_matches' => count($matches)];
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
