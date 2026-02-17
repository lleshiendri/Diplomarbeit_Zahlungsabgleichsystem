<?php
require "auth_check.php";
require "db_connect.php";

/* ===============================================================
   1) MARK MULTIPLE AS READ
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
            $conn->query("UPDATE NOTIFICATION_TAB SET is_read = 1 WHERE id = $id");
        }
    }

    echo "OK";
    exit;
}

/* ===============================================================
   1b) SEND EMAIL TO PARENT (urgent only)   <-- NEW
   =============================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['send_parent_email'])) {

// TEST MODE ONLY: bypass admin gate (REMOVE AFTER CONFIRMING EMAIL WORKS)
if (!defined('ALLOW_MAIL_TEST')) define('ALLOW_MAIL_TEST', true);

if (!ALLOW_MAIL_TEST) {
    http_response_code(403);
    echo "FORBIDDEN";
    exit;
}


    $notifId = intval($_POST['send_parent_email']);
    if ($notifId <= 0) {
        http_response_code(400);
        echo "BAD_ID";
        exit;
    }

    // Fetch notification and ensure it's urgent
    $stmt = $conn->prepare("
        SELECT id, urgency, student_id, invoice_reference, description, time_from
        FROM NOTIFICATION_TAB
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        http_response_code(500);
        echo "DB_PREP_FAIL";
        exit;
    }

$stmt->bind_param("i", $notifId);
$stmt->execute();

$stmt->bind_result($id, $urgency, $student_id, $invoice_reference, $description, $time_from);
$notif = null;

if ($stmt->fetch()) {
    $notif = [
        'id' => $id,
        'urgency' => $urgency,
        'student_id' => $student_id,
        'invoice_reference' => $invoice_reference,
        'description' => $description,
        'time_from' => $time_from,
    ];
}

$stmt->close();

    if (!$notif) {
        http_response_code(404);
        echo "NOT_FOUND";
        exit;
    }

    if (($notif['urgency'] ?? '') !== 'urgent') {
        http_response_code(400);
        echo "NOT_URGENT";
        exit;
    }

    // ------------------------------------------------------------
    // HOOK: call your existing PHP mail script here
    // You decide how to resolve parent email(s).
    // ------------------------------------------------------------

    // Example: you might have a function already:
    // require_once "mail/send_parent_latefee.php";
    // $ok = sendLateFeeEmailToParents($conn, $notif);

    // For now, we call a placeholder include that YOU implement.
    // It should return true/false and set $errorMsg if needed.
    $ok = false;
    $errorMsg = "";

    try {
        require_once "send_parent_email.php"; // <-- YOU provide this file
        if (function_exists('sendParentEmailForNotification')) {
            $ok = sendParentEmailForNotification($conn, $notif, $errorMsg);
        } else {
            $errorMsg = "Missing function sendParentEmailForNotification() in send_parent_email.php";
        }
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        $ok = false;
    }
if ($ok) {
    // Success → mark as sent
    $stmt2 = $conn->prepare("
        UPDATE NOTIFICATION_TAB
        SET mail_status='sent',
            mail_sent_at = NOW()
        WHERE id=?
    ");
    if (!$stmt2) {
        http_response_code(500);
        echo "DB_PREP_FAIL: " . $conn->error;
        exit;
    }

    $stmt2->bind_param("i", $notifId);
    $stmt2->execute();
    $stmt2->close();

    echo "OK";
} else {
    // Failure → mark as failed (no last_error / attempts columns exist)
    $stmt2 = $conn->prepare("
        UPDATE NOTIFICATION_TAB
        SET mail_status='failed'
        WHERE id=?
    ");
    if (!$stmt2) {
        http_response_code(500);
        echo "DB_PREP_FAIL: " . $conn->error;
        exit;
    }

    $stmt2->bind_param("i", $notifId);
    $stmt2->execute();
    $stmt2->close();

    http_response_code(500);
    echo "MAIL_FAIL: " . $errorMsg;
}
exit;
}

/* ===============================================================
   NAVIGATOR
   =============================================================== */
require "navigator.php";

/* ===============================================================
   3) SEARCH + PAGINATION
   =============================================================== */
$search = trim($_GET["search"] ?? "");
$searchSql = "";

if ($search !== "") {
    $safe = $conn->real_escape_string($search);

    $isRef = (stripos($search, 'HTL-') === 0);           // looks like student ref
    $isNum = ctype_digit($search);                      // only digits

    $conds = [];

    // Always allow free text in description
    $conds[] = "n.description LIKE '%$safe%'";

    // Urgency search (info/warning/urgent)
    $conds[] = "n.urgency LIKE '%$safe%'";

    // Student reference (best UX): exact/prefix match
    // If user typed HTL-..., prioritize this
    if ($isRef) {
        $conds[] = "s.reference_id LIKE '$safe%'";
    } else {
        // also allow contains for partial searches
        $conds[] = "s.reference_id LIKE '%$safe%'";
    }

    // Numeric searches: invoice_reference or student_id
    if ($isNum) {
        $conds[] = "n.invoice_reference = '$safe'";
        $conds[] = "n.student_id = $safe";
    } else {
        // fallback contains (helps if invoice_reference is not purely numeric)
        $conds[] = "n.invoice_reference LIKE '%$safe%'";
        $conds[] = "CAST(n.student_id AS CHAR) LIKE '%$safe%'";
    }

    // Date search
    $conds[] = "n.time_from LIKE '%$safe%'";

    $searchSql = "WHERE (" . implode(" OR ", $conds) . ")";
}

$perPage = 10;
$page = isset($_GET["page"]) ? max(1, intval($_GET["page"])) : 1;

$countResult = $conn->query("
    SELECT COUNT(*) as total
    FROM NOTIFICATION_TAB n
    LEFT JOIN STUDENT_TAB s ON s.id = n.student_id
    $searchSql
");
$totalRows = ($countResult && ($r = $countResult->fetch_assoc())) ? (int)$r["total"] : 0;
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$result = $conn->query("
    SELECT 
        n.id,
        n.urgency,
        n.student_id,
        n.description,
        n.invoice_reference,
        n.time_from,
        n.is_read,
        s.reference_id AS student_ref
    FROM NOTIFICATION_TAB n
    LEFT JOIN STUDENT_TAB s ON s.id = n.student_id
    $searchSql
    ORDER BY n.time_from DESC
    LIMIT $perPage OFFSET $offset
");


/* helper for pagination links (keeps search) */
function buildPageLink($p, $search) {
    $params = ['page' => $p];
    if ($search !== '') $params['search'] = $search;
    return 'notifications.php?' . http_build_query($params);
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
.row-urgent { background:#FDE7E9 !important; }
.row-warning { background:#FFF4E0 !important; }
.row-info { background:#F3F3F3 !important; }
.read-row { opacity:0.55; }

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

/* NEW: send email button */
/* Link-style admin action */
.notify-btn{
    background:none;
    border:none;
    padding:0;
    margin:0;
    font-family:'Montserrat';
    font-weight:600;
    font-size:13px;
    color:#444;
    cursor:pointer;
    text-decoration:underline;
    transition: color .18s ease;
}

.notify-btn:hover{
    color:#B31E32; /* your red-dark */
}

.notify-btn:disabled{
    color:#aaa;
    cursor:not-allowed;
    text-decoration:none;
}


/* PAGINATION */
.pagination{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    margin:26px auto 0;
    max-width:1300px;
    user-select:none;
}
.page-btn, .page-num{
    text-decoration:none;
    border:1px solid #E3E5E0;
    padding:8px 12px;
    border-radius:10px;
    font-family:'Montserrat';
    font-weight:600;
    font-size:13px;
    color:#B31E32;
    background:#fff;
    box-shadow:0 2px 6px rgba(0,0,0,0.06);
}
.page-num.active{
    background:#B31E32;
    color:#fff;
    border-color:#B31E32;
}
.page-btn:hover, .page-num:hover{
    filter:brightness(0.98);
}
.page-btn.disabled{
    pointer-events:none;
    opacity:0.45;
}
.dots{
    color:#999;
    font-family:'Montserrat';
    font-weight:700;
    padding:0 2px;
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
    <?php if ($search !== ""): ?>
        <input type="hidden" name="page" value="1">
    <?php endif; ?>
</div>
</form>

<table class="notification-table">
<thead>
<tr>
    <th style="width:350px;">Description</th>
    <th>Student ID</th>
<th>Student Ref</th>
<th>Invoice Ref</th>
    <th>Urgency</th>
    <th>Timestamp</th>
    <?php if ($isAdmin): ?>
      <th style="width:140px;">Mark as read</th>
<th style="width:120px;">Notify Parent</th>
    <?php endif; ?>
</tr>
</thead>

<tbody>
<?php while ($result && ($row = $result->fetch_assoc())):
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

    $isUrgent = ($row["urgency"] === "urgent");
?>
<tr id="row_<?= (int)$row['id'] ?>" class="<?= $urgencyRowClass ?> <?= $isRead ? 'read-row' : '' ?>">
    <td><?= nl2br(htmlspecialchars($row["description"])) ?></td>
    <td><?= htmlspecialchars($row["student_id"]) ?></td>
<td><?= htmlspecialchars($row["student_ref"] ?? '—') ?></td>

<td>
<?php
  $desc = (string)($row["description"] ?? '');
  $isLateFee = (stripos($desc, 'Late fee') === 0);

  // For latefees, there is no invoice reference_number -> show dash.
  echo $isLateFee ? '—' : htmlspecialchars($row["invoice_reference"] ?? '—');
?>
</td>
    <td>
        <div class="urgency-badge <?= $badgeClass ?>">
            <span class="material-icons-outlined"><?= $icon ?></span>
            <?= $badgeText ?>
        </div>
    </td>
    <td><?= htmlspecialchars($row["time_from"]) ?></td>

    <?php if ($isAdmin): ?>
    <td>
        <input type="checkbox" class="bulk-check" value="<?= (int)$row['id'] ?>"
               style="width:16px; height:16px; cursor:pointer;">
    </td>

    <!-- NEW: Notify Parent column -->
    <td>
        <?php if ($isUrgent): ?>
            <button type="button"
        class="notify-btn"
        onclick="sendParentEmail(<?= (int)$row['id'] ?>, this)">
    Send Email
</button>
        <?php else: ?>
            <!-- empty for info/warning -->
        <?php endif; ?>
    </td>
    <?php endif; ?>
</tr>
<?php endwhile; ?>
</tbody>
</table>

<div class="mark-read-wrapper">
<?php if ($isAdmin): ?>
    <button type="button" class="mark-read-button" onclick="markAllSelected()">
        Mark all selected as read
    </button>
<?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>"
       href="<?= $page <= 1 ? '#' : htmlspecialchars(buildPageLink($page - 1, $search)) ?>">‹ Prev</a>

    <?php
        $window = 2;
        $start = max(1, $page - $window);
        $end   = min($totalPages, $page + $window);

        if ($start > 1) {
            echo '<a class="page-num" href="'.htmlspecialchars(buildPageLink(1, $search)).'">1</a>';
            if ($start > 2) echo '<span class="dots">…</span>';
        }

        for ($p = $start; $p <= $end; $p++) {
            $active = ($p === $page) ? 'active' : '';
            echo '<a class="page-num '.$active.'" href="'.htmlspecialchars(buildPageLink($p, $search)).'">'.$p.'</a>';
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="dots">…</span>';
            echo '<a class="page-num" href="'.htmlspecialchars(buildPageLink($totalPages, $search)).'">'.$totalPages.'</a>';
        }
    ?>

    <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
       href="<?= $page >= $totalPages ? '#' : htmlspecialchars(buildPageLink($page + 1, $search)) ?>">Next ›</a>
</div>
<?php endif; ?>

</main>

<script>
const notifSidebar = document.getElementById("sidebar");
const notifOverlay = document.getElementById("overlay");
const notifContent = document.getElementById("notifContent");

function openSidebar(){
    notifSidebar.classList.add("open");
    notifContent.classList.add("shifted");
    notifOverlay.classList.add("show");
}
function closeSidebar(){
    notifSidebar.classList.remove("open");
    notifContent.classList.remove("shifted");
    notifOverlay.classList.remove("show");
}
function toggleSidebar(){
    notifSidebar.classList.contains("open") ? closeSidebar() : openSidebar();
}

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
    });
}

/* NEW: Send parent email for urgent notifications only */
function sendParentEmail(notifId, btn){
    if (!confirm("Send an email to the parent(s) for this CRITICAL notification?")) return;

    btn.disabled = true;
    const oldText = btn.innerHTML;
    btn.innerHTML = '<span class="material-icons-outlined" style="font-size:18px;">hourglass_top</span> SENDING...';

    const formData = new URLSearchParams();
    formData.append('send_parent_email', notifId);

    fetch("notifications.php", {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: formData.toString()
    })
    .then(r => r.text().then(t => ({ok:r.ok, text:t})))
    .then(({ok, text}) => {
        if (ok && text.trim() === "OK") {
            alert("Email sent.");
        } else {
            alert("Email failed:\n" + text);
        }
    })
    .catch(err => alert("Network error: " + err))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = oldText;
    });
}
</script>

</body>
</html>
