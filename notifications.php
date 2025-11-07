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

<style> 
/* === Layout === */
.content-container {
    padding: 40px;
    margin-left: var(--sidebar-width, 260px);
    font-family: 'Montserrat', sans-serif;

    display: flex;
    justify-content: center;
    margin-top: 60px;
}

/* === TABLE (ECKIGE KANTEN!) === */
.notifications-table {
    width: 95%;
    max-width: 1300px;
    border-collapse: collapse;

    background: white; /* Off-white wie Student State */

    border: 1px solid lightgrey; /* der helle beige Rahmen */
}

/* === TABLE HEADER (Student-State-Farbcode) === */
.notifications-table thead {
    background: #F7DECF; /* Genau dieses Beige vom Screenshot */
}

.notifications-table th {
    padding: 18px 16px;
    font-size: 16px;
    font-weight: 700;
    text-align: left;

    color: #B31E32; /* Dunkelrot wie dein Titel im Screenshot */
    border-bottom: 1px solid lightgrey;
}

/* === TABLE BODY === */
.notifications-table td {
    padding: 16px 16px;
    border-bottom: 1px solid lightgrey; /* gleiche Linien wie Student State */
    font-size: 15px;
    color: #333;
}

/* === Row Hover (leicht beige/rosa wie beim Student State) === */
/*.notifications-table tbody tr:hover {
    background: #FAE4D5;
    transition: 0.15s;
}*/

/* === EMPTY ROW === */
.empty {
    text-align: center;
    padding: 30px 0;
    font-style: italic;
}

/* === ICON COLORS (angepasst an Student State Stil) === */
.icon {
    font-size: 20px;
}

.icon.warning { color: #D77F00; }  /* Student State-style yellow/orange */
.icon.success { color: #1A8E3A; }  /* Student State green */
.icon.info    { color: #555; }

/* === TYPE COLORS === */
.type-warning { color: #D77F00; font-weight: 600; }
.type-success { color: #1A8E3A; font-weight: 600; }
.type-info    { color: #555; font-weight: 600; }

</style>
</head>

<body>

<div class="content-container">
    <table class="notifications-table">
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

                    // Icon abhängig vom Typ
                    $icon = '';
                    if ($row["type"] === "warning") {
                        $icon = '<span class="icon warning">⚠️</span>';
                    } else if ($row["type"] === "success") {
                        $icon = '<span class="icon success">✅</span>';
                    } else {
                        $icon = '<span class="icon info">ℹ️</span>';
                    }

                    echo "
                    <tr>
                        <td>$icon</td>
                        <td>" . htmlspecialchars($row["text"]) . "</td>
                        <td class='type-".$row["type"]."'>" . ucfirst($row["type"]) . "</td>
                        <td>" . $row["created_at"] . "</td>
                    </tr>";
                }
            } else {
                echo "
                <tr>
                    <td colspan='4' class='empty'>No notifications available.</td>
                </tr>";
            }
            ?>
        </tbody>
    </table>

</div>

</body>
</html>