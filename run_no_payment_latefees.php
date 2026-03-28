<?php
require __DIR__ . '/db_connect.php';
require_once __DIR__ . '/matching_functions.php';

// Monthly "no payment" fee: no invoice split — only additional_payments_status (invoice_id = 0 in helper).
// createNotificationOnce / September / full-year-paid rules still apply.

$CUTOFF_DAY = 10;

$today = date('Y-m-d');
$day   = (int)date('j');

if ($day <= $CUTOFF_DAY) {
    exit("SKIP: day <= $CUTOFF_DAY\n");
}

$monthKey = date('Y-m');
$feeAmt = (float)LATE_FEE_AMOUNT_LEK;
$desc = "Late fee $monthKey: +{$feeAmt} LEK (no payment for this month)";

$sql = "
    SELECT s.id AS student_id
    FROM STUDENT_TAB s
    LEFT JOIN (
        SELECT student_id
        FROM INVOICE_TAB
        WHERE processing_date IS NOT NULL
          AND student_id IS NOT NULL
          AND YEAR(processing_date) = YEAR(CURDATE())
          AND MONTH(processing_date) = MONTH(CURDATE())
        GROUP BY student_id
    ) p ON p.student_id = s.id
    WHERE p.student_id IS NULL
";

$res = $conn->query($sql);
if (!$res) {
    exit("DB_ERROR: " . $conn->error . "\n");
}

$created = 0;

while ($row = $res->fetch_assoc()) {
    $sid = (int)$row['student_id'];

    if (notificationExistsExact($conn, $sid, 'urgent', $desc)) {
        continue;
    }

    if (isSeptemberDate($today)) {
        continue;
    }
    if (hasPaidFullYearAmount($conn, $sid, $today)) {
        continue;
    }

    $ref = getStudentReferenceOrFallback($conn, $sid);

    $conn->begin_transaction();
    $feeOk = applyLateFeeToStudentBalances($conn, $sid, 0, $feeAmt);
    if (!$feeOk) {
        $conn->rollback();
        continue;
    }
    $ok = createNotificationOnce(
        $conn,
        'urgent',
        $sid,
        $ref,
        $today,
        $desc
    );
    if ($ok) {
        $conn->commit();
        $created++;
    } else {
        $conn->rollback();
    }
}

echo "OK created=$created month=$monthKey\n";
