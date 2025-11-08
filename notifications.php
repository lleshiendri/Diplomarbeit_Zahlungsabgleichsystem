<?php
require "auth_check.php";
require "navigator.php";
require "db_connect.php";

$result = $conn->query("
    SELECT id, type, text, created_at
    FROM NOTIFICATIONS
    ORDER BY created_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Notifications</title>

<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700&family=Roboto:wght@400;500&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

<style>
/* ============================ */
/* = EXACT STUDENT STATE STYLE = */
/* ============================ */

body { font-family:'Roboto', sans-serif; }

.content {
    transition:margin-left 0.3s ease;
    margin-left:0;
    padding:100px 30px 60px;
}

.page-title {
    font-family:'Space Grotesk', sans-serif;
    font-weight:700;
    color:#B31E32;
    text-align:center;
    margin-bottom:20px;
    font-size:28px;
}

/* === TABLE DESIGN – IDENTICAL TO STUDENT STATE === */

.notification-table {
    width:100%;
    border-collapse:collapse;
}

/* ✅ Header EXACT like Student State */
.notification-table th {
    font-family:'Montserrat', sans-serif;
    font-weight:600;
    color:#B31E32;
    background-color:#FAE4D5;
    text-align:center;
    padding:14px 12px;
    border-bottom:1px solid #F2E8DC;
    font-size:16px;
}

/* ✅ Table cells EXACT like Student State */
.notification-table td {
    font-family:'Roboto', sans-serif;
    color:#222;
    vertical-align:middle;
    text-align:center;
    padding:14px 12px;
    border-bottom:1px solid #F2E8DC;
    font-size:15px;
}

/* ✅ Row striping (1:1 Student State) */
.notification-table tbody tr:nth-child(odd) { background-color:#FFFFFF; }
.notification-table tbody tr:nth-child(even){ background-color:#FFF8EB; }

/* ✅ Icon colors mimicking STUDENT STATE */
.icon { font-size:22px; vertical-align:middle; }
.icon.warning { color:#D77F00; }
.icon.success { color:#1A8E3A; }
.icon.info { color:#555; }

.type-warning { color:#D77F00; font-weight:600; }
.type-success { color:#1A8E3A; font-weight:600; }
.type-info { color:#555; font-weight:600; }

</style>
</head>

<body>

<div class="content">
    <h1 class="page-title">NOTIFICATIONS</h1>

    <table class="notification-table">
        <thead>
            <tr>
                <th>Icon</th>
                <th>Message</th>
                <th>Type</th>
                <th>Timestamp</th>
            </tr>
        </thead>

        <tbody>
        <?php
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {

                // 100% Student State icon style
                if ($row["type"] === "warning") {
                    $icon = '<span class="icon warning material-icons-outlined">warning</span>';
                } elseif ($row["type"] === "success") {
                    $icon = '<span class="icon success material-icons-outlined">check_circle</span>';
                } else {
                    $icon = '<span class="icon info material-icons-outlined">info</span>';
                }

                echo "
                <tr>
                    <td>$icon</td>
                    <td>" . htmlspecialchars($row["text"]) . "</td>
                    <td class='type-" . $row["type"] . "'>" . ucfirst($row["type"]) . "</td>
                    <td>" . htmlspecialchars($row["created_at"]) . "</td>
                </tr>";
            }
        } else {
            echo '<tr><td colspan="4" style="padding:20px; color:#777;">No notifications found</td></tr>';
        }
        ?>
        </tbody>
    </table>
</div>

</body>
</html>
