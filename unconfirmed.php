<?php
require_once 'auth_check.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'navigator.php';
require 'db_connect.php';
require_once 'matching_functions.php';

$success_message = "";
$error_message   = "";

/* ===============================================================
   FILTERS (from filters.php for unconfirmed.php)
   Keys:
   - beneficiary
   - reference_number
   - q
   - transaction_type (optional)
   - from / to
   - amount_min / amount_max
   - page
   - mh_id (selected row)
   =============================================================== */
$filterBeneficiary = trim($_GET['beneficiary'] ?? '');
$filterRefNumber   = trim($_GET['reference_number'] ?? '');
$filterText        = trim($_GET['q'] ?? '');
$filterType        = trim($_GET['transaction_type'] ?? '');

$filterFrom        = trim($_GET['from'] ?? '');
$filterTo          = trim($_GET['to'] ?? '');
$filterMin         = trim($_GET['amount_min'] ?? '');
$filterMax         = trim($_GET['amount_max'] ?? '');

/* ===============================================================
   PAGINATION
   =============================================================== */
$limit = 10;
$page  = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$paginationBase = $_GET;
unset($paginationBase['page']);

/* ===============================================================
   Helpers for prepared statements with dynamic params
   =============================================================== */
function stmt_bind_params(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) return;
    $bind_names = [];
    $bind_names[] = $types;
    for ($i = 0; $i < count($params); $i++) {
        $bind_names[] = &$params[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);
}

function buildFilteredWhere(array &$params, string &$types, mysqli $conn): string {
    $clauses = [];
    // Base: unconfirmed suggestions ONLY
    $clauses[] = "mh.is_confirmed = 0";

    if ($GLOBALS['filterBeneficiary'] !== '') {
        $clauses[] = "t.beneficiary LIKE ?";
        $types   .= "s";
        $params[] = "%" . $GLOBALS['filterBeneficiary'] . "%";
    }

    if ($GLOBALS['filterRefNumber'] !== '') {
        $clauses[] = "t.reference_number LIKE ?";
        $types   .= "s";
        $params[] = "%" . $GLOBALS['filterRefNumber'] . "%";
    }

    if ($GLOBALS['filterText'] !== '') {
        $clauses[] = "(t.reference LIKE ? OR t.description LIKE ?)";
        $types   .= "ss";
        $like = "%" . $GLOBALS['filterText'] . "%";
        $params[] = $like;
        $params[] = $like;
    }

    if ($GLOBALS['filterType'] !== '') {
        $clauses[] = "t.transaction_type = ?";
        $types   .= "s";
        $params[] = $GLOBALS['filterType'];
    }

    if ($GLOBALS['filterFrom'] !== '') {
        $clauses[] = "t.processing_date >= ?";
        $types   .= "s";
        $params[] = $GLOBALS['filterFrom'];
    }

    if ($GLOBALS['filterTo'] !== '') {
        $clauses[] = "t.processing_date <= ?";
        $types   .= "s";
        $params[] = $GLOBALS['filterTo'];
    }

    if ($GLOBALS['filterMin'] !== '' && is_numeric($GLOBALS['filterMin'])) {
        $clauses[] = "t.amount_total >= ?";
        $types   .= "d";
        $params[] = (float)$GLOBALS['filterMin'];
    }

    if ($GLOBALS['filterMax'] !== '' && is_numeric($GLOBALS['filterMax'])) {
        $clauses[] = "t.amount_total <= ?";
        $types   .= "d";
        $params[] = (float)$GLOBALS['filterMax'];
    }

    return "WHERE " . implode(" AND ", $clauses);
}

/* ===============================================================
   HANDLE ADMIN CONFIRMATION (manual assign based on mh_id)
   =============================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_invoice'])) {

    $mh_id     = isset($_POST['mh_id']) ? (int)$_POST['mh_id'] : 0;
    $student_id= isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

    if ($mh_id > 0 && $student_id > 0) {

        $conn->begin_transaction();

        try {
            // 1) Load mh row (guard unconfirmed) + invoice meta
            $sql = "
                SELECT
                    mh.invoice_id,
                    t.reference_number,
                    t.processing_date
                FROM MATCHING_HISTORY_TAB mh
                JOIN INVOICE_TAB t ON t.id = mh.invoice_id
                WHERE mh.id = ?
                  AND mh.is_confirmed = 0
                LIMIT 1
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Statement error: " . $conn->error);

            $stmt->bind_param("i", $mh_id);
            if (!$stmt->execute()) throw new Exception("Execute error: " . $stmt->error);

            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$row) {
                throw new Exception("Invalid or already confirmed suggestion.");
            }

            $invoice_id = (int)$row['invoice_id'];

            // 2) Update invoice final assignment
            $stmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ? WHERE id = ?");
            if (!$stmt) throw new Exception("Statement error: " . $conn->error);
            $stmt->bind_param("ii", $student_id, $invoice_id);
            if (!$stmt->execute()) throw new Exception("Error updating invoice: " . $stmt->error);
            $stmt->close();

            // 3) Mark THIS mh suggestion as confirmed
          // 3) Confirm THIS mh row AND store the FINAL chosen student_id
$stmt = $conn->prepare("
  UPDATE MATCHING_HISTORY_TAB
  SET student_id = ?, is_confirmed = 1
  WHERE id = ? AND is_confirmed = 0
");
if (!$stmt) throw new Exception("Statement error: " . $conn->error);
$stmt->bind_param("ii", $student_id, $mh_id);
if (!$stmt->execute()) throw new Exception("Error updating matching history: " . $stmt->error);
$stmt->close();

            // 4) Optional: notifications (fail-safe)
            $processing_date = $row['processing_date'] ?: date('Y-m-d H:i:s');
            $invoice_reference = $row['reference_number'];
            if ($invoice_reference === null || trim((string)$invoice_reference) === '') {
                $invoice_reference = "INV-" . (int)$invoice_id;
            }

            if (function_exists('createNotificationOnce')) {
                @createNotificationOnce(
                    $conn,
                    "info",
                    $student_id,
                    $invoice_reference,
                    date('Y-m-d', strtotime((string)$processing_date)),
                    "Confirmed manually: $invoice_reference assigned to Student #$student_id"
                );
            }

            if (function_exists('maybeCreateLateFeeUrgent')) {
                @maybeCreateLateFeeUrgent($conn, $student_id, $invoice_reference, (string)$processing_date);
            }

            $conn->commit();
            $success_message = "Suggestion confirmed and assigned to student.";

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }

    } else {
        $error_message = "Invalid matching history or student ID.";
    }
}

/* ===============================================================
   FILTERED BASE WHERE for mh + t
   =============================================================== */
$params = [];
$types  = "";
$whereSql = buildFilteredWhere($params, $types, $conn);

/* ===============================================================
   Best-suggestion-per-invoice selection (MySQL 5.7 compatible)
   - pick highest confidence_score
   - tie-breaker newest created_at
   - tie-breaker highest mh.id
   =============================================================== */
$bestPickJoin = "
    JOIN (
        SELECT x.invoice_id, MAX(x.id) AS best_mh_id
        FROM MATCHING_HISTORY_TAB x
        JOIN INVOICE_TAB tx ON tx.id = x.invoice_id
        {$whereSql}
        AND x.confidence_score = (
            SELECT MAX(y.confidence_score)
            FROM MATCHING_HISTORY_TAB y
            WHERE y.invoice_id = x.invoice_id
              AND y.is_confirmed = 0
        )
        AND x.created_at = (
            SELECT MAX(z.created_at)
            FROM MATCHING_HISTORY_TAB z
            WHERE z.invoice_id = x.invoice_id
              AND z.is_confirmed = 0
              AND z.confidence_score = (
                SELECT MAX(y2.confidence_score)
                FROM MATCHING_HISTORY_TAB y2
                WHERE y2.invoice_id = x.invoice_id
                  AND y2.is_confirmed = 0
              )
        )
        GROUP BY x.invoice_id
    ) best ON best.best_mh_id = mh.id
";

/* ===============================================================
   COUNT for pagination (best suggestions only)
   =============================================================== */
/* ===============================================================
   COUNT (pagination) - ALL unconfirmed suggestions
   =============================================================== */
$count_sql = "
  SELECT COUNT(*) AS total
  FROM MATCHING_HISTORY_TAB mh
  JOIN INVOICE_TAB t ON t.id = mh.invoice_id
  {$whereSql}
";
$count_stmt = $conn->prepare($count_sql);
$totalRows = 0;
if ($count_stmt) {
  $count_params = $params;
  $count_types  = $types;
  stmt_bind_params($count_stmt, $count_types, $count_params);
  $count_stmt->execute();
  $count_res = $count_stmt->get_result();
  $totalRows = $count_res ? (int)$count_res->fetch_assoc()['total'] : 0;
  $count_stmt->close();
}

$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $limit;

/* ===============================================================
   LIST - ALL unconfirmed suggestions (no bestPickJoin)
   =============================================================== */
$transactions_sql = "
  SELECT
    mh.id AS mh_id,
    mh.invoice_id,
    mh.student_id AS suggested_student_id,
    mh.confidence_score,
    mh.matched_by,
    mh.created_at AS suggested_at,

    t.reference_number,
    t.beneficiary,
    t.reference,
    t.description,
    t.transaction_type,
    t.amount_total,
    t.processing_date
  FROM MATCHING_HISTORY_TAB mh
  JOIN INVOICE_TAB t ON t.id = mh.invoice_id
  {$whereSql}
  ORDER BY mh.created_at DESC
  LIMIT ? OFFSET ?
";

$transactions_stmt = $conn->prepare($transactions_sql);
$transactions_result = null;

if (!$transactions_stmt) {
  die("SQL PREPARE FAILED: " . $conn->error . "<br><pre>" . htmlspecialchars($transactions_sql) . "</pre>");
}

$q_params = $params;
$q_types  = $types . "ii";
$q_params[] = $limit;
$q_params[] = $offset;

stmt_bind_params($transactions_stmt, $q_types, $q_params);
$transactions_stmt->execute();
$transactions_result = $transactions_stmt->get_result();

/* ===============================================================
   STAT CARDS
   Pending = count of best suggestions where mh.is_confirmed=0 (best per invoice)
   Confirmed = invoices that have student_id set (as your system currently uses)
   =============================================================== */
$pending = 0;
$pending_sql = "
  SELECT COUNT(*) AS c
  FROM MATCHING_HISTORY_TAB mh
  JOIN INVOICE_TAB t ON t.id = mh.invoice_id
  {$whereSql}
";
$pending_stmt = $conn->prepare($pending_sql);
if ($pending_stmt) {
  $p_params = $params;
  $p_types  = $types;
  stmt_bind_params($pending_stmt, $p_types, $p_params);
  $pending_stmt->execute();
  $pending_res = $pending_stmt->get_result();
  $pending = $pending_res ? (int)$pending_res->fetch_assoc()['c'] : 0;
  $pending_stmt->close();
}
$pending_stmt = $conn->prepare($pending_sql);
if ($pending_stmt) {
    $p_params = $params;
    $p_types  = $types;
    stmt_bind_params($pending_stmt, $p_types, $p_params);
    $pending_stmt->execute();
    $pending_res = $pending_stmt->get_result();
    $pending = $pending_res ? (int)$pending_res->fetch_assoc()['c'] : 0;
    $pending_stmt->close();
}

$confirmed = 0;
$confirmed_sql = "SELECT COUNT(*) AS c FROM INVOICE_TAB WHERE student_id IS NOT NULL";
$confirmed_res = $conn->query($confirmed_sql);
$confirmed = $confirmed_res ? (int)$confirmed_res->fetch_assoc()['c'] : 0;

/* ===============================================================
   SIDEBAR (selected mh suggestion)
   =============================================================== */
$suggestion_mh_id = null;
$suggestion_invoice_id = null;
$suggestion_student_id = null;
$suggestion_student_name = "";
$suggestion_invoice_ref = "";
$suggestion_invoice_beneficiary = "";
$suggestion_invoice_reference = "";
$suggestion_invoice_desc = "";
$suggestion_processing_date = "";
$suggestion_amount = 0.0;

$suggestion_confidence = null;
$suggestion_matched_by = "";
$suggestion_created_at = "";
$reason_text = "";

// Selected suggestion via GET
$active_mh_id = isset($_GET['mh_id']) ? (int)$_GET['mh_id'] : 0;

// Pick selected if exists and still unconfirmed, else pick newest in current filtered set
if ($active_mh_id > 0) {
    $suggestion_sql = "
        SELECT
            mh.id AS mh_id,
            mh.invoice_id,
            mh.student_id AS suggested_student_id,
            mh.confidence_score,
            mh.matched_by,
            mh.created_at,

            t.reference_number,
            t.beneficiary,
            t.reference,
            t.description,
            t.processing_date,
            t.amount_total,

            s.long_name AS suggested_student_name
        FROM MATCHING_HISTORY_TAB mh
        JOIN INVOICE_TAB t ON t.id = mh.invoice_id
        LEFT JOIN STUDENT_TAB s ON s.id = mh.student_id
        WHERE mh.is_confirmed = 0
          AND mh.id = ?
        LIMIT 1
    ";
    $suggestion_stmt = $conn->prepare($suggestion_sql);
    if ($suggestion_stmt) {
        $suggestion_stmt->bind_param("i", $active_mh_id);
        $suggestion_stmt->execute();
        $suggestion_res = $suggestion_stmt->get_result();
        $suggestion_row = $suggestion_res ? $suggestion_res->fetch_assoc() : null;
        $suggestion_stmt->close();
    } else {
        $suggestion_row = null;
    }
} else {
    // newest best suggestion from current filtered list
    $suggestion_sql = "
        SELECT
            mh.id AS mh_id,
            mh.invoice_id,
            mh.student_id AS suggested_student_id,
            mh.confidence_score,
            mh.matched_by,
            mh.created_at,

            t.reference_number,
            t.beneficiary,
            t.reference,
            t.description,
            t.processing_date,
            t.amount_total,

            s.long_name AS suggested_student_name
        FROM MATCHING_HISTORY_TAB mh
        JOIN INVOICE_TAB t ON t.id = mh.invoice_id
        LEFT JOIN STUDENT_TAB s ON s.id = mh.student_id
        {$bestPickJoin}
        {$whereSql}
        ORDER BY mh.created_at DESC
        LIMIT 1
    ";
    $suggestion_stmt = $conn->prepare($suggestion_sql);
    if ($suggestion_stmt) {
        $s_params = $params;
        $s_types  = $types;
        stmt_bind_params($suggestion_stmt, $s_types, $s_params);
        $suggestion_stmt->execute();
        $suggestion_res = $suggestion_stmt->get_result();
        $suggestion_row = $suggestion_res ? $suggestion_res->fetch_assoc() : null;
        $suggestion_stmt->close();
    } else {
        $suggestion_row = null;
    }
}

if (!empty($suggestion_row)) {
    $suggestion_mh_id = (int)$suggestion_row['mh_id'];
    $suggestion_invoice_id = (int)$suggestion_row['invoice_id'];
    $suggestion_student_id = (int)($suggestion_row['suggested_student_id'] ?? 0);

    $suggestion_confidence = isset($suggestion_row['confidence_score']) ? (float)$suggestion_row['confidence_score'] : null;
    $suggestion_matched_by = (string)($suggestion_row['matched_by'] ?? '');
    $suggestion_created_at = (string)($suggestion_row['created_at'] ?? '');

    $suggestion_invoice_ref = (string)($suggestion_row['reference_number'] ?? '');
    $suggestion_invoice_beneficiary = (string)($suggestion_row['beneficiary'] ?? '');
    $suggestion_invoice_reference = (string)($suggestion_row['reference'] ?? '');
    $suggestion_invoice_desc = (string)($suggestion_row['description'] ?? '');
    $suggestion_processing_date = (string)($suggestion_row['processing_date'] ?? '');
    $suggestion_amount = (float)($suggestion_row['amount_total'] ?? 0);

    $suggestion_student_name = (string)($suggestion_row['suggested_student_name'] ?? '');

    // Reason text strictly from mh metadata
    $reason_text = "Suggested by: " . ($suggestion_matched_by !== '' ? $suggestion_matched_by : 'unknown');
    if ($suggestion_confidence !== null) {
        $reason_text .= " • Confidence: " . rtrim(rtrim(number_format($suggestion_confidence, 2, '.', ''), '0'), '.') . "%";
    }
    if ($suggestion_created_at !== '') {
        $reason_text .= " • Created: " . date("d/m/Y H:i", strtotime($suggestion_created_at));
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Unconfirmed</title>

  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Roboto:wght@400;500&family=Space+Grotesk:wght@700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

  <style>
    :root{
      --red-dark:#B31E32;
      --red-main:#D4463B;
      --red-light:#FAE4D5;
      --off-white:#FFF8EB;
      --gray-light:#E3E5E0;
      --radius:10px;
      --shadow:0 1px 2px rgba(0,0,0,.06),0 2px 10px rgba(0,0,0,.04);
      --sidebar-w:320px;
    }
    *{box-sizing:border-box}
    body{ margin:0; font-family:'Roboto',sans-serif; color:black; background:#fff; }
    #content { transition: margin-left 0.3s ease; margin-left: 0; padding: 100px 30px 60px; }
    #content.shifted { margin-left: 260px; }

    .page h1{
      font-family:'Space Grotesk',sans-serif;
      font-size:28px;
      font-weight:700;
      color:var(--red-dark);
      letter-spacing:.5px;
      margin:0 0 8px;
    }
    .subtitle{
      display:inline-block;
      margin:0 0 23px;
      padding:6px 10px;
      font-size:14px;
      color:#444;
      background:#fff;
      border-radius:8px;
      box-shadow:var(--shadow);
    }
    .layout{
      display:grid;
      grid-template-columns: 1fr var(--sidebar-w);
      gap:20px;
      align-items:start;
    }
    .card{
      border:1px solid var(--gray-light);
      border-radius:var(--radius);
      background:#fff;
      box-shadow:var(--shadow);
      padding:20px;
    }

    table{width:100%;border-collapse:collapse;font-size:14px;}
    thead th{
      font-family:'Montserrat',sans-serif;
      text-align:left;
      padding:12px 14px;
      background:var(--red-light);
      color:#333;
      font-weight:600;
    }
    tbody td{
      font-family:'Roboto',sans-serif;
      padding:12px 14px;
      border-top:1px solid var(--gray-light);
    }
    .amount{text-align:right;font-variant-numeric:tabular-nums;}
    tbody tr:hover { background-color:#f5f5f5; transition:background-color 0.2s ease-in-out; cursor:pointer; }

    /* ACTIVE ROW HIGHLIGHT */
    tbody tr.active-row{
      outline: 2px solid var(--red-main);
      background: #fff2ee;
    }

    .stack{display:flex;flex-direction:column;gap:16px;}
    .stats{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    .stat{
      border:1px solid var(--gray-light);
      border-radius:var(--radius);
      background:#fff;
      box-shadow:var(--shadow);
      padding:10px 12px;
      text-align:center;
    }
    .stat .num{
      font-family:'Space Grotesk',sans-serif;
      font-weight:700;
      font-size:28px;
      line-height:1;
      margin-bottom:6px;
      color:#000;
    }
    .stat .label{
      font-size:12px;
      color:#333;
      border-top:1px solid var(--gray-light);
      padding-top:6px;
      font-family:'Roboto',sans-serif;
    }

    .side-title{font-family:'Montserrat',sans-serif;font-weight:600;color:#333;font-size:15px;margin:0 0 8px;}
    .side-input{
      font-family:'Roboto',sans-serif;
      width:100%;
      border:1px solid var(--gray-light);
      border-radius:8px;
      padding:10px 12px;
      font-size:14px;
      margin-bottom:14px;
      color:grey;
      background:#fff;
    }
    .reason-text{font-family:'Roboto',sans-serif;font-size:13px;line-height:1.6;margin-top:6px;color:#333;}
    .btn{
      font-family:'Roboto',sans-serif;
      appearance:none;
      border:none;
      cursor:pointer;
      border-radius:6px;
      padding:8px 14px;
      font-weight:500;
      font-size:13px;
      display:inline-flex;
      align-items:center;
      gap:6px;
    }
    .btn-primary{background:var(--red-main);color:#fff;}
    .btn-ghost{background:#fff;color:#333;border:1px solid var(--gray-light);}
    .btn:hover{opacity:.9;}
    #overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); display: none; z-index: 98; }
    #overlay.show {display:block;}

    /* Pagination */
    .pager{
      margin-top:14px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:10px;
      font-size:13px;
    }
    .pager .meta{ color:#444; }
    .pager .links{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:flex-end; }
    .pager a, .pager span{
      display:inline-block;
      padding:8px 10px;
      border-radius:8px;
      border:1px solid var(--gray-light);
      background:#fff;
      color:#333;
      text-decoration:none;
      box-shadow:var(--shadow);
      font-family:'Montserrat',sans-serif;
      font-weight:600;
      font-size:12px;
    }
    .pager span.active{
      background:var(--red-light);
      border-color:var(--red-main);
      color:#000;
    }
    .pager a:hover{ opacity:.9; }
    .pager a.disabled{
      pointer-events:none;
      opacity:.45;
    }

    .pill{
      display:inline-block;
      padding:4px 8px;
      border-radius:999px;
      border:1px solid var(--gray-light);
      background:#fff;
      font-size:12px;
      font-family:'Montserrat',sans-serif;
      font-weight:600;
      color:#333;
    }

    /* Student search UI */
.student-search-wrap{
  border:1px solid var(--gray-light);
  border-radius: var(--radius);
  background: #fafafa;
  padding: 12px;
  box-shadow: var(--shadow);
  margin-top: 12px;
}

.student-search-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom: 8px;
}

.student-search-label{
  font-family:'Montserrat',sans-serif;
  font-size: 12px;
  font-weight: 700;
  color:#444;
  letter-spacing:.2px;
  text-transform: uppercase;
}

.student-search-close{
  appearance:none;
  border:1px solid var(--gray-light);
  background:#fff;
  border-radius: 8px;
  width: 32px;
  height: 32px;
  cursor:pointer;
  font-size: 18px;
  line-height: 1;
  display:flex;
  align-items:center;
  justify-content:center;
}
.student-search-close:hover{ opacity:.9; }

.student-search-input{
  margin-bottom: 10px;
  color:#222; /* nicht grey, sonst wirkt disabled */
}

.student-search-results{
  border:1px solid var(--gray-light);
  border-radius: 10px;
  background:#fff;
  overflow:auto;
  max-height: 240px;
  padding: 6px;
}

.student-search-item{
  padding: 10px 10px;
  border-radius: 8px;
  cursor:pointer;
  font-size: 13px;
  line-height: 1.2;
  color:#222;
}
.student-search-item:hover{
  background: #f5f5f5;
}
.student-search-empty{
  padding: 10px;
  font-size: 13px;
  color:#777;
}
  </style>
</head>
<body>
  <main id="content">
    <div class="page">
      <h1>UNCONFIRMED</h1>
      <div class="subtitle">Review and confirm transactions that need manual verification.</div>

      <div class="layout">
        <section class="card">

          <?php if ($success_message): ?>
            <div class="card" style="background:#E7F7E7; color:#2E7D32; padding:12px; border:1px solid #C8E6C9; margin-bottom:14px;">
              <?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>
          <?php if ($error_message): ?>
            <div class="card" style="background:#FCE8E6; color:#B71C1C; padding:12px; border:1px solid #F5C6CB; margin-bottom:14px;">
              <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>

          <table>
            <thead>
              <tr>
                <th>Reference Number</th>
                <th>Ordering Name</th>
                <th>Description</th>
                <th>Transaction Date</th>
                <th class="amount">Amount Paid</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($transactions_result && $transactions_result->num_rows > 0): ?>
                <?php while($row = $transactions_result->fetch_assoc()): ?>
                  <?php $row_mh_id = (int)$row['mh_id']; ?>
                  <tr data-mh-id="<?= $row_mh_id ?>" class="<?= ($active_mh_id === $row_mh_id) ? 'active-row' : '' ?>">
                    <td><?= htmlspecialchars((string)($row['reference_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($row['beneficiary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)($row['reference'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= !empty($row['processing_date']) ? date("d/m/Y", strtotime($row['processing_date'])) : '-' ?></td>
                    <td class="amount"><?= number_format((float)($row['amount_total'] ?? 0), 2, ',', '.') ?> <?= CURRENCY ?></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="5">No unconfirmed transactions found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>

          <!-- Pagination UI -->
          <div class="pager">
            <div class="meta">
              <?= (int)$totalRows ?> results • Page <?= (int)$page ?> / <?= (int)$totalPages ?>
            </div>

            <div class="links">
              <?php
                $baseQuery = http_build_query($paginationBase);

                $prevPage = max(1, $page - 1);
                $nextPage = min($totalPages, $page + 1);

                $prevUrl = "?" . ($baseQuery ? $baseQuery . "&" : "") . "page=" . $prevPage;
                $nextUrl = "?" . ($baseQuery ? $baseQuery . "&" : "") . "page=" . $nextPage;

                $prevClass = ($page <= 1) ? "disabled" : "";
                $nextClass = ($page >= $totalPages) ? "disabled" : "";
              ?>
              <a class="<?= $prevClass ?>" href="<?= htmlspecialchars($prevUrl, ENT_QUOTES, 'UTF-8') ?>">&laquo; Prev</a>

              <?php
                $window = 2;
                $start = max(1, $page - $window);
                $end   = min($totalPages, $page + $window);

                if ($start > 1) {
                  $u = "?" . ($baseQuery ? $baseQuery . "&" : "") . "page=1";
                  echo '<a href="'.htmlspecialchars($u, ENT_QUOTES, 'UTF-8').'">1</a>';
                  if ($start > 2) echo '<span>…</span>';
                }

                for ($p = $start; $p <= $end; $p++) {
                  if ($p === $page) {
                    echo '<span class="active">'.(int)$p.'</span>';
                  } else {
                    $u = "?" . ($baseQuery ? $baseQuery . "&" : "") . "page=" . $p;
                    echo '<a href="'.htmlspecialchars($u, ENT_QUOTES, 'UTF-8').'">'.(int)$p.'</a>';
                  }
                }

                if ($end < $totalPages) {
                  if ($end < $totalPages - 1) echo '<span>…</span>';
                  $u = "?" . ($baseQuery ? $baseQuery . "&" : "") . "page=" . $totalPages;
                  echo '<a href="'.htmlspecialchars($u, ENT_QUOTES, 'UTF-8').'">'.(int)$totalPages.'</a>';
                }
              ?>

              <a class="<?= $nextClass ?>" href="<?= htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>">Next &raquo;</a>
            </div>
          </div>
        </section>

        <aside class="stack">
          <div class="stats">
            <div class="stat"><div class="num"><?= (int)$pending ?></div><div class="label">Pending</div></div>
            <div class="stat"><div class="num"><?= (int)$confirmed ?></div><div class="label">Confirmed</div></div>
          </div>

          <?php if ($suggestion_mh_id): ?>
          <div class="card">
            <div class="side-title">Manual confirmation</div>

            <div style="font-size:13px;color:#333;line-height:1.4;margin-bottom:10px;">
              <b>Invoice:</b>
              <?= htmlspecialchars($suggestion_invoice_ref ?: ("INV-" . (int)$suggestion_invoice_id), ENT_QUOTES, 'UTF-8') ?><br>

              <b>Ordering name:</b> <?= htmlspecialchars($suggestion_invoice_beneficiary, ENT_QUOTES, 'UTF-8') ?><br>
              <b>Reference:</b> <?= htmlspecialchars($suggestion_invoice_reference, ENT_QUOTES, 'UTF-8') ?><br>
              <?php if ($suggestion_invoice_desc !== ''): ?>
                <b>Description:</b> <?= htmlspecialchars($suggestion_invoice_desc, ENT_QUOTES, 'UTF-8') ?><br>
              <?php endif; ?>
              <b>Amount:</b> <?= number_format((float)$suggestion_amount, 2, ',', '.') ?> <?= CURRENCY ?><br>
              <b>Date:</b> <?= $suggestion_processing_date ? date("d/m/Y", strtotime($suggestion_processing_date)) : '-' ?><br>

              <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                <?php if ($suggestion_matched_by !== ''): ?>
                  <span class="pill"><?= htmlspecialchars($suggestion_matched_by, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <?php if ($suggestion_confidence !== null): ?>
                  <span class="pill"><?= htmlspecialchars(rtrim(rtrim(number_format((float)$suggestion_confidence, 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') ?>%</span>
                <?php endif; ?>
              </div>
            </div>

            <form method="post" action="">
              <input type="hidden" name="mh_id" value="<?= (int)$suggestion_mh_id ?>">

              <select name="student_id" class="side-input" required>
                <option value="">-- Select Student --</option>
                <?php
                $students_sql = "SELECT id, long_name FROM STUDENT_TAB ORDER BY long_name ASC";
                $students_res = $conn->query($students_sql);
                if ($students_res) {
                    while ($s = $students_res->fetch_assoc()) {
                        $sid = (int)$s['id'];
                        $selected = ($suggestion_student_id > 0 && $sid === (int)$suggestion_student_id) ? 'selected' : '';
                        $label = (string)$s['long_name'];
                        echo '<option value="' . $sid . '" ' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
                    }
                }
                ?>
              </select>
            <div id="studentSearchWrap" class="student-search-wrap" style="display:none;">
  <div class="student-search-head">
    <span class="student-search-label">Search</span>
    <button type="button" id="studentSearchClose" class="student-search-close" aria-label="Close">×</button>
  </div>

  <input
    type="text"
    id="studentSearchInput"
    class="side-input student-search-input"
    placeholder="Search student…"
    autocomplete="off"
  />

  <div id="studentSearchResults" class="student-search-results" style="display:none;"></div>
</div>

              <div class="card" style="margin-top:12px; padding:10px; background:#f9f9f9;">
                <div class="side-title">Connection Reason</div>
                <p class="reason-text"><?= htmlspecialchars($reason_text ?: 'No metadata available.', ENT_QUOTES, 'UTF-8') ?></p>
                <?php if ($suggestion_student_id > 0): ?>
                  <p class="reason-text" style="margin-top:8px;">
                    <b>Suggested student:</b> <?= htmlspecialchars($suggestion_student_name ?: ("Student #" . (int)$suggestion_student_id), ENT_QUOTES, 'UTF-8') ?>
                  </p>
                <?php endif; ?>
              </div>

              <div style="margin-top:12px;display:flex;">
                <button type="submit" name="confirm_invoice" class="btn btn-primary">
                  <span class="material-icons-outlined_toggleSidebar"></span>Confirm
                </button>
              </div>
            </form>
          </div>
          <?php endif; ?>
        </aside>
      </div>
    </div>
  </main>

  <?php include 'filters.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Inject filter icon once
  const navLeft = document.querySelector('.nav-left');
  if (navLeft && !document.getElementById('filterToggle')) {
    const filterIcon = document.createElement('span');
    filterIcon.id = 'filterToggle';
    filterIcon.className = 'nav-icon material-icons-outlined';
    filterIcon.textContent = 'filter_list';
    filterIcon.style.cursor = 'pointer';
    navLeft.appendChild(filterIcon);
  }
  // --- Student live search when '-- Select Student --' is selected ---
const studentSelect = document.querySelector('select[name="student_id"]');
const wrap = document.getElementById('studentSearchWrap');
const input = document.getElementById('studentSearchInput');
const resultsBox = document.getElementById('studentSearchResults');

if (studentSelect && wrap && input && resultsBox) {

  // Cache all real options (exclude placeholder value="")
  const allStudents = Array.from(studentSelect.options)
    .filter(o => o.value !== "")
    .map(o => ({ value: o.value, text: o.text }));

 function renderResults(list) {
  resultsBox.innerHTML = "";

  if (!list.length) {
    resultsBox.style.display = "block";
    resultsBox.innerHTML = '<div class="student-search-empty">No results.</div>';
    return;
  }

  resultsBox.style.display = "block";

  list.slice(0, 30).forEach(item => {
    const row = document.createElement('div');
    row.className = 'student-search-item';
    row.textContent = item.text;

    row.addEventListener('click', () => {
      studentSelect.value = item.value;
      wrap.style.display = "none";
      input.value = "";
      resultsBox.innerHTML = "";
      resultsBox.style.display = "none";
    });

    resultsBox.appendChild(row);
  });
}

  function updateVisibility() {
    if (studentSelect.value === "") {
  wrap.style.display = "block";
  input.focus();
  resultsBox.innerHTML = ""; // show nothing until typing
} else {
      wrap.style.display = "none";
    }
  }

  // When placeholder selected -> show search
  studentSelect.addEventListener('change', updateVisibility);

  // Live filter while typing
  input.addEventListener('input', () => {
    const q = input.value.toLowerCase().trim();
    if (q === "") {
  resultsBox.innerHTML = ""; // nothing until typing
  return;
}
const filtered = allStudents.filter(s => s.text.toLowerCase().includes(q));
renderResults(filtered);
  });

  // Also show search if user clicks the placeholder again
  studentSelect.addEventListener('mousedown', () => {
    // If currently placeholder, show search instantly
  if (studentSelect.value === "") {
  studentSelect.selectedIndex = 0;
}
  });

  // Init state on load (in case value is placeholder)
  updateVisibility();
}

  // Row click -> reload with selected mh_id (keep query params)
  document.querySelectorAll('tbody tr[data-mh-id]').forEach(tr => {
    tr.addEventListener('click', () => {
      const id = tr.getAttribute('data-mh-id');
      if (!id) return;

      const url = new URL(window.location.href);
      url.searchParams.set('mh_id', id);
      window.location.href = url.toString();
    });
  });
  const closeBtn = document.getElementById('studentSearchClose');

if (closeBtn) {
  closeBtn.addEventListener('click', () => {

    // Search UI schließen
    wrap.style.display = 'none';

    // Input & Results resetten
    input.value = '';
    resultsBox.innerHTML = '';
    resultsBox.style.display = 'none';

    studentSelect.focus();
  });
}
});
</script>
</body>
</html>
<?php
// cleanup statement
if (isset($transactions_stmt) && $transactions_stmt instanceof mysqli_stmt) {
    $transactions_stmt->close();
}
?>