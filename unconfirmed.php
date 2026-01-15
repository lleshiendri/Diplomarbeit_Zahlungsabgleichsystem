<?php   
require_once 'auth_check.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'navigator.php'; 
require 'db_connect.php';

// 1) Unbestätigte Transaktionen aus INVOICE_TAB
$transactions_sql = "
    SELECT 
        reference_number,
        beneficairy,             
        reference,
        amount_total,
        processing_date
    FROM INVOICE_TAB
    WHERE processing_date IS NULL
    ORDER BY id DESC
    LIMIT 50
";
$transactions_result = $conn->query($transactions_sql);

// 2) Stat-Karten (Pending/Confirmed)
$pending_sql = "SELECT COUNT(*) AS c FROM INVOICE_TAB WHERE processing_date IS NULL";
$pending_res = $conn->query($pending_sql);
$pending = $pending_res ? (int)$pending_res->fetch_assoc()['c'] : 0;

$confirmed_sql = "SELECT COUNT(*) AS c FROM INVOICE_TAB WHERE processing_date IS NOT NULL";
$confirmed_res = $conn->query($confirmed_sql);
$confirmed = $confirmed_res ? (int)$confirmed_res->fetch_assoc()['c'] : 0;

// 3) Vorschläge: Passender Student/Legal Guardian
// anhand des Ordering Names (beneficairy)
$suggestion_student   = "";
$suggestion_guardian  = "";
$reason_text          = "";

$suggestion_sql = "
    SELECT beneficairy
    FROM INVOICE_TAB
    WHERE processing_date IS NULL
    ORDER BY id DESC
    LIMIT 1
";
$suggestion_res = $conn->query($suggestion_sql);

if ($suggestion_res && $row = $suggestion_res->fetch_assoc()) {
    $ordering_name = trim($row['beneficairy']);
    if (!empty($ordering_name)) {
        
        $last_name_parts = preg_split('/\s+/', $ordering_name);
        $last_name = end($last_name_parts);
        $student_sql = "
            SELECT long_name
            FROM STUDENT_TAB
            WHERE long_name LIKE '%".$conn->real_escape_string($last_name)."%'
            LIMIT 1
        ";
        $student_res = $conn->query($student_sql);
        if ($student_res && $student_row = $student_res->fetch_assoc()) {
            $suggestion_student = $student_row['long_name'];
            $reason_text = "Student and ordering party share the same last name.";
        }

        $guardian_sql = "
            SELECT CONCAT(first_name, ' ', last_name) AS fullname
            FROM LEGAL_GUARDIAN_TAB
            WHERE last_name = '".$conn->real_escape_string($last_name)."'
            LIMIT 1
        ";
        $guardian_res = $conn->query($guardian_sql);
        if ($guardian_res && $guardian_row = $guardian_res->fetch_assoc()) {
            $suggestion_guardian = $guardian_row['fullname'];
            if (empty($reason_text)) {
                $reason_text = "Legal guardian and ordering party share the same last name.";
            }
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
                    <td><?= htmlspecialchars($row['beneficairy']) ?></td>
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
          <div class="stats">
            <div class="stat"><div class="num"><?= $pending ?></div><div class="label">Pending</div></div>
            <div class="stat"><div class="num"><?= $confirmed ?></div><div class="label">Confirmed</div></div>
          </div>

          <div class="card">
            <div class="side-title">Suggested Student</div>
            <input class="side-input" type="text" value="<?= htmlspecialchars($suggestion_student) ?>">
          </div>

          <div class="card">
            <div class="side-title">Suggested Legal Guardian</div>
            <input class="side-input" type="text" value="<?= htmlspecialchars($suggestion_guardian) ?>">
          </div>

          <div class="card">
            <div class="side-title">Connection Reason</div>
            <p class="reason-text"><?= htmlspecialchars($reason_text) ?></p>

            <div style="margin-top:12px;display:flex;gap:10px;">
              <button class="btn btn-primary"><span class="material-icons-outlined">check_circle</span> Confirm</button>
              <button class="btn btn-ghost"><span class="material-icons-outlined">edit</span> Edit</button>
            </div>  
          </div>
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