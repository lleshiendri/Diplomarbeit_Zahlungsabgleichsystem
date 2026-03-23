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
   PHASE 1 CHECK: VIEWPORT HANDSHAKE NEEDED?
   =============================================================== */
$hasRowsPerPageParam = isset($_GET["rows_per_page"]);
$needsViewportHandshake = !$hasRowsPerPageParam;

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

$minPerPage = 4;
$maxPerPage = 10;
$defaultPerPage = 6;

// Only proceed with full rendering if rows_per_page is explicitly set
// Otherwise, render minimal state and let JS compute it
if ($hasRowsPerPageParam) {
    $perPage = intval($_GET["rows_per_page"]);
    $perPage = max($minPerPage, min($maxPerPage, $perPage));
} else {
    // Minimal state: don't query DB yet
    $perPage = $defaultPerPage;
}

$page = isset($_GET["page"]) ? max(1, intval($_GET["page"])) : 1;

// Only query DB if we're not in handshake mode
if (!$needsViewportHandshake) {
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
} else {
    // Handshake phase: set defaults, no queries yet
    $totalRows = 0;
    $totalPages = 1;
    $offset = 0;
    $result = null;
}


/* helper for pagination links (keeps current GET filters/search) */
function buildPageLink($p, $rowsPerPage) {
    $params = $_GET;
    $params['page'] = $p;
    $params['rows_per_page'] = $rowsPerPage;
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
#content {
    transition: margin-left 0.3s ease;
    margin-left: 0;
    padding: 100px 30px 60px;
}
#content.shifted {
    margin-left: 260px;
}
.page-title {
    font-family:'Space Grotesk';
    font-weight:700;
    color:#B31E32;
    font-size:28px;
    margin-top:5px;
    margin-bottom:20px;
    margin-left:auto;
    margin-right:auto;
    text-align:left;
    max-width:1300px;
}
.search-wrapper{
    display:flex;
    align-items:center;
    width:330px;
    background:#F4F4F4;
    border-radius:12px;
    border:1px solid #D1D1D1;
    padding:4px 10px;
    margin: 0 auto 25px auto;
    box-shadow:0 2px 6px rgba(0,0,0,0.08);
    max-width:1300px;
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
    table-layout:fixed;
}
.notification-table th {
    background:#FAE4D5;
    color:#B31E32;
    font-family:'Montserrat';
    font-weight:600;
    padding:14px;
    font-size: 15px;
    text-align:center;
    overflow:hidden;
}
.notification-table th:nth-child(1){ width:39%; text-align:left; }
.notification-table th:nth-child(2){ width:8%; }
.notification-table th:nth-child(3){ width:13%; }
.notification-table th:nth-child(4){ width:13%; }
.notification-table th:nth-child(5){ width:110px; }
.notification-table th:nth-child(6){ width:105px; }
.notification-table th.actions-col{ width:11%; min-width:80px; }
.notification-table td {
    padding:14px;
    border-bottom:1px solid #EEE;
    font-size: 15px;
    text-align: center;
    vertical-align: middle;
    overflow:hidden;
    text-overflow:ellipsis;
}
.notification-table td:nth-child(5){ width:110px; }
.notification-table td:nth-child(6){ width:105px; }
.col-description{
    text-align:left !important;
    white-space:normal;
    overflow-wrap:anywhere;
    word-break:break-word;
    text-overflow:clip;
}
.col-short{
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    padding:10px 4px;
}
.col-full-text{
    text-align:left !important;
    white-space:normal;
    word-break:break-word;
    overflow-wrap:break-word;
    padding:10px 8px;
    overflow:visible;
}
.col-invoice-ref{
    text-align:center;
    white-space:normal;
    word-break:break-word;
    overflow-wrap:break-word;
    padding:10px 8px;
    overflow:visible;
}
.col-nowrap{
    white-space:nowrap;
    padding:10px 6px;
    overflow:visible;
}
.notification-table-wrap{
    width:100%;
    overflow-x:auto;
    overflow-y:visible;
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
    min-width:0;
    white-space:nowrap;
    overflow:hidden;
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
    background:transparent;
    border:none;
    width:18px;
    height:18px;
    padding:0;
    margin:0;
    color:#B31E32;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    transition:all .18s ease;
    flex-shrink:0;
}

.notify-btn:hover{
    color:#8d1727;
}

.notify-btn:disabled{
    color:#aaa;
    cursor:not-allowed;
}
.notify-btn .material-icons-outlined{
    font-size:16px;
}
.actions-cell{
    white-space:nowrap;
    padding:10px 4px;
}
.actions-wrap{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:3px;
}
.bulk-check{
    width:16px;
    height:16px;
    cursor:pointer;
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

<div id="content">
<main>

<h1 class="page-title">NOTIFICATIONS</h1>

<?php if ($needsViewportHandshake): ?>
    <!-- PHASE 1: VIEWPORT DETECTION SHELL -->
    <div id="initializingShell" style="text-align:center; padding:60px 30px; color:#999; font-size:14px;">
        <p>Initializing for your screen...</p>
    </div>
<?php else: ?>
    <!-- PHASE 2: FULL RENDER -->
<form method="GET">
<div class="search-wrapper">
    <span class="material-icons-outlined" style="color:#777;">search</span>
    <input type="text" name="search" placeholder="Search…"
           value="<?= htmlspecialchars($search) ?>"
           style="flex:1; border:none; background:transparent; outline:none; font-family:'Montserrat'; font-size: 13px;">
    <?php if ($search !== ""): ?>
        <input type="hidden" name="page" value="1">
    <?php endif; ?>
    <input type="hidden" name="rows_per_page" value="<?= (int)$perPage ?>">
</div>
</form>

<div class="notification-table-wrap">
<table class="notification-table">
<thead>
<tr>
    <th>Description</th>
    <th>Student ID</th>
    <th>Student Ref</th>
    <th>Invoice Ref</th>
    <th>Urgency</th>
    <th>Timestamp</th>
    <?php if ($isAdmin): ?>
      <th class="actions-col">Actions</th>
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
    <td class="col-description"><?= nl2br(htmlspecialchars($row["description"])) ?></td>
    <td class="col-short"><?= htmlspecialchars($row["student_id"]) ?></td>
    <td class="col-full-text"><?= htmlspecialchars($row["student_ref"] ?? '—') ?></td>

<td class="col-invoice-ref">
<?php
  $desc = (string)($row["description"] ?? '');
  $isLateFee = (stripos($desc, 'Late fee') === 0);

  // For latefees, there is no invoice reference_number -> show dash.
  echo $isLateFee ? '—' : htmlspecialchars($row["invoice_reference"] ?? '—');
?>
</td>
    <td class="col-nowrap">
        <div class="urgency-badge <?= $badgeClass ?>">
            <span class="material-icons-outlined"><?= $icon ?></span>
            <?= $badgeText ?>
        </div>
    </td>
    <td class="col-short"><?= htmlspecialchars($row["time_from"]) ?></td>

    <?php if ($isAdmin): ?>
    <td class="actions-cell">
        <div class="actions-wrap">
            <input type="checkbox" class="bulk-check" value="<?= (int)$row['id'] ?>">
        <?php if ($isUrgent): ?>
            <button type="button"
                    class="notify-btn"
                    title="Send email to parent"
                    aria-label="Send email to parent"
                    onclick="sendParentEmail(<?= (int)$row['id'] ?>, this)">
                <span class="material-icons-outlined">mail</span>
            </button>
        <?php else: ?>
            <span style="display:inline-block; width:16px; height:16px;"></span>
        <?php endif; ?>
        </div>
    </td>
    <?php endif; ?>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>

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
       href="<?= $page <= 1 ? '#' : htmlspecialchars(buildPageLink($page - 1, $perPage)) ?>">‹ Prev</a>

    <?php
        $window = 2;
        $start = max(1, $page - $window);
        $end   = min($totalPages, $page + $window);

        if ($start > 1) {
            echo '<a class="page-num" href="'.htmlspecialchars(buildPageLink(1, $perPage)).'">1</a>';
            if ($start > 2) echo '<span class="dots">…</span>';
        }

        for ($p = $start; $p <= $end; $p++) {
            $active = ($p === $page) ? 'active' : '';
            echo '<a class="page-num '.$active.'" href="'.htmlspecialchars(buildPageLink($p, $perPage)).'">'.$p.'</a>';
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="dots">…</span>';
            echo '<a class="page-num" href="'.htmlspecialchars(buildPageLink($totalPages, $perPage)).'">'.$totalPages.'</a>';
        }
    ?>

    <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
       href="<?= $page >= $totalPages ? '#' : htmlspecialchars(buildPageLink($page + 1, $perPage)) ?>">Next ›</a>
</div>
<?php endif; ?>

<?php endif; // Close $needsViewportHandshake conditional ?>

</main>
</div>

<script>
/* ========================================================== */
/* PHASE 1: VIEWPORT HANDSHAKE DETECTION AND REDIRECT       */
/* ========================================================== */
function performViewportHandshake() {
    const shell = document.getElementById("initializingShell");
    if (!shell) return; // Not in handshake mode
    
    // This page was loaded without rows_per_page parameter
    // Compute it now based on current viewport
    const computed = estimateRowsPerPageFromViewport();
    
    if (computed && Number.isFinite(computed) && computed > 0) {
        // Redirect to same page with rows_per_page parameter
        const url = new URL(window.location.href);
        url.searchParams.set('rows_per_page', String(computed));
        // Reset to page 1 since we're just starting
        url.searchParams.set('page', '1');
        // Clear any existing search on first handshake
        if (!url.searchParams.has('search')) {
            url.searchParams.delete('search');
        }
        window.location.replace(url.toString());
    }
}

function estimateRowsPerPageFromViewport() {
    const minRows = 4;
    const maxRows = 10;
    const viewportHeight = window.innerHeight;
    
    // Estimate reserved space conservatively:
    // - Header: ~70px
    // - Title: ~50px
    // - Search box: ~50px
    // - Table header: ~48px
    // - Mark-read button: ~50px
    // - Pagination: ~50px
    // - Safety buffer: 60px
    const estimatedReservedHeight = 70 + 50 + 50 + 48 + 50 + 50 + 60;
    
    // Available space for table body
    const availableBodyHeight = Math.max(120, viewportHeight - estimatedReservedHeight);
    
    // Estimate row height conservatively: 62px per row
    const estimatedRowHeight = 62;
    
    // Compute how many rows fit
    const rowsFit = Math.floor(availableBodyHeight / estimatedRowHeight);
    
    // Clamp and bias toward fewer rows (not more)
    const result = Math.max(minRows, Math.min(maxRows, rowsFit - 1)); // -1 for extra safety
    
    return result > 0 ? result : null;
}

// Run handshake on initial load if needed
document.addEventListener('DOMContentLoaded', performViewportHandshake);

function clampRowsPerPage(v, min, max) {
    return Math.max(min, Math.min(max, v));
}

function computeRowsPerPageForViewport() {
    const minRows = 4;
    const maxRows = 10;
    const table = document.querySelector(".notification-table");
    const tbody = table ? table.querySelector("tbody") : null;
    if (!table || !tbody) return null;

    const tableTop = table.getBoundingClientRect().top;
    const viewportHeight = window.innerHeight;

    const markRead = document.querySelector(".mark-read-wrapper");
    const pagination = document.querySelector(".pagination");
    const markReadH = markRead ? markRead.getBoundingClientRect().height : 0;
    const paginationH = pagination ? pagination.getBoundingClientRect().height : 0;

    const verticalReserve = markReadH + paginationH + 60; // reduced from 90 to allow 1-2 more rows
    const availableForTable = Math.floor(viewportHeight - tableTop - verticalReserve);

    const thead = table.querySelector("thead");
    const headerHeight = thead ? Math.ceil(thead.getBoundingClientRect().height) : 48;
    const availableBodyHeight = Math.max(120, availableForTable - headerHeight);

    let rowHeight = 62;
    const rows = Array.from(tbody.querySelectorAll("tr"));
    if (rows.length > 0) {
        const sampleRows = rows.slice(0, Math.min(4, rows.length));
        rowHeight = Math.ceil(Math.max(...sampleRows.map(row => row.getBoundingClientRect().height)));
    } else {
        const sampleCell = table.querySelector("tbody td");
        if (sampleCell) {
            const cs = window.getComputedStyle(sampleCell);
            const lineHeight = parseFloat(cs.lineHeight) || 20;
            const pt = parseFloat(cs.paddingTop) || 14;
            const pb = parseFloat(cs.paddingBottom) || 14;
            rowHeight = Math.ceil(lineHeight + pt + pb + 12);
        }
    }

    rowHeight = Math.max(54, rowHeight);
    const rowsFit = Math.floor(availableBodyHeight / rowHeight); // removed -1 penalty; buffer provides safety
    return clampRowsPerPage(rowsFit, minRows, maxRows);
}

// Only sync viewport on handshake phase (when initializing shell is visible)
// Do NOT resync on every page load after handshake is complete
function syncRowsPerPageWithViewport() {
    if (!document.getElementById('initializingShell')) return; // Not in handshake phase, skip
    
    const url = new URL(window.location.href);
    const currentRaw = parseInt(url.searchParams.get("rows_per_page") || "", 10);
    const current = Number.isFinite(currentRaw) ? clampRowsPerPage(currentRaw, 4, 10) : null;
    const computed = computeRowsPerPageForViewport();
    if (!Number.isFinite(computed)) return;

    if (current === null || current !== computed) {
        url.searchParams.set("rows_per_page", String(computed));
        if (current !== computed) {
            url.searchParams.set("page", "1");
        }
        window.location.replace(url.toString());
    }
}

syncRowsPerPageWithViewport();

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
    btn.innerHTML = '<span class="material-icons-outlined">hourglass_top</span>';

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
