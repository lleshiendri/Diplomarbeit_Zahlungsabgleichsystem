<?php 
require_once 'auth_check.php';
require "navigator.php";
require "db_connect.php";
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

        // MAIN QUERY
        $sql = "
        SELECT
            s.id AS student_id,
            s.long_name,
            MAX(i.processing_date) AS last_transaction,
            NOW() AS last_import,
            DATEDIFF(CURDATE(), MAX(i.processing_date)) AS days_since_payment
        FROM STUDENT_TAB s
        LEFT JOIN LEGAL_GUARDIAN_STUDENT_TAB lgs 
            ON lgs.student_id = s.id
        LEFT JOIN INVOICE_TAB i 
            ON i.legal_guardian_id = lgs.legal_guardian_id
        GROUP BY s.id
        ORDER BY s.long_name ASC
        LIMIT $perPage OFFSET $offset
        ";

        $result = $conn->query($sql);

        if ($result) {
            while ($row = $result->fetch_assoc()) {

                $lateText = "—";
                $lateClass = "txt-orange";

                if (!is_null($row['last_transaction'])) {
                    $days = (int)$row['days_since_payment'];

                    if ($days <= 5) {
                        $lateClass = "txt-green";
                    } elseif ($days <= 10) {
                        $lateClass = "txt-orange";
                    } else {
                        $lateClass = "txt-red";
                    }

                    $lateText = $days . " DAYS";
                }

                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['long_name']) . "</td>";
                echo "<td>" . ($row['last_transaction'] ?: '—') . "</td>";
                echo "<td>" . ($row['last_import'] ?: '—') . "</td>";
                echo "<td class='days-late $lateClass'>$lateText</td>";
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
