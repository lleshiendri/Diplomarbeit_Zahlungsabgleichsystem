<?php 
require_once 'auth_check.php';
require "navigator.php";
require "db_connect.php";
if (!function_exists('maybeCreateLateFeeUrgent')) {
    require_once __DIR__ . '/matching_functions.php';
}

// Ensure NOTIFICATION_TAB has an "urgent" row for every late payment this month (deadline 10th).
// So the notifications page can show "send email" for each late transaction.
$lateInvoices = $conn->query("
    SELECT student_id, reference_number, processing_date
    FROM INVOICE_TAB
    WHERE student_id IS NOT NULL
      AND YEAR(processing_date) = YEAR(CURDATE())
      AND MONTH(processing_date) = MONTH(CURDATE())
      AND DATE(processing_date) > STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(), '%Y-%m'), '-10'), '%Y-%m-%d')
");
if ($lateInvoices) {
    while ($row = $lateInvoices->fetch_assoc()) {
        $ref = isset($row['reference_number']) ? trim((string)$row['reference_number']) : '';
        if ($ref !== '') {
            maybeCreateLateFeeUrgent($conn, (int)$row['student_id'], $ref, $row['processing_date']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Latencies</title>

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

    <style>
        body {
            font-family: 'Roboto', sans-serif;
            margin: 0;
            background: white;
        }

        #content {
            transition: margin-left 0.3s ease;
        }

        #content.shifted {
            margin-left: 260px;
        }

        .content {
            padding: 120px 40px 40px;
        }

        h1 {
            text-align: center;
            font-family: 'Montserrat', sans-serif;
            font-size: 32px;
            font-weight: 600;
            color: #B31E32;
            margin-bottom: 40px;
        }

        .lat-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }

        .lat-table th {
            background: #FAE4D5;
            padding: 16px;
            text-align: left;
            font-family: 'Montserrat', sans-serif;
            font-weight: 600;
            font-size: 15px;
            color: #B31E32;
        }

        .lat-table td {
            padding: 16px;
            border-bottom: 1px solid #E3E5E0;
        }

        .lat-table tr:last-child td {
            border-bottom: none;
        }

        /* Days Late column aligned center */
        .lat-table td.days-late {
            text-align: center;
            font-weight: 600;
            font-size: 15px;
        }

        /* Colors for late status */
        .txt-green { color: #4CAF50; }
        .txt-orange { color: #FF9800; }
        .txt-red { color: #F44336; }
        .txt-gray { color: gray;}
    </style>
</head>

<body>

<div id="content" class="content">
    <h1>Latencies</h1>

    <table class="lat-table">
        <thead>
            <tr>
                <th>Student Name</th>
                <th>Last Transaction Date</th>
                <th>Last Import Date</th>
                <th>Days Late</th>
            </tr>
        </thead>

        <tbody>
        <?php
        // Pagination
        $perPage = 10;
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $offset = ($page - 1) * $perPage;

        $countResult = $conn->query("SELECT COUNT(*) AS total FROM STUDENT_TAB");
        $totalStudents = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
        $totalPages = max(1, (int)ceil($totalStudents / $perPage));

        /*
          MAIN QUERY
          - last_transaction: MAX(processing_date) across ALL confirmed invoices (any month)
          - this_month_count: number of confirmed invoices in current month
          - first_payment_this_month: MIN(processing_date) in current month
          - days_late: late days ONLY if first_payment_this_month > deadline (10th)
        */
        $sql = "
            SELECT
                s.id AS student_id,
                s.long_name,
                lt.last_transaction,
                NOW() AS last_import,
                COALESCE(cm.this_month_count, 0) AS this_month_count,
                cm.first_payment_this_month,
                COALESCE(cm.days_late, 0) AS days_late
            FROM STUDENT_TAB s
            LEFT JOIN (
                SELECT student_id, MAX(processing_date) AS last_transaction
                FROM INVOICE_TAB
                WHERE student_id IS NOT NULL
                GROUP BY student_id
            ) lt ON lt.student_id = s.id
            LEFT JOIN (
                SELECT
                    student_id,
                    COUNT(*) AS this_month_count,
                    MIN(processing_date) AS first_payment_this_month,
                    CASE
                        WHEN MIN(processing_date) IS NULL THEN 0
                        WHEN DATE(MIN(processing_date)) <= STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(), '%Y-%m'), '-10'), '%Y-%m-%d') THEN 0
                        ELSE DATEDIFF(
                            DATE(MIN(processing_date)),
                            STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(), '%Y-%m'), '-10'), '%Y-%m-%d')
                        )
                    END AS days_late
                FROM INVOICE_TAB
                WHERE student_id IS NOT NULL
                  AND YEAR(processing_date) = YEAR(CURDATE())
                  AND MONTH(processing_date) = MONTH(CURDATE())
                GROUP BY student_id
            ) cm ON cm.student_id = s.id
            ORDER BY s.long_name ASC
            LIMIT $perPage OFFSET $offset
        ";

        $result = $conn->query($sql);

        if ($result) {
            while ($row = $result->fetch_assoc()) {

                $thisMonthCount = isset($row['this_month_count']) ? (int)$row['this_month_count'] : 0;
                $daysLate = isset($row['days_late']) ? (int)$row['days_late'] : 0;
                $lastTransaction = $row['last_transaction'] ?? null;

                /*
                  New display logic for "Days Late":
                  - No payment this month but has older last_transaction => "No payment this month"
                  - Paid on time this month:
                      - 1 payment => "Paid on time"
                      - >1 payments => "Paid on time (paid X times)"
                  - Late payment this month => "X DAYS"
                */

                $lateClass = "txt-orange";
                $lateText = "—";

                if ($thisMonthCount === 0) {
                    // No payment in current month
                    if ($lastTransaction) {
                        $lateClass = "txt-gray";
                        $lateText = "No payment this month";
                    } else {
                        $lateClass = "txt-gray";
                        $lateText = "—";
                    }
                } else {
                    // At least one payment this month
                    if ($daysLate > 0) {
                        // Late
                        if ($daysLate <= 10) {
                            $lateClass = "txt-orange";
                        } else {
                            $lateClass = "txt-red";
                        }
                        $lateText = $daysLate . " DAYS";
                    } else {
                        // On time (first payment <= 10th)
                        $lateClass = "txt-green";
                        if ($thisMonthCount === 1) {
                            $lateText = "Paid on time";
                        } else {
                            $lateText = "Paid on time (paid $thisMonthCount times)";
                        }
                    }
                }

                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['long_name']) . "</td>";
                echo "<td>" . ($lastTransaction ? date('Y-m-d', strtotime($lastTransaction)) : '—') . "</td>";
                echo "<td>" . ($row['last_import'] ? date('Y-m-d H:i:s', strtotime($row['last_import'])) : '—') . "</td>";
                echo "<td class='days-late $lateClass'>" . htmlspecialchars($lateText) . "</td>";
                echo "</tr>";
            }
        }
        ?>
        </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="text-align: center; margin-top: 30px; font-family: 'Roboto', sans-serif;">
        <?php
        $queryParams = $_GET;

        $prevPage = max(1, $page - 1);
        $nextPage = min($totalPages, $page + 1);

        $queryParams['page'] = $prevPage;
        $prevUrl = '?' . http_build_query($queryParams);
        $queryParams['page'] = $nextPage;
        $nextUrl = '?' . http_build_query($queryParams);
        ?>
        
        <?php if ($page > 1): ?>
            <a href="<?= htmlspecialchars($prevUrl) ?>" style="text-decoration: none; color: #B31E32; margin-right: 20px; font-weight: 500;">« Prev</a>
        <?php else: ?>
            <span style="color: #999; margin-right: 20px;">« Prev</span>
        <?php endif; ?>

        <span style="color: #333; margin: 0 20px;">Page <?= $page ?> of <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
            <a href="<?= htmlspecialchars($nextUrl) ?>" style="text-decoration: none; color: #B31E32; margin-left: 20px; font-weight: 500;">Next »</a>
        <?php else: ?>
            <span style="color: #999; margin-left: 20px;">Next »</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</body>
</html>
