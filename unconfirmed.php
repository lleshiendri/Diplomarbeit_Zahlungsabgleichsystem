<?php   
require_once 'auth_check.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'navigator.php'; 
require 'db_connect.php';
require 'matching_functions.php';

$success_message = "";
$error_message = "";

// Handle admin confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_invoice'])) {
    $invoice_id = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : 0;
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    
    if ($invoice_id > 0 && $student_id > 0) {
        // Get invoice details to check if it's a reference-id match
        $invoice_stmt = $conn->prepare("SELECT reference_number, processing_date FROM INVOICE_TAB WHERE id = ?");
        $reference_number = null;
        $processing_date = null;
        if ($invoice_stmt) {
            $invoice_stmt->bind_param("i", $invoice_id);
            $invoice_stmt->execute();
            $invoice_result = $invoice_stmt->get_result();
            if ($invoice_row = $invoice_result->fetch_assoc()) {
                $reference_number = $invoice_row['reference_number'];
                $processing_date = $invoice_row['processing_date'];
            }
            $invoice_stmt->close();
        }
        
        // Check if this was matched by reference-id (check matching history)
        $match_stmt = $conn->prepare("SELECT matched_by FROM MATCHING_HISTORY_TAB WHERE invoice_id = ? ORDER BY created_at DESC LIMIT 1");
        $matched_by = 'manual';
        if ($match_stmt) {
            $match_stmt->bind_param("i", $invoice_id);
            $match_stmt->execute();
            $match_result = $match_stmt->get_result();
            if ($match_row = $match_result->fetch_assoc()) {
                $matched_by = $match_row['matched_by'];
            }
            $match_stmt->close();
        }
        
        // If reference_number exists, treat as reference-id match
        if (!empty($reference_number)) {
            $matched_by = 'reference';
        }
        
        // Update invoice with student_id
        $stmt = $conn->prepare("UPDATE INVOICE_TAB SET student_id = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $student_id, $invoice_id);
            if ($stmt->execute()) {
                // Log manual confirmation to MATCHING_HISTORY_TAB
                logMatchingAttempt($conn, $invoice_id, $student_id, 100.0, $matched_by, true);
                
                // Check and create late-fee notification if needed (only for reference-id matches)
                if ($matched_by === 'reference' && $processing_date) {
                    require_once 'matching_functions.php';
                    checkAndCreateLateFeeNotification($conn, $student_id, $invoice_id, $processing_date, $matched_by);
                }
                
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

// 1) Unbestätigte Transaktionen aus INVOICE_TAB (student_id IS NULL)
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

// 2) Stat-Karten (Pending/Confirmed)
$pending_sql = "SELECT COUNT(*) AS c FROM INVOICE_TAB WHERE student_id IS NULL";
$pending_res = $conn->query($pending_sql);
$pending = $pending_res ? (int)$pending_res->fetch_assoc()['c'] : 0;

$confirmed_sql = "SELECT COUNT(*) AS c FROM INVOICE_TAB WHERE student_id IS NOT NULL";
$confirmed_res = $conn->query($confirmed_sql);
$confirmed = $confirmed_res ? (int)$confirmed_res->fetch_assoc()['c'] : 0;

// 3) Get first unconfirmed invoice for suggestions
$suggestion_invoice_id = null;
$suggestion_student_id = null;
$suggestion_student_name = "";
$suggestion_guardian = "";
$reason_text = "";

$suggestion_sql = "
    SELECT id, reference_number, beneficiary, reference
    FROM INVOICE_TAB
    WHERE student_id IS NULL
    ORDER BY id DESC
    LIMIT 1
";
$suggestion_res = $conn->query($suggestion_sql);

if ($suggestion_res && $row = $suggestion_res->fetch_assoc()) {
    $suggestion_invoice_id = (int)$row['id'];
    $reference_number = $row['reference_number'] ?? '';
    $beneficiary = $row['beneficiary'] ?? '';
    $reference = $row['reference'] ?? '';
    
    // Run matching algorithm to get suggestion
    $match_result = matchInvoiceToStudent($conn, $reference_number, $beneficiary, $reference);
    
    if ($match_result['student_id']) {
        $suggestion_student_id = $match_result['student_id'];
        // Get student name
        $student_stmt = $conn->prepare("SELECT id, long_name FROM STUDENT_TAB WHERE id = ?");
        if ($student_stmt) {
            $student_stmt->bind_param("i", $suggestion_student_id);
            $student_stmt->execute();
            $student_res = $student_stmt->get_result();
            if ($student_row = $student_res->fetch_assoc()) {
                $suggestion_student_name = $student_row['long_name'];
                $reason_text = "Matched by " . $match_result['matched_by'] . " (confidence: " . number_format($match_result['confidence'], 1) . "%)";
            }
            $student_stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Unconfirmed</title>

  <!-- Fonts & Icons -->
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
    body{
      margin:0;
      font-family:'Roboto',sans-serif;
      color:black;
      background:#fff;
    }
    #content {
      transition: margin-left 0.3s ease;
      margin-left: 0;
      padding: 100px 30px 60px;
    }
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
    tbody tr:hover {
      background-color:#f5f5f5;
      transition:background-color 0.2s ease-in-out;
      cursor:pointer;
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
    #overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,.4);
      display: none;
      z-index: 98;
    }
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
                    <td><?= htmlspecialchars($row['reference_number']) ?></td>
                    <td><?= htmlspecialchars($row['beneficiary']) ?></td>
                    <td><?= htmlspecialchars($row['reference']) ?></td>
                    <td><?= $row['processing_date'] ? date("d/m/Y", strtotime($row['processing_date'])) : '-' ?></td>
                    <td class="amount"><?= number_format($row['amount_total'], 2, ',', '.') ?> <?= CURRENCY ?></td>
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
              <?= htmlspecialchars($success_message) ?>
            </div>
          <?php endif; ?>
          <?php if ($error_message): ?>
            <div class="card" style="background:#FCE8E6; color:#B71C1C; padding:12px; border:1px solid #F5C6CB;">
              <?= htmlspecialchars($error_message) ?>
            </div>
          <?php endif; ?>

          <div class="stats">
            <div class="stat"><div class="num"><?= $pending ?></div><div class="label">Pending</div></div>
            <div class="stat"><div class="num"><?= $confirmed ?></div><div class="label">Confirmed</div></div>
          </div>

          <?php if ($suggestion_invoice_id): ?>
          <div class="card">
            <div class="side-title">Suggested Student</div>
            <form method="post" action="">
              <input type="hidden" name="invoice_id" value="<?= $suggestion_invoice_id ?>">
              <select name="student_id" class="side-input" required>
                <option value="">-- Select Student --</option>
                <?php
                // Get all students for dropdown
                $students_sql = "SELECT id, long_name FROM STUDENT_TAB ORDER BY long_name ASC";
                $students_res = $conn->query($students_sql);
                if ($students_res) {
                    while ($s = $students_res->fetch_assoc()) {
                        $selected = ($suggestion_student_id == $s['id']) ? 'selected' : '';
                        echo '<option value="' . (int)$s['id'] . '" ' . $selected . '>' . htmlspecialchars($s['long_name']) . '</option>';
                    }
                }
                ?>
              </select>
              
              <div class="card" style="margin-top:12px; padding:10px; background:#f9f9f9;">
                <div class="side-title">Connection Reason</div>
                <p class="reason-text"><?= htmlspecialchars($reason_text ?: 'No automatic match found') ?></p>
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

  <!-- Filter Panel aus externer Datei -->
  <?php include 'filters.php'; ?>

  <script>
    // Sidebar Steuerung
    const sidebar   = document.getElementById("sidebar");
    const content   = document.getElementById("content");
    const overlay   = document.getElementById("overlay");
    function openSidebar(){ sidebar.classList.add("open"); content.classList.add("shifted"); overlay.classList.add("show"); }
    function closeSidebar(){ sidebar.classList.remove("open"); content.classList.remove("shifted"); overlay.classList.remove("show"); }
    function toggleSidebar(){
       sidebar.classList.contains("open") ? closeSidebar() : openSidebar(); 
      }

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