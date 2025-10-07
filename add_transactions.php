<?php 
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Wichtig: currentPage VOR navigator.php setzen
$currentPage = basename($_SERVER['PHP_SELF']);

require 'navigator.php'; 
require 'db_connect.php';

// 1) Datensatz speichern bei Formularabsendung
$success_message = "";
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ref_number       = trim($_POST['ref_number'] ?? '');
    $ordering_name    = trim($_POST['ordering_name'] ?? '');
    $transaction_date = $_POST['transaction_date'] ?? '';
    $description      = trim($_POST['description'] ?? '');
    $amount           = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;

    if ($ref_number && $ordering_name && $transaction_date && $amount) {
        $stmt = $conn->prepare("
            INSERT INTO INVOICE_TAB (reference_number, beneficairy, reference, processing_date, amount_total) 
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("ssssd", $ref_number, $ordering_name, $description, $transaction_date, $amount);
            if ($stmt->execute()) {
                $success_message = "Transaction was successfully added.";
            } else {
                $error_message = "Error while saving: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Statement error: " . $conn->error;
        }
    } else {
        $error_message = "Please fill out all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <title>Add Transaction</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <style>
        :root{
            --red-dark:#B31E32;
            --red-main:#D4463B;
            --red-light:#FAE4D5;
            --off-white:#FFF8EB;
            --gray-light:#E3E5E0;
        }
        body{ margin:0; font-family:'Roboto',sans-serif; background:#fff; color:#222; }

        /* Content verschiebbar wie bei unconfirmed */
        #content {
            transition: margin-left 0.3s ease;
            margin-left: 0;
            padding: 100px 30px 30px; /* 100px wegen fixer Header aus navigator.php */
        }
        #content.shifted { margin-left: 100px; }

        /* Overlay für Sidebar (navigator.php liefert es nicht) */
        #overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.4);
            display: none;
            z-index: 98;
        }
        #overlay.show { display:block; }

        h1{
            font-family:'Space Grotesk',sans-serif;
            color:var(--red-dark);
            margin-bottom:20px;
            text-align:center;
        }

        .form-card{
            background:#FFF8EB;
            border:1px solid var(--gray-light);
            border-radius:10px;
            padding:30px;
            max-width:800px;
            margin:0 auto;
            box-shadow:0 1px 3px rgba(0,0,0,.08);
        }
        .form-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:20px;
            margin-bottom:20px;
        }
        label{
            font-family:'Montserrat',sans-serif;
            font-weight:600;
            display:block;
            margin-bottom:6px;
            color:#333;
        }
        .input-group{
            display:flex;
            align-items:center;
            border:1px solid var(--gray-light);
            border-radius:6px;
            background:#fff;
            padding:0 10px;
        }
        .input-group .material-icons-outlined{
            color:#888;
            margin-right:6px;
        }
        .input-group input,
        .input-group textarea{
            border:none;
            outline:none;
            flex:1;
            padding:10px;
            font-size:14px;
            font-family:'Roboto',sans-serif;
            background:transparent;
        }
        textarea{ resize:vertical; min-height:90px; }
        .full-width{ grid-column:1 / span 2; }

        .save-btn{
            display:block;
            width:100%;
            padding:12px;
            border:none;
            border-radius:6px;
            background:var(--red-main);
            color:#fff;
            font-family:'Montserrat',sans-serif;
            font-weight:600;
            cursor:pointer;
            font-size:15px;
            transition:.2s;
        }
        .save-btn:hover{ background:var(--red-dark); }

        .message{
            max-width:800px;
            margin:0 auto 20px;
            text-align:center;
            font-family:'Roboto',sans-serif;
            padding:10px;
            border-radius:6px;
        }
        .success{ background:#E7F7E7; color:#2E7D32; border:1px solid #C8E6C9; }
        .error{ background:#FCE8E6; color:#B71C1C; border:1px solid #F5C6CB; }
    </style>
</head>
<body>

<!-- Inhalt -->
<div id="content">
    <h1>Add Transaction</h1>

    <?php if ($success_message): ?>
        <div class="message success"><?= $success_message ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="message error"><?= $error_message ?></div>
    <?php endif; ?>

    <div class="form-card">
        <form method="post" action="">
            <div class="form-grid">
                <div>
                    <label for="ref_number">Reference Number</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">tag</span>
                        <input type="text" id="ref_number" name="ref_number" placeholder="Enter Reference Number" required>
                    </div>
                </div>
                <div>
                    <label for="ordering_name">Ordering Name</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">person</span>
                        <input type="text" id="ordering_name" name="ordering_name" placeholder="Enter Ordering Name" required>
                    </div>
                </div>
                <div>
                    <label for="transaction_date">Transaction Date</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">event</span>
                        <input type="date" id="transaction_date" name="transaction_date" required>
                    </div>
                </div>
                <div>
                    <label for="description">Description</label>
                    <div class="input-group" style="align-items:flex-start;">
                        <span class="material-icons-outlined">description</span>
                        <textarea id="description" name="description" placeholder="Enter Description..."></textarea>
                    </div>
                </div>
                <div class="full-width">
                    <label for="amount">Amount Paid</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">euro</span>
                        <input type="number" step="0.01" id="amount" name="amount" placeholder="Enter Amount" required>
                    </div>
                </div>
            </div>

            <button type="submit" class="save-btn">Save Transaction</button>
        </form>
    </div>
</div>

<!-- Sidebar/Global Overlay (für Sidebar) -->
<div id="overlay" class="overlay" onclick="closeSidebar(); closeFilter();"></div>

<!-- Filters einbinden (Panel + eigener filterOverlay aus der Datei) -->
<?php include 'filters.html'; ?>

<script>
  // ---- Sidebar Toggle wie bei unconfirmed.php ----
  const sidebar = document.getElementById("sidebar"); // kommt aus navigator.php
  const content = document.getElementById("content");
  const overlay = document.getElementById("overlay");

  function openSidebar(){
    if (!sidebar) return;
    sidebar.classList.add("open");
    content && content.classList.add("shifted");
    overlay && overlay.classList.add("show");
  }
  function closeSidebar(){
    if (!sidebar) return;
    sidebar.classList.remove("open");
    content && content.classList.remove("shifted");
    overlay && overlay.classList.remove("show");
  }
  function toggleSidebar(){ (sidebar && sidebar.classList.contains("open")) ? closeSidebar() : openSidebar(); }

  // ---- Filter Icon + Home Icon dynamisch in Header einsetzen (ohne navigator.php zu ändern) ----
  document.addEventListener('DOMContentLoaded', () => {
    const navLeft = document.querySelector('.nav-left'); // Header-Container aus navigator.php
    if (navLeft) {
      // Filter-Icon nur hinzufügen, wenn es nicht schon existiert
      if (!document.getElementById('filterToggle')) {
        const filterSpan = document.createElement('span');
        filterSpan.id = 'filterToggle';
        filterSpan.className = 'nav-icon material-icons-outlined';
        filterSpan.textContent = 'filter_list';
        filterSpan.style.cursor = 'pointer';
        navLeft.appendChild(filterSpan);
      }
      // Home-Icon nur hinzufügen, wenn es nicht schon existiert
      if (!document.getElementById('homeLink')) {
        const homeLink = document.createElement('a');
        homeLink.id = 'homeLink';
        homeLink.href = 'dashboard.php';
        homeLink.className = 'nav-icon material-icons-outlined';
        homeLink.textContent = 'home';
        homeLink.style.textDecoration = 'none';
        homeLink.style.color = 'white';
        navLeft.appendChild(homeLink);
      }
    }

    // Filter-Handlers binden
    attachFilterHandlers();
  });

  // ---- Filter Panel Steuerung (nutzt Markup aus filters.html) ----
  function attachFilterHandlers(){
    const filterPanel   = document.getElementById("filterPanel");     // aus filters.html
    const filterOverlay = document.getElementById("filterOverlay");   // aus filters.html
    const filterToggle  = document.getElementById("filterToggle");    // Icon oben

    function closeFilter(){
      if (filterPanel)   filterPanel.classList.remove('open');
      if (filterOverlay) filterOverlay.classList.remove('show');
    }
    // Expose für Overlay onclick oben
    window.closeFilter = closeFilter;

    if (filterToggle && filterPanel) {
      filterToggle.addEventListener('click', () => {
        filterPanel.classList.toggle('open');
        if (filterOverlay) filterOverlay.classList.toggle('show');
      });
    }
    if (filterOverlay) {
      filterOverlay.addEventListener('click', closeFilter);
    }
  }

  // Falls navigator.php das Menü-Icon mit onclick="toggleSidebar()" hat: Funktionen sind jetzt global vorhanden.
</script>
</body>
</html>