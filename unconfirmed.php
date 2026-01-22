<?php
require_once 'auth_check.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'navigator.php';
require 'db_connect.php';
require_once 'matching_functions.php';

$success_message = "";
$error_message = "";

/* ===============================================================
   HANDLE ADMIN CONFIRMATION (manual assign)
   =============================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_invoice'])) {
    $invoice_id = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

    if ($invoice_id > 0 && $student_id > 0) {

        $stmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $student_id, $invoice_id);

            if ($stmt->execute()) {

                // 1) Log manual confirmation
                logMatchingAttempt($conn, $invoice_id, $student_id, 100.0, 'manual', true);

                // 2) Fetch invoice meta (processing_date + reference_number)
                $meta = getInvoiceMeta($conn, $invoice_id);
                $processing_date = $meta['processing_date'] ?: date('Y-m-d H:i:s');

                $invoice_reference = $meta['reference_number'];
                if ($invoice_reference === null || trim((string)$invoice_reference) === '') {
                    $invoice_reference = "INV-" . (int)$invoice_id;
                }

                // INFO notification (always)
createNotificationOnce(
    $conn,
    "info",
    $student_id,
    $invoice_reference,
    date('Y-m-d', strtotime($processing_date)),
    "Confirmed manually: $invoice_reference assigned to Student #$student_id"
);

// LATE FEE check immediately (Rule A)
maybeCreateLateFeeUrgent($conn, $student_id, $invoice_reference, $processing_date);


                $success_message = "Invoice confirmed and assigned to student.";

            } else {
                $error_message = "Error updating invoice: " . $stmt->error;
            }

            $stmt->close();
        } else {
            $error_message = "Statement error: " . $conn->error;
        }

    } else {
        $error_message = "Invalid invoice or student ID.";
    }
}

/* ===============================================================
   1) UNCONFIRMED TRANSACTIONS (student_id IS NULL)
   =============================================================== */
$transactions_sql = "
    SELECT 
        id,
        reference_number,
        beneficiary,
        reference,
        amount_total,
        processing_date
    FROM INVOICE_TAB
    WHERE student_id IS NULL
    ORDER BY id DESC
    LIMIT 50
";
$transactions_result = $conn->query($transactions_sql);

/* ===============================================================
   2) STAT CARDS
   =============================================================== */
$pending_sql = "SELECT COUNT(*) AS c FROM INVOICE_TAB WHERE student_id IS NULL";
$pending_res = $conn->query($pending_sql);
$pending = $pending_res ? (int)$pending_res->fetch_assoc()['c'] : 0;

$confirmed_sql = "SELECT COUNT(*) AS c FROM INVOICE_TAB WHERE student_id IS NOT NULL";
$confirmed_res = $conn->query($confirmed_sql);
$confirmed = $confirmed_res ? (int)$confirmed_res->fetch_assoc()['c'] : 0;

/* ===============================================================
   3) SUGGESTION BOX (first unconfirmed invoice)
   =============================================================== */
$suggestion_invoice_id = null;
$suggestion_student_id = null;
$reason_text = "";
$suggestion_invoice_ref = "";
$suggestion_invoice_beneficiary = "";
$suggestion_invoice_reference = "";
$suggestion_guardian = "";

$suggestion_sql = "
    SELECT id, reference_number, beneficiary, reference, processing_date
    FROM INVOICE_TAB
    WHERE student_id IS NULL
    ORDER BY id DESC
    LIMIT 1
";
$suggestion_res = $conn->query($suggestion_sql);

if ($suggestion_res && ($row = $suggestion_res->fetch_assoc())) {

    // ✅ IMPORTANT: without this, sidebar will never render
    $suggestion_invoice_id = (int)$row['id'];

    $suggestion_invoice_ref = (string)($row['reference_number'] ?? '');
    $suggestion_invoice_beneficiary = (string)($row['beneficiary'] ?? '');
    $suggestion_invoice_reference = (string)($row['reference'] ?? '');

    $ordering_name = trim($suggestion_invoice_beneficiary);

    if ($ordering_name !== "") {
        $last_name_parts = preg_split('/\s+/', $ordering_name);
        $last_name = $last_name_parts ? end($last_name_parts) : "";

        if ($last_name !== "") {

            // Student suggestion (id + name)
            $student_sql = "
                SELECT id, long_name
                FROM STUDENT_TAB
                WHERE long_name LIKE '%" . $conn->real_escape_string($last_name) . "%'
                LIMIT 1
            ";
            $student_res = $conn->query($student_sql);
            if ($student_res && ($student_row = $student_res->fetch_assoc())) {
                $suggestion_student_id = (int)$student_row['id'];
                $reason_text = "Student and ordering party share the same last name.";
            }

            // Guardian suggestion
            $guardian_sql = "
                SELECT CONCAT(first_name, ' ', last_name) AS fullname
                FROM LEGAL_GUARDIAN_TAB
                WHERE last_name = '" . $conn->real_escape_string($last_name) . "'
                LIMIT 1
            ";
            $guardian_res = $conn->query($guardian_sql);
            if ($guardian_res && ($guardian_row = $guardian_res->fetch_assoc())) {
                $suggestion_guardian = (string)$guardian_row['fullname'];
                if ($reason_text === "") {
                    $reason_text = "Legal guardian and ordering party share the same last name.";
                }
            }
        }
    }

    if ($reason_text === "") {
        $reason_text = "No automatic match found.";
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
  </style>
</head>
<body>
  <main id="content">
    <div class="page">
      <h1>UNCONFIRMED</h1>
      <div class="subtitle">Review and confirm transactions that need manual verification.</div>

      <div class="layout">
        <section class="card">
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
                  <tr>
                    <td><?= htmlspecialchars($row['reference_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['beneficiary'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['reference'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= !empty($row['processing_date']) ? date("d/m/Y", strtotime($row['processing_date'])) : '-' ?></td>
                    <td class="amount"><?= number_format((float)$row['amount_total'], 2, ',', '.') ?> <?= CURRENCY ?></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="5">No unconfirmed transactions found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </section>

        <aside class="stack">
          <?php if ($success_message): ?>
            <div class="card" style="background:#E7F7E7; color:#2E7D32; padding:12px; border:1px solid #C8E6C9;">
              <?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>
          <?php if ($error_message): ?>
            <div class="card" style="background:#FCE8E6; color:#B71C1C; padding:12px; border:1px solid #F5C6CB;">
              <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>

          <div class="stats">
            <div class="stat"><div class="num"><?= (int)$pending ?></div><div class="label">Pending</div></div>
            <div class="stat"><div class="num"><?= (int)$confirmed ?></div><div class="label">Confirmed</div></div>
          </div>

          <?php if ($suggestion_invoice_id): ?>
          <div class="card">
            <div class="side-title">Manual confirmation</div>

            <div style="font-size:13px;color:#333;line-height:1.4;margin-bottom:10px;">
              <b>Invoice:</b> <?= htmlspecialchars($suggestion_invoice_ref ?: ("INV-" . (int)$suggestion_invoice_id), ENT_QUOTES, 'UTF-8') ?><br>
              <b>Ordering name:</b> <?= htmlspecialchars($suggestion_invoice_beneficiary, ENT_QUOTES, 'UTF-8') ?><br>
              <b>Description:</b> <?= htmlspecialchars($suggestion_invoice_reference, ENT_QUOTES, 'UTF-8') ?><br>
              <?php if (!empty($suggestion_guardian)): ?>
                <b>Guardian hint:</b> <?= htmlspecialchars($suggestion_guardian, ENT_QUOTES, 'UTF-8') ?><br>
              <?php endif; ?>
            </div>

            <form method="post" action="">
              <input type="hidden" name="invoice_id" value="<?= (int)$suggestion_invoice_id ?>">

              <select name="student_id" class="side-input" required>
                <option value="">-- Select Student --</option>
                <?php
                $students_sql = "SELECT id, long_name FROM STUDENT_TAB ORDER BY long_name ASC";
                $students_res = $conn->query($students_sql);
                if ($students_res) {
                    while ($s = $students_res->fetch_assoc()) {
                        $selected = ($suggestion_student_id !== null && (int)$suggestion_student_id === (int)$s['id']) ? 'selected' : '';
                        echo '<option value="' . (int)$s['id'] . '" ' . $selected . '>' . htmlspecialchars($s['long_name'], ENT_QUOTES, 'UTF-8') . '</option>';
                    }
                }
                ?>
              </select>

              <div class="card" style="margin-top:12px; padding:10px; background:#f9f9f9;">
                <div class="side-title">Connection Reason</div>
                <p class="reason-text"><?= htmlspecialchars($reason_text, ENT_QUOTES, 'UTF-8') ?></p>
              </div>

              <div style="margin-top:12px;display:flex;gap:10px;">
                <button type="submit" name="confirm_invoice" class="btn btn-primary">
                  <span class="material-icons-outlined">check_circle</span> Confirm
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
    const sidebar   = document.getElementById("sidebar");
    const content   = document.getElementById("content");
    const overlay   = document.getElementById("overlay");

    function openSidebar(){ sidebar.classList.add("open"); content.classList.add("shifted"); overlay.classList.add("show"); }
    function closeSidebar(){ sidebar.classList.remove("open"); content.classList.remove("shifted"); overlay.classList.remove("show"); }
    function toggleSidebar(){ sidebar.classList.contains("open") ? closeSidebar() : openSidebar(); }

    document.addEventListener('DOMContentLoaded', () => {
      const navLeft = document.querySelector('.nav-left');
      if (navLeft && !document.getElementById('filterToggle')) {
        const filterIcon = document.createElement('span');
        filterIcon.id = 'filterToggle';
        filterIcon.className = 'nav-icon material-icons-outlined';
        filterIcon.textContent = 'filter_list';
        filterIcon.style.cursor = 'pointer';
        navLeft.appendChild(filterIcon);
      }

      const filterPanel = document.getElementById("filterPanel");
      const filterOverlay = document.getElementById("filterOverlay");
      const filterToggle = document.getElementById("filterToggle");

      if (filterToggle) {
        filterToggle.addEventListener("click", () => {
          filterPanel.classList.toggle("open");
          filterOverlay.classList.toggle("show");
        });
      }

      if (filterOverlay) {
        filterOverlay.addEventListener("click", () => {
          filterPanel.classList.remove("open");
          filterOverlay.classList.remove("show");
        });
      }
    });
  </script>
</body>
</html>
