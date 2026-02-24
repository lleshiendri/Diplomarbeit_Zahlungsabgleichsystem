<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/fpdf.php';

/* ============================================================
   CONFIG: archive folder MUST be inside /buchhaltung
   ============================================================ */
$ARCHIVE_DIR = __DIR__ . '/pdfArchive';
if (!is_dir($ARCHIVE_DIR)) {
    @mkdir($ARCHIVE_DIR, 0755, true);
}
if (!is_dir($ARCHIVE_DIR) || !is_writable($ARCHIVE_DIR)) {
    http_response_code(500);
    exit("pdfArchive missing or not writable: " . $ARCHIVE_DIR);
}

$ui = isset($_GET['ui']) && $_GET['ui'] === '1';

function safe_filename($s): string {
    $s = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', (string)$s);
    return trim($s, '_');
}
function money_lek($v): string {
    return number_format((float)$v, 2, ',', '.') . " Lek";
}
function fmt_date_or_dash($d): string {
    if (!$d) return "-";
    $ts = strtotime($d);
    if (!$ts) return "-";
    return date("d/m/Y", $ts);
}

/* ============================================================
   INPUT: EITHER student_id=123 (single) OR ids=1,2,3 (list)
   ============================================================ */
$studentIds = [];

$singleId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
if ($singleId > 0) {
    $studentIds[] = $singleId;
} else {
    $idsParam = trim($_GET['ids'] ?? '');
    if ($idsParam === '') {
        if ($ui) {
            header('Location: student_state.php?pdf_ok=0&pdf_error=' . rawurlencode('Invalid student ID.'));
            exit;
        }
        http_response_code(400);
        exit("Invalid student ID.");
    }

    foreach (explode(',', $idsParam) as $p) {
        $id = (int)trim($p);
        if ($id > 0) $studentIds[] = $id;
    }
}

$studentIds = array_values(array_unique($studentIds));

if (count($studentIds) === 0) {
    if ($ui) {
        header('Location: student_state.php?pdf_ok=0&pdf_error=' . rawurlencode('Invalid student ID.'));
        exit;
    }
    http_response_code(400);
    exit("Invalid student ID.");
}

/* ============================================================
   QUERY: Uses YOUR table fields (matches your student_state select)
   ============================================================ */
$placeholders = implode(',', array_fill(0, count($studentIds), '?'));
$types = str_repeat('i', count($studentIds));

$sql = "
    SELECT
        s.id AS student_id,
        s.extern_key AS extern_key,
        s.long_name AS student_name,
        s.name,
        s.amount_paid,
        s.left_to_pay,
        s.additional_payments_status,
        MAX(i.processing_date) AS last_transaction_date
    FROM STUDENT_TAB s
    LEFT JOIN INVOICE_TAB i ON i.student_id = s.id
    WHERE s.id IN ($placeholders)
    GROUP BY
        s.id, s.extern_key, s.long_name, s.name,
        s.amount_paid, s.left_to_pay, s.additional_payments_status
    ORDER BY s.id ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit("Prepare failed: " . $conn->error);
}
$stmt->bind_param($types, ...$studentIds);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

if (count($rows) === 0) {
    http_response_code(404);
    exit("No students found for given ids.");
}

/* ============================================================
   PDF: one PDF per student, saved into /pdfArchive
   ============================================================ */
$generated = [];
$failed = [];

foreach ($rows as $row) {
    try {
        $studentId = (int)$row['student_id'];
        $studentName = (string)($row['student_name'] ?? '');
        $externKey = (string)($row['extern_key'] ?? '');
        $lastDate = fmt_date_or_dash($row['last_transaction_date'] ?? null);

        $amountPaid = (float)($row['amount_paid'] ?? 0);
        $leftToPay  = (float)($row['left_to_pay'] ?? 0);
        $addPay     = (float)($row['additional_payments_status'] ?? 0);

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        // Title
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'STUDENT STATE REPORT', 0, 1, 'C');

        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, 'Generated: ' . date('d.m.Y H:i'), 0, 1, 'C');
        $pdf->Ln(4);

        // Student info block
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(40, 8, 'Student ID:', 1, 0);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 8, (string)$studentId, 1, 1);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(40, 8, 'Student Name:', 1, 0);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 8, $studentName, 1, 1);

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(40, 8, 'Extern Key:', 1, 0);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 8, $externKey !== '' ? $externKey : '-', 1, 1);

        $pdf->Ln(6);

        // The table you have on the page (same columns)
        $pdf->SetFont('Arial', 'B', 10);

        // Header row
        $pdf->Cell(30, 8, 'Amount Paid', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Left to Pay', 1, 0, 'C');
        $pdf->Cell(45, 8, 'Last Transaction', 1, 0, 'C');
        $pdf->Cell(0, 8, 'Additional Pay. Status', 1, 1, 'C');

        // Values row
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(30, 8, money_lek($amountPaid), 1, 0, 'C');

        // highlight if left_to_pay > 0
        if ($leftToPay > 0.0001) $pdf->SetTextColor(179, 30, 50);
        $pdf->Cell(30, 8, money_lek($leftToPay), 1, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Cell(45, 8, $lastDate, 1, 0, 'C');
        $pdf->Cell(0, 8, money_lek($addPay), 1, 1, 'C');

        $pdf->Ln(10);

        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5,
            "Note: This PDF mirrors the Student State table values.\n" .
            "If you also want the detailed transaction rows per student, you must add a second query (TRANSACTION_TAB) and render another table.",
            0, 'L'
        );

        // Save file
        $fileBase = safe_filename("student_{$studentId}_" . ($row['student_name'] ?? ''));
        if ($fileBase === '') $fileBase = "student_" . $studentId;

        $filePath = $ARCHIVE_DIR . "/report_" . $fileBase . "_" . date("Y-m") . ".pdf";
        $pdf->Output('F', $filePath);

        $generated[] = $filePath;

    } catch (Throwable $e) {
        $failed[] = [
            'student_id' => $row['student_id'] ?? null,
            'error' => $e->getMessage()
        ];
    }
}

// If called from UI, redirect back with banner info instead of JSON
if ($ui) {
    $ok = count($failed) === 0 && count($generated) > 0;
    $firstId = $studentIds[0] ?? 0;
    if ($ok) {
        // Build a web path assuming /pdfArchive is web-served relative to this script
        $firstFs = $generated[0];
        $fileName = basename($firstFs);
        $webPath = 'pdfArchive/' . $fileName;
        $location = 'student_state.php?pdf_ok=1'
            . '&pdf_student_id=' . urlencode((string)$firstId)
            . '&pdf_file=' . rawurlencode($webPath);
        header('Location: ' . $location);
        exit;
    } else {
        $errMsg = $failed[0]['error'] ?? 'PDF generation failed.';
        $location = 'student_state.php?pdf_ok=0'
            . '&pdf_student_id=' . urlencode((string)$firstId)
            . '&pdf_error=' . rawurlencode($errMsg);
        header('Location: ' . $location);
        exit;
    }
}

header("Content-Type: application/json; charset=utf-8");
echo json_encode([
    "ok" => count($failed) === 0,
    "generated_count" => count($generated),
    "generated" => $generated,
    "failed" => $failed
], JSON_PRETTY_PRINT);