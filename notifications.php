<?php
declare(strict_types=1);

require "auth_check.php";
require "db_connect.php";

/* ===============================================================
   TABLE NAME (change here if needed)
   =============================================================== */
$table = "NOTIFICATION_TAB"; // <-- if your real table is NOTIFICATION, change it here

/* ===============================================================
   1) MARK AS READ (SINGLE)
   =============================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['read_id'])) {
    $id = (int)$_POST['read_id'];
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE $table SET is_read = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
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

    // Filter valid ints
    $clean = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) $clean[] = $id;
    }

    if (count($clean) === 0) {
        http_response_code(400);
        echo "ERROR";
        exit;
    }

    // Build IN (?, ?, ?)
    $placeholders = implode(",", array_fill(0, count($clean), "?"));
    $types = str_repeat("i", count($clean));

    $sql = "UPDATE $table SET is_read = 1 WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$clean);
    $stmt->execute();
    $stmt->close();

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
$search = trim($search);

$where = "";
$params = [];
$types = "";

if ($search !== "") {
    $like = "%" . $search . "%";
    $where = "WHERE urgency LIKE ? OR student_id LIKE ? OR description LIKE ? OR invoice_id LIKE ? OR time_from LIKE ? OR mail_status LIKE ?";
    $params = [$like, $like, $like, $like, $like, $like];
    $types = "ssssss";
}

$perPage = 10;
$page = isset($_GET["page"]) ? max(1, (int)$_GET["page"]) : 1;
$offset = ($page - 1) * $perPage;

/* ---- COUNT ---- */
$countSql = "SELECT COUNT(*) as total FROM $table $where";
$stmt = $conn->prepare($countSql);
if (!$stmt) {
    die("COUNT Prepare Error: " . $conn->error);
}
if ($where !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$countResult = $stmt->get_result();
if (!$countResult) {
    die("COUNT Result Error: " . $conn->error);
}
$totalRows = (int)$countResult->fetch_assoc()["total"];
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* ---- DATA ---- */
$dataSql = "
    SELECT id, urgency, student_id, description, invoice_id, time_from, mail_status, is_read
    FROM $table
    $where
    ORDER BY time_from DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($dataSql);
if (!$stmt) {
    die("DATA Prepare Error: " . $conn->error);
}

if ($where !== "") {
    // add limit/offset
    $types2 = $types . "ii";
    $params2 = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($types2, ...$params2);
} else {
    $stmt->bind_param("ii", $perPage, $offset);
}

$stmt->execute();
$result = $stmt->get_result();
if (!$result) {
    die("DATA Result Error: " . $conn->error);
}
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
#notifContent {
    padding: 100px 30px 60px;
    max-width: 1300px;
    margin: 0 auto;
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
    text-align:left;
}

/* Search box */
.search-wrapper{
    display:flex;
    align-items:center;
    width:330px;
    background:#F4F4F4;
    border-radius:12px;
    border:1px solid #D1D1D1;
    padding:4px 10px;
    margin: 0 0 25px 0;
    box-shadow:0 2px 6px rgba(0,0,0,0.08);
}

/* TABLE */
.notification-table {
    width:100%;
    max-width:1300px;
    margin:0 auto;
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
    text-align:center;
}
.notification-table td {
    padding:14px;
    border-bottom:1px solid #EEE;
    font-size: 15px;
    text-align: center;
}

/* ROW COLORS */
.row-urgent { background:#FDE7E9 !important; }
.row-warning { background:#FFF4E0 !important; }
.row-info { background:#F3F3F3 !important; }

/* read */
.read-row { opacity:0.55; }

/* urgency badge */
.urgency-badge {
    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    font-family:'Roboto';
    font-weight:500;
}
.urgent { color:#B31E32; }
.warning { color:#D77F00; }
.info { color:#555; }

.mark-read-wrapper {
    display:flex;
    justify-content:flex-end;
    max-width:1300px;
    margin:20px auto 0 auto;
}
.mark-read-button {
    background:#B31E32;
    border:none;
    padding:10px 18px;
    border-radius:8px;
    color:white;
    font-family:'Montserrat';
    font-weight:600;
    cursor:pointer;
    font-size:14px;
}
.mark-read-button:hover { background:#8d1727; }

/* pagination */
.pagination {
    display:flex;
    gap:8px;
    justify-content:center;
    margin-top:25px;
    font-family:'Montserrat';
}
.pagination a, .pagination span {
    padding:8px 12px;
    border-radius:8px;
    text-decoration:none;
    border:1px solid #ddd;
    color:#333;
}
.pagination .active {
    background: var(--red-dark);
    color:#fff;
    border-color: var(--red-dark);
}
</style>
</head>

<body>
<div id="overlay"></div>

<main id="notifContent">
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
    <th>Mail Status</th>
    <th style="width:160px;">Select</th>
</tr>
</thead>

<tbody>
<?php while ($row = $result->fetch_assoc()):
    $isRead = ((int)$row["is_read"] === 1);

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

    $mailStatus = $row["mail_status"] ?? "none";
?>
<tr id="row_<?= (int)$row['id'] ?>" class="<?= $urgencyRowClass ?> <?= $isRead ? 'read-row' : '' ?>">
    <td><?= nl2br(htmlspecialchars((string)$row["description"])) ?></td>
    <td><?= htmlspecialchars((string)$row["student_id"]) ?></td>
    <td><?= htmlspecialchars((string)$row["invoice_id"]) ?></td>

    <td>
        <div class="urgency-badge <?= $badgeClass ?>">
            <span class="material-icons-outlined"><?= $icon ?></span>
            <?= $badgeText ?>
        </div>
    </td>

    <td><?= htmlspecialchars((string)$row["time_from"]) ?></td>
    <td><?= htmlspecialchars((string)$mailStatus) ?></td>

    <td>
        <input type="checkbox" class="bulk-check" value="<?= (int)$row['id'] ?>"
               style="width:16px; height:16px; cursor:pointer;">
    </td>
</tr>
<?php endwhile; ?>
</tbody>
</table>

<div class="mark-read-wrapper">
    <button type="button" class="mark-read-button" onclick="markAllSelected()">
        Mark all selected as read
    </button>
</div>

<?php
// Pagination links
if ($totalPages > 1):
    $base = "?search=" . urlencode($search) . "&page=";
?>
<div class="pagination">
    <?php for ($p=1; $p <= $totalPages; $p++): ?>
        <?php if ($p === $page): ?>
            <span class="active"><?= $p ?></span>
        <?php else: ?>
            <a href="<?= $base . $p ?>"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

</main>

<script>
/* Fix variable conflicts caused by navigator.php */
const notifSidebar = document.getElementById("sidebar");
const notifOverlay = document.getElementById("overlay");
const notifContent = document.getElementById("notifContent");

function openSidebar(){
    if (!notifSidebar) return;
    notifSidebar.classList.add("open");
    notifContent.classList.add("shifted");
    notifOverlay.classList.add("show");
}
function closeSidebar(){
    if (!notifSidebar) return;
    notifSidebar.classList.remove("open");
    notifContent.classList.remove("shifted");
    notifOverlay.classList.remove("show");
}
function toggleSidebar(){
    if (!notifSidebar) return;
    notifSidebar.classList.contains("open") ? closeSidebar() : openSidebar();
}

/* MARK ALL SELECTED */
function markAllSelected() {
    let checked = document.querySelectorAll(".bulk-check:checked");
    if (checked.length === 0) {
        alert("No notifications selected.");
        return;
    }

    if (!confirm("The selected notifications will be marked as read.\nContinue?")) return;

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
            window.location.reload();
        } else {
            alert("Error: " + resp);
        }
    })
    .catch(err => alert("Request failed: " + err));
}
</script>

</body>
</html>
<?php
$stmt->close();
?>
