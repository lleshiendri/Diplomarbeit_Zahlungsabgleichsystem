<?php 
const CURRENCY = 'Lek';
require_once 'auth_check.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

$currentPage = basename($_SERVER['PHP_SELF']);

require 'navigator.php'; 
require 'db_connect.php';

function makeTransactionHash($reference_number, $beneficiary, $description, $reference, $transaction_type, $processing_date, $amount) {
    $parts = [
        trim((string)$reference_number),
        trim((string)$processing_date),
        number_format((float)$amount, 2, '.', ''), // normalize
        mb_strtolower(trim((string)$beneficiary)),
        mb_strtolower(trim((string)$reference)),
        mb_strtolower(trim((string)$description)),
        mb_strtolower(trim((string)$transaction_type)),
    ];
    return hash('sha256', implode('|', $parts));
}

// ✅ Use new matching + notifications pipeline
//require_once __DIR__ . '/matching_functions.php';

$success_message = "";
$error_message = "";
$debug_box = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ✅ Do NOT htmlspecialchars before DB insert. Use trim only.
    $ref_number      = trim($_POST['reference_number'] ?? '');
    $reference       = trim($_POST['reference'] ?? '');
    $ordering_name   = trim($_POST['beneficiary'] ?? '');
    $processing_date = trim($_POST['processing_date'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $amount          = isset($_POST['amount']) ? (float) $_POST['amount'] : 0;
    $transaction_type = "Payment";

    $import_hash = makeTransactionHash(
        $ref_number,
        $ordering_name,
        $description,
        $reference,
        $transaction_type,
        $processing_date,
        $amount
    );

    // Ensure DATETIME string (input type="date" => YYYY-MM-DD)
    if ($processing_date && strlen($processing_date) === 10) {
        $processing_date .= " 00:00:00";
    }

    if ($ref_number && $reference && $ordering_name && $processing_date && $amount) {

        $stmt = $conn->prepare("
            INSERT INTO INVOICE_TAB 
                (reference_number, reference, beneficiary, processing_date, description, amount, transaction_type, import_hash) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if ($stmt) {
            $stmt->bind_param("sssssdss", $ref_number, $reference, $ordering_name, $processing_date, $description, $amount, $transaction_type, $import_hash);

            if ($stmt->execute()) {

                $newInvoiceId = (int)$conn->insert_id;

                if ($newInvoiceId > 0) {

                    // ✅ Run full pipeline (matching + history + invoice update + notifications)
                  //$matchResult = processInvoiceMatching($conn, $newInvoiceId, $ref_number, $ordering_name, $reference, true);


                    $success_message = "Transaction was successfully added.";

                    // Optional debug box
                    if (defined('ENV_DEBUG') && ENV_DEBUG) {
                        $debug_box = "<pre style='background:#111;color:#0f0;padding:12px;border-radius:10px;max-width:900px;margin:20px auto;overflow:auto;'>";
                       // $debug_box .= "DEBUG: processInvoiceMatching() result\n";
                        $debug_box .= htmlspecialchars(json_encode($matchResult, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');
                        $debug_box .= "</pre>";
                    }

                } else {
                    $error_message = "Invoice insert failed (no insert_id).";
                }

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

        #content {
            transition: margin-left 0.3s ease;
            margin-left: 0;
            padding: 100px 30px 30px; 
        }
        #content.shifted { margin-left: 100px; }

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
            margin-bottom:10px;
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

<div id="content">
    <h1>Add Transaction</h1>

    <?php if ($success_message): ?>
        <div class="message success"><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="message error"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?= $debug_box ?>

    <div class="form-card">
        <form method="post" action="">
            <div class="form-grid">
                <div>
                    <label for="reference_number">Reference Number</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">tag</span>
                        <input type="text" id="reference_number" name="reference_number" placeholder="Enter Reference Number" required>
                    </div>
                </div>
                <div>
                    <label for="reference">Reference</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">tag</span>
                        <input type="text" id="reference" name="reference" placeholder="Enter Reference" required>
                    </div>
                </div>
                <div>
                    <label for="beneficiary">Ordering Name (Beneficiary)</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">person</span>
                        <input type="text" id="beneficiary" name="beneficiary" placeholder="Enter Ordering Name" required>
                    </div>
                </div>
                <div>
                    <label for="processing_date">Processing Date</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">event</span>
                        <input type="date" id="processing_date" name="processing_date" required>
                    </div>
                </div>
                <div class="full-width">
                    <label for="description">Description</label>
                    <div class="input-group" style="align-items:flex-start;">
                        <span class="material-icons-outlined">description</span>
                        <textarea id="description" name="description" placeholder="Enter Description"></textarea>
                    </div>
                </div>
                <div class="full-width">
                    <label for="amount">Amount Paid (<?= CURRENCY ?>)</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">payments</span>
                        <input type="number" step="0.01" id="amount" name="amount" placeholder="Enter Amount" required>
                    </div>
                </div>
            </div>

            <button type="submit" class="save-btn">Save Transaction</button>
        </form>
    </div>
</div>

<div id="overlay" class="overlay" onclick="closeSidebar();"></div>

<script>
  const sidebar = document.getElementById("sidebar"); 
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
</script>
</body>
</html>
