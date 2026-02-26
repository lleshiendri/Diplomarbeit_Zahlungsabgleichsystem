<?php
declare(strict_types=1);

/**
 * send_email_reference.php
 * Sends the student's payment reference ID by email to the student (if email exists) and all linked legal guardians.
 * JSON-only response. Callable from student_state.php or via AJAX.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

if (!function_exists('graph_send_mail')) {
    require_once __DIR__ . '/test.php';
}

$sentTo = [];
$errors = [];

// --- 1) Input: student_id from GET or POST ---
$rawId = $_GET['student_id'] ?? $_POST['student_id'] ?? null;
if ($rawId === null || $rawId === '') {
    echo json_encode([
        'success' => false,
        'sent_to' => [],
        'errors' => ['Missing student_id.'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$studentId = filter_var($rawId, FILTER_VALIDATE_INT);
if ($studentId === false || $studentId <= 0) {
    echo json_encode([
        'success' => false,
        'sent_to' => [],
        'errors' => ['Invalid student_id: must be a positive integer.'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 2) Fetch student: forename, name, reference_id, email (if column exists) ---
$studentCols = ['forename', 'name', 'reference_id'];
$resCol = @$conn->query("SHOW COLUMNS FROM STUDENT_TAB LIKE 'email'");
if ($resCol && $resCol->num_rows > 0) {
    $studentCols[] = 'email';
}
$colsList = implode(', ', $studentCols);
$stmt = $conn->prepare("SELECT {$colsList} FROM STUDENT_TAB WHERE id = ? LIMIT 1");
if (!$stmt) {
    error_log('[send_email_reference] DB prepare failed for student.');
    echo json_encode([
        'success' => false,
        'sent_to' => [],
        'errors' => ['Database error.'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param('i', $studentId);
$stmt->execute();
$result = $stmt->get_result();
$student = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$student) {
    echo json_encode([
        'success' => false,
        'sent_to' => [],
        'errors' => ['Student not found.'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$forename = trim((string)($student['forename'] ?? ''));
$name = trim((string)($student['name'] ?? ''));
$referenceId = isset($student['reference_id']) ? trim((string)$student['reference_id']) : '';
$studentEmail = isset($student['email']) ? trim((string)$student['email']) : '';

if ($referenceId === '') {
    echo json_encode([
        'success' => false,
        'sent_to' => [],
        'errors' => ['Student has no reference ID.'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$studentFullName = trim($forename . ' ' . $name);
if ($studentFullName === '') {
    $studentFullName = 'Student #' . $studentId;
}

// --- 3) Fetch legal guardian emails (JOIN LEGAL_GUARDIAN_STUDENT_TAB + LEGAL_GUARDIAN_TAB) ---
$guardianEmails = [];
$tableCheck = @$conn->query("SHOW TABLES LIKE 'LEGAL_GUARDIAN_STUDENT_TAB'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $resColLg = @$conn->query("SHOW COLUMNS FROM LEGAL_GUARDIAN_TAB LIKE 'email'");
    if ($resColLg && $resColLg->num_rows > 0) {
        $stmtG = $conn->prepare("
            SELECT lg.email
            FROM LEGAL_GUARDIAN_STUDENT_TAB lgs
            JOIN LEGAL_GUARDIAN_TAB lg ON lg.id = lgs.legal_guardian_id
            WHERE lgs.student_id = ?
              AND lg.email IS NOT NULL
              AND lg.email <> ''
        ");
        if ($stmtG) {
            $stmtG->bind_param('i', $studentId);
            $stmtG->execute();
            $resG = $stmtG->get_result();
            if ($resG) {
                while ($row = $resG->fetch_assoc()) {
                    $em = trim((string)($row['email'] ?? ''));
                    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                        $guardianEmails[] = $em;
                    }
                }
            }
            $stmtG->close();
        }
    }
}

// --- 4) Build unique recipient list: student (if valid email) + all guardians ---
$recipients = [];
if ($studentEmail !== '' && filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
    $recipients[] = $studentEmail;
}
foreach ($guardianEmails as $em) {
    if (!in_array($em, $recipients, true)) {
        $recipients[] = $em;
    }
}

if (count($recipients) === 0) {
    echo json_encode([
        'success' => false,
        'sent_to' => [],
        'errors' => ['No valid email address found for this student or their legal guardians.'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 5) Email content ---
$subject = 'Your School Payment Reference ID';
$body =
    "Dear Sir or Madam,\n\n" .
    "Please find below the official payment reference information for school fees.\n\n" .
    "Student: {$studentFullName}\n\n" .
    "Reference ID: {$referenceId}\n\n" .
    "This reference must be used when making any payment related to this student. " .
    "Including it ensures that your payment is correctly assigned and processed without delay.\n\n" .
    "If you have any questions, please contact the school administration.\n\n" .
    "Yours sincerely,\n" .
    "Accounting Office\n";

// --- 6) Send one email per recipient; track sent_to and errors ---
foreach ($recipients as $email) {
    $errMsg = '';
    $ok = graph_send_mail([$email], $subject, $body, $errMsg);
    if ($ok) {
        $sentTo[] = $email;
    } else {
        $errors[] = $email . ': ' . $errMsg;
        error_log('[send_email_reference] Failed to send to ' . $email . ': ' . $errMsg);
    }
}

$success = (count($errors) === 0 && count($sentTo) > 0);

echo json_encode([
    'success' => $success,
    'sent_to' => $sentTo,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE);
