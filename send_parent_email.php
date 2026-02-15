<?php
declare(strict_types=1);

require_once __DIR__ . "/test.php";

function sendParentEmailForNotification(mysqli $conn, array $notif, string &$errorMsg): bool
{
    $errorMsg = '';

    $studentId = (int)($notif['student_id'] ?? 0);
    if ($studentId <= 0) {
        $errorMsg = "Invalid student_id";
        return false;
    }

    // 1) Fetch student (mysqlnd-safe)
    $stmt = $conn->prepare("SELECT id, forename, name FROM STUDENT_TAB WHERE id = ? LIMIT 1");
    if (!$stmt) {
        $errorMsg = "DB prep student failed: " . $conn->error;
        return false;
    }

    $stmt->bind_param("i", $studentId);
    $stmt->execute();

    $stmt->bind_result($id, $forename, $surname);
    $student = null;

    if ($stmt->fetch()) {
        $student = ['id' => $id, 'forename' => $forename, 'name' => $surname];
    }
    $stmt->close();

    if (!$student) {
        $errorMsg = "Student not found for id={$studentId}";
        return false;
    }

    $studentName = trim((string)$student['forename'] . ' ' . (string)$student['name']);
    if ($studentName === '') $studentName = "Student ID " . $studentId;

    // 2) Fetch guardian emails via LEGAL_GUARDIAN_STUDENT_TAB (mysqlnd-safe)
    $emails = [];

    $stmt = $conn->prepare("
        SELECT lg.email
        FROM LEGAL_GUARDIAN_STUDENT_TAB lgs
        JOIN LEGAL_GUARDIAN_TAB lg ON lg.id = lgs.legal_guardian_id
        WHERE lgs.student_id = ?
          AND lg.email IS NOT NULL
          AND lg.email <> ''
    ");
    if (!$stmt) {
        $errorMsg = "DB prep guardian failed: " . $conn->error;
        return false;
    }

    $stmt->bind_param("i", $studentId);
    $stmt->execute();

    $stmt->bind_result($email);
    while ($stmt->fetch()) {
        $email = trim((string)$email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }
    $stmt->close();

    $emails = array_values(array_unique($emails));
    if (count($emails) === 0) {
        $errorMsg = "No valid legal guardian email found for student_id={$studentId}";
        return false;
    }

    // 3) Compose email
    $invoiceRef  = (string)($notif['invoice_reference'] ?? '');
    $timeFrom    = (string)($notif['time_from'] ?? '');
    $description = trim((string)($notif['description'] ?? ''));

    $subject = "Official Notice: Late Fee Recorded – {$studentName}";
    $body =
        "Dear Parent or Legal Guardian,\n\n" .
        "This is an official notification that a late fee has been recorded in the school accounting system for the following student:\n\n" .
        "Student: {$studentName}\n" .
        ($invoiceRef !== '' ? "Invoice / Reference: {$invoiceRef}\n" : "") .
        ($timeFrom !== '' ? "Date: {$timeFrom}\n" : "") .
        ($description !== '' ? "Details: {$description}\n" : "") .
        "\nWe kindly request that the outstanding amount be settled as soon as possible.\n\n" .
        "If payment has already been completed, please disregard this notice.\n\n" .
        "For further information, please contact the school administration.\n\n" .
        "Sincerely,\n" .
        "Accounting Office\n";

    // 4) Send
    return graph_send_mail($emails, $subject, $body, $errorMsg);
}