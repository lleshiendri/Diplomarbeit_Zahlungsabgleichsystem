<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/mail_with_attachment.php';

// Validate and normalize student_id
$studentIdParam = $_GET['student_id'] ?? '';
if (!is_string($studentIdParam) || !ctype_digit($studentIdParam)) {
    $studentId = 0;
} else {
    $studentId = (int)$studentIdParam;
}

// Preserve return query string for redirect back to student_state.php
$returnParam = isset($_GET['return']) && is_string($_GET['return']) ? $_GET['return'] : '';

/**
 * Build redirect URL back to student_state.php while preserving filters/pagination if provided.
 *
 * @param string $returnQuery Encoded original query string (value of return=...)
 * @param array<string,mixed> $extraParams
 */
function redirect_back_to_student_state(string $returnQuery, array $extraParams): void {
    $extra = http_build_query($extraParams);

    if ($returnQuery !== '') {
        // returnQuery already represents a query string like "student=...&page=2"
        $base = 'student_state.php?' . $returnQuery;
        $separator = '&';
    } else {
        $base = 'student_state.php';
        $separator = '?';
    }

    $location = $base . $separator . $extra;
    header('Location: ' . $location);
    exit;
}

if ($studentId <= 0) {
    redirect_back_to_student_state($returnParam, [
        'email_ok' => 0,
        'email_error' => rawurlencode('Invalid student ID.'),
    ]);
}

// Fetch student name
$studentStmt = $conn->prepare('SELECT long_name, name FROM STUDENT_TAB WHERE id = ? LIMIT 1');
if (!$studentStmt) {
    redirect_back_to_student_state($returnParam, [
        'email_ok' => 0,
        'student_id' => $studentId,
        'email_error' => rawurlencode('Database error while preparing student lookup.'),
    ]);
}

$studentStmt->bind_param('i', $studentId);
$studentStmt->execute();
$studentStmt->bind_result($studentLongName, $studentNameRaw);
$studentRowFound = $studentStmt->fetch();
$studentStmt->close();

if (!$studentRowFound) {
    redirect_back_to_student_state($returnParam, [
        'email_ok' => 0,
        'student_id' => $studentId,
        'email_error' => rawurlencode('Student not found.'),
    ]);
}

$studentDisplayName = trim((string)$studentLongName);
if ($studentDisplayName === '') {
    $studentDisplayName = trim((string)$studentNameRaw);
}
if ($studentDisplayName === '') {
    $studentDisplayName = 'Student ID ' . $studentId;
}

// Resolve guardian recipients
$guardianStmt = $conn->prepare("
    SELECT 
        lg.id,
        lg.email,
        CONCAT(COALESCE(lg.first_name, ''), ' ', COALESCE(lg.last_name, '')) AS full_name
    FROM LEGAL_GUARDIAN_STUDENT_TAB lgs
    JOIN LEGAL_GUARDIAN_TAB lg ON lg.id = lgs.legal_guardian_id
    WHERE lgs.student_id = ?
");

if (!$guardianStmt) {
    redirect_back_to_student_state($returnParam, [
        'email_ok' => 0,
        'student_id' => $studentId,
        'email_error' => rawurlencode('Database error while preparing guardian lookup.'),
    ]);
}

$guardianStmt->bind_param('i', $studentId);
$guardianStmt->execute();
$guardianStmt->bind_result($guardianId, $guardianEmail, $guardianFullName);

$recipients = [];
while ($guardianStmt->fetch()) {
    $email = trim((string)$guardianEmail);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        continue;
    }

    $name = trim((string)$guardianFullName);
    if ($name === '') {
        $name = $email;
    }

    $recipients[] = [
        'email' => $email,
        'name' => $name,
    ];
}
$guardianStmt->close();

if (count($recipients) === 0) {
    redirect_back_to_student_state($returnParam, [
        'email_ok' => 0,
        'student_id' => $studentId,
        'email_error' => rawurlencode('No guardian email found for this student.'),
    ]);
}

// Resolve latest PDF for this student
$pdfDirFs = __DIR__ . '/pdfArchive';
if (!is_dir($pdfDirFs)) {
    redirect_back_to_student_state($returnParam, [
        'email_ok' => 0,
        'student_id' => $studentId,
        'email_error' => rawurlencode('No PDF found for this student.'),
    ]);
}

$pattern = $pdfDirFs . '/report_student_' . $studentId . '_*.pdf';
$files = glob($pattern);

if (!$files || count($files) === 0) {
    redirect_back_to_student_state($returnParam, [
        'email_ok' => 0,
        'student_id' => $studentId,
        'email_error' => rawurlencode('No PDF found for this student.'),
    ]);
}

usort($files, static function (string $a, string $b): int {
    $ma = @filemtime($a) ?: 0;
    $mb = @filemtime($b) ?: 0;
    if ($ma === $mb) {
        return 0;
    }
    return ($ma < $mb) ? 1 : -1;
});

$latestPdfFs = $files[0];
$attachmentName = basename($latestPdfFs);

// Build subject and body
$monthPart = date('Y-m');
$subject = 'Payment Report - ' . $studentDisplayName . ' - ' . $monthPart;

$bodyLines = [
    'Dear Legal Guardian,',
    '',
    'Attached you will find the latest payment report for ' . $studentDisplayName . '.',
    '',
    'If you have already settled the outstanding balance, please disregard this email.',
    '',
    'Best regards,',
    'Accounting Office',
];
$body = implode("\n", $bodyLines);

// Send emails with attachment
$successCount = 0;
$errors = [];

foreach ($recipients as $rec) {
    $sendResult = send_mail_with_attachment(
        (string)$rec['email'],
        (string)$rec['name'],
        $subject,
        $body,
        $latestPdfFs,
        $attachmentName
    );

    if (!empty($sendResult['ok'])) {
        $successCount++;
    } else {
        $err = isset($sendResult['error']) ? (string)$sendResult['error'] : 'Unknown error while sending email.';
        $errors[] = $err;
    }
}

if ($successCount > 0) {
    redirect_back_to_student_state($returnParam, [
        'email_ok' => 1,
        'student_id' => $studentId,
        'email_sent' => $successCount,
    ]);
}

$firstError = $errors[0] ?? 'Email sending failed.';
redirect_back_to_student_state($returnParam, [
    'email_ok' => 0,
    'student_id' => $studentId,
    'email_error' => rawurlencode($firstError),
]);

