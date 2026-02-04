<?php
require "db_connect.php";
require_once "matching_engine.php"; // uses createNotificationOnce + helpers above

$CUTOFF_DAY = 10;
$LATEFEE_AMOUNT = 500;

$today = date('Y-m-d');
$day   = (int)date('j');

if ($day <= $CUTOFF_DAY) {
    exit("SKIP: day <= $CUTOFF_DAY\n");
}

$monthKey = date('Y-m');
$desc = "Late fee $monthKey: +{$LATEFEE_AMOUNT} LEK (no payment for this month)";

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
if (!$res) exit("DB_ERROR: " . $conn->error . "\n");

$created = 0;

while ($row = $res->fetch_assoc()) {
    $sid = (int)$row['student_id'];

    // Dedupe: once per student+month+type
    if (notificationExistsExact($conn, $sid, "urgent", $desc)) continue;

    $ref = getStudentReferenceOrFallback($conn, $sid);

    $ok = createNotificationOnce(
        $conn,
        "urgent",
        $sid,
        $ref,
        $today,
        $desc
    );

    if ($ok) {
        // Apply +500 once per month for "no payment"
        $conn->query("UPDATE STUDENT_TAB
                      SET additional_payments_status = COALESCE(additional_payments_status,0) + " . (int)$LATEFEE_AMOUNT . "
                      WHERE id = " . (int)$sid);
        $created++;
    }
}

echo "OK created=$created month=$monthKey\n";
