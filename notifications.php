<?php
require "auth_check.php";
require "db_connect.php";

/* ===============================================================
   1) MARK AS READ (SINGLE)
   =============================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['read_id'])) {
    $id = intval($_POST['read_id']);
    $conn->query("UPDATE NOTIFICATION SET is_read = 1 WHERE id = $id");
    echo "OK";
    exit;
}

/* ===============================================================
   2) MARK MULTIPLE AS READ
   =============================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['mark_many'])) {

    $ids = json_decode($_POST['mark_many'], true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($ids) || count($ids) === 0) {
        http_response_code(400);
        echo "ERROR";
        exit;
    }

    foreach ($ids as $id) {
        $id = intval($id);
        if ($id > 0) {
            $conn->query("UPDATE NOTIFICATION SET is_read = 1 WHERE id = $id");
        }
    }

    echo "OK";
    exit;
}

/* ===============================================================
   NAVIGATOR
   =============================================================== */
require "navigator.php";

/* ===============================================================
   3) SEARCH + PAGINATION
   =============================================================== */
$search = $_GET["search"] ?? "";
$searchSql = "";

if ($search !== "") {
    $safe = $conn->real_escape_string($search);
    $searchSql = "WHERE 
        urgency LIKE '%$safe%' OR
        student_id LIKE '%$safe%' OR
        description LIKE '%$safe%' OR
        invoice_id LIKE '%$safe%' OR
        time_from LIKE '%$safe%'
    ";
}

$perPage = 10;
$page = isset($_GET["page"]) ? max(1, intval($_GET["page"])) : 1;
$offset = ($page - 1) * $perPage;

$countResult = $conn->query("SELECT COUNT(*) as total FROM NOTIFICATION $searchSql");
$totalRows = $countResult->fetch_assoc()["total"];
$totalPages = ceil($totalRows / $perPage);

$result = $conn->query("
    SELECT id, urgency, student_id, description, invoice_id, time_from, is_read
    FROM NOTIFICATION
    $searchSql
    ORDER BY time_from DESC
    LIMIT $perPage OFFSET $offset
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

:root{
    --red-dark:#B31E32;
    --red-main:#D4463B;
    --red-light:#FAE4D5;
    --off-white:#FFF8EB;
    --gray-light:#E3E5E0;
    --sidebar-w:260px;
}

body { 
    font-family:'Roboto', sans-serif;  
    margin:0;
    background:#FFFFFF;
}

#content {
    transition: margin-left 0.3s ease;
    margin-left: 0;
    padding: 100px 40px 40px;
}

#content.shifted {
    margin-left: 260px;
}

#overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.4);
    display: none;
    z-index: 98;
}
#overlay.show { display:block; }

.page-title {
    font-family:'Space Grotesk';
    font-weight:700;
    color:#B31E32;
    font-size:28px;
    margin-bottom:20px;
    margin-top:5px;
}

.search-wrapper{
    display:flex;
    align-items:center;
    width:330px;
    background:#F4F4F4;
    border-radius:12px;
    border:1px solid #D1D1D1;
    padding:4px 10px;
    margin-bottom:25px;
    box-shadow:0 2px 6px rgba(0,0,0,0.08);
}

.notification-table {
    width:100%;
    border-collapse:collapse;
    background:white;
    border-radius:12px;
    overflow:hidden;
    box-shadow:0 6px 20px rgba(0,0,0,0.10);
}

.notification-table th {
    background:#FAE4D5;
    color:#B31E32;
    font-family:'Montserrat';
    font-weight:600;
    padding:14px;
    font-size: 15px;
}

.notification-table td {
    padding:14px;
    border-bottom:1px solid #EEE;
    font-size: 15px;
    text-align: center;
}

.row-urgent { background:#FDE7E9 !important; }
.row-warning { background:#FFF4E0 !important; }
.row-info { background:#F3F3F3 !important; }

.read-row { opacity:0.55; }

.urgency-badge {
    display:flex;
    align-items:center;
    gap:6px;
    font-family:'Roboto';
    font-weight:500;
}
.urgent { color:#B31E32; }
.warning { color:#D77F00; }
.info { color:#555; }

</style>
</head>

<body>
<div id="overlay"></div>
<main id="content">

<h1 class="page-title">NOTIFICATIONS</h1>

<form method="GET">
<div class="search-wrapper">
    <span class="material-icons-outlined" style="color:#777;">search</span>
    <input type="text" name="search" placeholder="Search…" 
           value="<?= htmlspecialchars($search) ?>" 
           style="flex:1; border:none; background:transparent; outline:none; font-family:'Montserrat'; font-size: 13px;">
</div>
</form>

<table class="notification-table">

<thead>
<tr>
    <th>Description</th>
    <th>Student ID</th>
    <th>Invoice ID</th>
    <th>Urgency</th>
    <th>Timestamp</th>
    <th style="width:140px;">Mark as read</th>
</tr>
</thead>

<tbody>
<?php while ($row = $result->fetch_assoc()): 
    $isRead = intval($row["is_read"]) === 1;

    if ($row["urgency"] === "urgent") {
        $urgencyRowClass = "row-urgent";
        $icon = "error";
        $badgeClass = "urgent";
        $badgeText = "Critical";
    } elseif ($row["urgency"] === "warning") {
        $urgencyRowClass = "row-warning";
        $icon = "warning";
        $badgeClass = "warning";
        $badgeText = "Warning";
    } else {
        $urgencyRowClass = "row-info";
        $icon = "info";
        $badgeClass = "info";
        $badgeText = "Info";
    }
?>
<tr id="row_<?= $row['id'] ?>" class="<?= $urgencyRowClass ?> <?= $isRead ? 'read-row' : '' ?>">

    <td><?= nl2br(htmlspecialchars($row["description"])) ?></td>
    <td><?= htmlspecialchars($row["student_id"]) ?></td>
    <td><?= htmlspecialchars($row["invoice_id"]) ?></td>

    <td>
        <div class="urgency-badge <?= $badgeClass ?>">
            <span class="material-icons-outlined"><?= $icon ?></span>
            <?= $badgeText ?>
        </div>
    </td>

    <td><?= htmlspecialchars($row["time_from"]) ?></td>

    <td>
        <input type="checkbox" class="bulk-check" value="<?= $row['id'] ?>"
               style="width:16px; height:16px; cursor:pointer;">
    </td>

</tr>
<?php endwhile; ?>
</tbody>

</table>

<div style="margin-top:25px; display:flex; justify-content:flex-end;">
    <button type="button" onclick="markAllSelected()"
            style="background:#B31E32; border:none; padding:8px 15px; border-radius:8px; 
                   color:white; font-family:'Montserrat'; font-weight:600; cursor:pointer; font-size:13px;">
        Mark all selected as read
    </button>
</div>

</main>

<script>

const sidebar = document.getElementById("sidebar");
const content = document.getElementById("content");
const overlay = document.getElementById("overlay");

function openSidebar(){
    sidebar.classList.add("open");
    content.classList.add("shifted");
    overlay.classList.add("show");
}
function closeSidebar(){
    sidebar.classList.remove("open");
    content.classList.remove("shifted");
    overlay.classList.remove("show");
}
function toggleSidebar(){
    sidebar.classList.contains("open") ? closeSidebar() : openSidebar();
}

/* ============================================================
   MARK ALL SELECTED
   ============================================================ */
function markAllSelected() {

    let checked = document.querySelectorAll(".bulk-check:checked");
    if (checked.length === 0) {
        alert("No notifications selected.");
        return;
    }

    if (!confirm("The selected notifications will be marked as read.\nContinue?"))
        return;

    let ids = [];
    checked.forEach(c => ids.push(c.value));

    const formData = new URLSearchParams();
    formData.append('mark_many', JSON.stringify(ids));

    fetch("notifications.php", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: formData.toString()
    })
    .then(r => r.text())
    .then(resp => {
        if (resp.trim() === "OK") {
            alert("The selected notifications have been marked as read.");
            window.location.reload();
        } else {
            alert("Error: " + resp);
        }
    });
}

</script>

</body>
</html>
