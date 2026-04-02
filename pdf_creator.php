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
   HELPER: Load base student info
   ============================================================ */
function loadStudentBaseInfo(mysqli $conn, int $studentId): ?array {
    $sql = "SELECT id, extern_key, long_name, name, amount_paid, left_to_pay, additional_payments_status
            FROM STUDENT_TAB WHERE id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

/* ============================================================
   HELPER: Load payment history from MATCHING_HISTORY_TAB
   ============================================================ */
function loadPaymentHistory(mysqli $conn, int $studentId): array {
    $sql = "
        SELECT
            mh.id AS mh_id,
            mh.student_id,
            mh.matched_by,
            mh.is_confirmed,
            mh.created_at,

            i.id AS invoice_id,
            i.processing_date,
            i.reference_number,
            i.beneficiary,
            i.reference,
            i.description,
            i.amount_total
        FROM MATCHING_HISTORY_TAB mh
        JOIN INVOICE_TAB i ON i.id = mh.invoice_id
        WHERE mh.student_id = ? AND mh.is_confirmed = 1
        ORDER BY i.processing_date DESC, mh.created_at DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if ($row) $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/* ============================================================
   HELPER: Resolve matched share amount for a payment row
   ============================================================ */
function resolveShareAmount(?array $row): float {
    if (!$row) return 0.0;
    return (float)($row['amount_total'] ?? 0.0);
}

/* ============================================================
   HELPER: Calculate financial summary for a student
   ============================================================ */
function calculateFinancialSummary(?array $baseInfo, array $paymentHistory): array {
    return [
        'total_paid'          => (float)($baseInfo['amount_paid'] ?? 0.0),
        'additional_payments' => (float)($baseInfo['additional_payments_status'] ?? 0.0),
        'left_to_pay'         => (float)($baseInfo['left_to_pay'] ?? 0.0),
    ];
}

/* ============================================================
   HELPER: Render PDF document header and title
   ============================================================ */
function renderPDFHeader(FPDF &$pdf, ?array $studentInfo) {
    $pdf->SetFont('Arial', 'B', 22);
    $pdf->SetTextColor(50, 50, 50);
    $pdf->Cell(0, 14, 'Student Payment Report', 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 6, 'Generated: ' . date('d.m.Y H:i'), 0, 1, 'C');
    $pdf->Ln(6);
}

/* ============================================================
   HELPER: Render student information block
   ============================================================ */
function renderPDFStudentInfo(FPDF &$pdf, ?array $studentInfo) {
    if (!$studentInfo) return;
    
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->Cell(0, 8, 'Student Information', 0, 1, 'L');
    
    $pdf->SetDrawColor(220, 220, 220);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(3);
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    
    // Student ID
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(40, 6, 'Student ID:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, (string)($studentInfo['id'] ?? '-'), 0, 1);
    
    // Student Name
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(40, 6, 'Name:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, (string)($studentInfo['long_name'] ?? '-'), 0, 1);
    
    // Extern Key
    if (!empty($studentInfo['extern_key'])) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(40, 6, 'External ID:', 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, (string)$studentInfo['extern_key'], 0, 1);
    }
    
    $pdf->Ln(4);
}

/* ============================================================
   HELPER: Render financial summary block
   ============================================================ */
function renderPDFFinancialSummary(FPDF &$pdf, array $summary) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->Cell(0, 8, 'Financial Summary', 0, 1, 'L');
    
    $pdf->SetDrawColor(220, 220, 220);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(3);
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    
    // Total Paid
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(60, 6, 'Total Amount Paid:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, money_lek($summary['total_paid']), 0, 1);
    
    // Additional Payments
    if ($summary['additional_payments'] > 0.001) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(60, 6, 'Additional Payments:', 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, money_lek($summary['additional_payments']), 0, 1);
    }
    
    // Left to Pay (highlighted if > 0)
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(60, 6, 'Amount Left to Pay:', 0, 0);
    if ($summary['left_to_pay'] > 0.001) {
        $pdf->SetTextColor(179, 30, 50);
    }
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, money_lek($summary['left_to_pay']), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    
    // TODO: Add overdue_amount if derivable from schema
    // TODO: Add next_deadline_outstanding if derivable from schema
    
    $pdf->Ln(4);
}

/* ============================================================
   HELPER: Render payment history table
   ============================================================ */
function renderPDFPaymentHistory(FPDF &$pdf, array $paymentHistory) {
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->Cell(0, 8, 'Payment History', 0, 1, 'L');
    $pdf->SetDrawColor(220, 220, 220);
    $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
    $pdf->Ln(3);

    if (count($paymentHistory) === 0) {
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, 'No confirmed payments found.', 0, 1);
        return;
    }

    // Total table width = 190 (matches line from x=10 to x=200)
    $colWidths = [28, 52, 75, 35]; // sum = 190

    // ── Header ───────────────────────────────────────────────────────────────
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(46, 64, 87);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(255, 255, 255);

    $pdf->Cell($colWidths[0], 8, 'Date', 1, 0, 'C', true);
    $pdf->Cell($colWidths[1], 8, 'Beneficiary', 1, 0, 'C', true);
    $pdf->Cell($colWidths[2], 8, 'Description', 1, 0, 'C', true);
    $pdf->Cell($colWidths[3], 8, 'Amount', 1, 1, 'C', true);

    // ── Rows ─────────────────────────────────────────────────────────────────
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetDrawColor(220, 220, 220);
    $lineHeight = 5.5;
    $maxLines = 3;
    $even = false;

    foreach ($paymentHistory as $row) {
        $date = fmt_date_or_dash($row['processing_date'] ?? null);
        $beneficiary = (string)($row['beneficiary'] ?? '-');
        $description = (string)($row['description'] ?? '-');
        $amount = money_lek(resolveShareAmount($row));

        // Calculate how many lines each text cell needs
        $pdf->SetFont('Arial', '', 9);
        $linesB = max(1, ceil($pdf->GetStringWidth($beneficiary) / ($colWidths[1] - 2)));
        $linesD = max(1, ceil($pdf->GetStringWidth($description) / ($colWidths[2] - 2)));

        // Cap at 3 lines, truncate text if it would exceed that
        $linesB = min($linesB, $maxLines);
        $linesD = min($linesD, $maxLines);

        $maxCharsB = (int)(($colWidths[1] - 2) / ($pdf->GetStringWidth('A') ?: 2.2)) * $maxLines;
        $maxCharsD = (int)(($colWidths[2] - 2) / ($pdf->GetStringWidth('A') ?: 2.2)) * $maxLines;

        if (mb_strlen($beneficiary) > $maxCharsB) {
            $beneficiary = mb_substr($beneficiary, 0, $maxCharsB - 3) . '...';
        }
        if (mb_strlen($description) > $maxCharsD) {
            $description = mb_substr($description, 0, $maxCharsD - 3) . '...';
        }

        // Row height = tallest cell
        $rowLines = max($linesB, $linesD, 1);
        $rowHeight = $rowLines * $lineHeight;

        // Alternating background
        $fill = $even ? [245, 247, 250] : [255, 255, 255];
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->SetTextColor(40, 40, 40);

        $x = $pdf->GetX();
        $y = $pdf->GetY();

        // Check page break manually before drawing row
        if ($y + $rowHeight > $pdf->GetPageHeight() - 15) {
            $pdf->AddPage();
            $y = $pdf->GetY();
        }

        // Date (single line, vertically centred)
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($colWidths[0], $rowHeight, $date, 1, 'C', true);

        // Beneficiary (multi-line)
        $pdf->SetXY($x + $colWidths[0], $y);
        $pdf->MultiCell($colWidths[1], $lineHeight, $beneficiary, 1, 'L', true);
        $usedB = $pdf->GetY() - $y;
        if ($usedB < $rowHeight) {
            $pdf->SetXY($x + $colWidths[0], $y + $usedB);
            $pdf->Cell($colWidths[1], $rowHeight - $usedB, '', 'LR', 0, 'L', true);
        }

        // Description (multi-line)
        $pdf->SetXY($x + $colWidths[0] + $colWidths[1], $y);
        $pdf->MultiCell($colWidths[2], $lineHeight, $description, 1, 'L', true);
        $usedD = $pdf->GetY() - $y;
        if ($usedD < $rowHeight) {
            $pdf->SetXY($x + $colWidths[0] + $colWidths[1], $y + $usedD);
            $pdf->Cell($colWidths[2], $rowHeight - $usedD, '', 'LR', 0, 'L', true);
        }

        // Amount (single line, vertically centred, green)
        $pdf->SetXY($x + $colWidths[0] + $colWidths[1] + $colWidths[2], $y);
        $pdf->SetTextColor(30, 140, 80);
        $pdf->MultiCell($colWidths[3], $rowHeight, $amount, 1, 'R', true);

        // Bottom border line across full row
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Line($x, $y + $rowHeight, $x + 190, $y + $rowHeight);

        $pdf->SetXY($x, $y + $rowHeight);
        $pdf->SetTextColor(40, 40, 40);
        $even = !$even;
    }

    $pdf->Ln(4);
}

/* ============================================================
   HELPER: Render introductory statement (appears after header)
   ============================================================ */
function renderPDFIntroText(FPDF &$pdf) {
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(60, 60, 60);
    
    $text = "This payment report summarizes all payments currently recorded in our system for the above-mentioned student, together with the present outstanding balance."
            ." We kindly ask you to review the information provided in this document carefully. If you notice that a payment is missing, has been allocated incorrectly, or if any of the listed information requires clarification, please contact the school administration at your earliest convenience."
            ." Where an outstanding balance is shown, we kindly request that the remaining amount be paid within the relevant payment period. Thank you for your attention and cooperation.";
    
    $pdf->MultiCell(0, 5, $text);
    $pdf->Ln(6);
}

/* ============================================================
   HELPER: Render formal footer / explanatory text (DEPRECATED: now shown at top)
   ============================================================ */
function renderPDFFormalFooter(FPDF &$pdf) {
    // This function is deprecated; statement is now rendered as introduction via renderPDFIntroText()
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
   PDF: one PDF per student, saved into /pdfArchive
   ============================================================ */
$generated = [];
$failed = [];

foreach ($studentIds as $studentId) {
    try {
        // Load student base info
        $studentBaseInfo = loadStudentBaseInfo($conn, $studentId);
        if (!$studentBaseInfo) {
            throw new Exception("Student not found: ID " . $studentId);
        }
        
        // Load payment history from MATCHING_HISTORY_TAB
        $paymentHistory = loadPaymentHistory($conn, $studentId);
        
        // Calculate financial summary
        $summary = calculateFinancialSummary($studentBaseInfo, $paymentHistory);
        
        // Create PDF
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        
        // Render sections
        renderPDFHeader($pdf, $studentBaseInfo);
        renderPDFIntroText($pdf);
        renderPDFStudentInfo($pdf, $studentBaseInfo);
        renderPDFFinancialSummary($pdf, $summary);
        
        // Payment history may span multiple pages
        renderPDFPaymentHistory($pdf, $paymentHistory);
        
        // Save file
        $fileBase = safe_filename("student_{$studentId}_" . ($studentBaseInfo['long_name'] ?? ''));
        if ($fileBase === '') $fileBase = "student_" . $studentId;
        
        $filePath = $ARCHIVE_DIR . "/report_" . $fileBase . "_" . date("Y-m") . ".pdf";
        $pdf->Output('F', $filePath);
        
        $generated[] = $filePath;
        
    } catch (Throwable $e) {
        $failed[] = [
            'student_id' => $studentId,
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