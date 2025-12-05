<?php 
require_once 'auth_check.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

$currentPage = basename($_SERVER['PHP_SELF']);

require 'navigator.php'; 
require 'db_connect.php';
require_once 'reference_id_generator.php';

$success_message = "";
$error_message = "";

// Klassen für Dropdown laden
$classes = [];
$class_res = $conn->query("SELECT id, name FROM CLASS_TAB ORDER BY id ASC");
if ($class_res && $class_res->num_rows > 0) {
    $classes = $class_res->fetch_all(MYSQLI_ASSOC);
}

// 1) Neuen Schüler hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $name        = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $forename    = htmlspecialchars(trim($_POST['forename'] ?? ''), ENT_QUOTES, 'UTF-8');
    $long_name   = htmlspecialchars(trim($_POST['long_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $birth_date  = htmlspecialchars($_POST['birth_date'] ?? '', ENT_QUOTES, 'UTF-8');
    $class_id    = htmlspecialchars(trim($_POST['class_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $additional  = htmlspecialchars(trim($_POST['additional_payments_status'] ?? ''), ENT_QUOTES, 'UTF-8');
    $left_to_pay = isset($_POST['left_to_pay']) ? (float) $_POST['left_to_pay'] : 0;

    if ($name && $forename && $birth_date && $class_id) {
        $duplicateFound = false;

        $stmtCheck = $conn->prepare("
            SELECT id FROM STUDENT_TAB
            WHERE name = ? 
              AND forename = ? 
              AND birth_date = ?
        ");

        if ($stmtCheck) {
            $stmtCheck->bind_param("sss", $name, $forename, $birth_date);
            $stmtCheck->execute();
            $dupRes = $stmtCheck->get_result();

            if ($dupRes && $dupRes->num_rows > 0) {
                $duplicateFound = true;
                $error_message = "This student already exists in the database.";
                echo "<script>alert('This student already exists in the database.');</script>";
            }

            $stmtCheck->close();
        }

        if (!$duplicateFound) {
            $stmt = $conn->prepare("
                INSERT INTO STUDENT_TAB 
                    (name, forename, long_name, birth_date, class_id, additional_payments_status, left_to_pay)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            if ($stmt) {
                $stmt->bind_param("ssssssd", $name, $forename, $long_name, $birth_date, $class_id, $additional, $left_to_pay);

                if ($stmt->execute()) {
                    $success_message = "Student successfully added.";
                } else {
                    $error_message = "Error while saving: " . $stmt->error;
                }

                $stmt->close();
            } else {
                $error_message = "Statement error: " . $conn->error;
            }
        }

    } else {
        $error_message = "Please fill in all required fields (Name, Forename, Birth Date, Class).";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <title>Add Students</title>

    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Montserrat:wght@400;500;600&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
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

        #content.shifted {
            margin-left: 260px; 
            transition: margin-left 0.3s ease;
        }

        h1{
            font-family:'Space Grotesk',sans-serif;
            font-weight:700;
            font-size: 30px;
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
            margin:0 auto 30px;
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
            font-size: 14px;
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
        .input-group input, .input-group select{
            border:none;
            outline:none;
            flex:1;
            padding:10px;
            font-size:14px;
            font-family:'Roboto',sans-serif;
            background:transparent;
        }
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
    <h1>ADD STUDENT</h1>

    <?php if ($success_message): ?>
        <div class="message success"><?= $success_message ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="message error"><?= $error_message ?></div>
    <?php endif; ?>

    <!-- Formular -->
    <div class="form-card">
        <form method="post" action="">
            <div class="form-grid">
                <div>
                    <label for="name">Name</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">badge</span>
                        <input type="text" id="name" name="name" required>
                    </div>
                </div>
                <div>
                    <label for="forename">Forename</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">person</span>
                        <input type="text" id="forename" name="forename" required>
                    </div>
                </div>
                <div class="full-width">
                    <label for="long_name">Long Name</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">assignment</span>
                        <input type="text" id="long_name" name="long_name">
                    </div>
                </div>
                <div>
                    <label for="birth_date">Birth Date</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">event</span>
                        <input type="date" id="birth_date" name="birth_date" required>
                    </div>
                </div>
                <div>
                    <label for="class_id">Class</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">school</span>
                        <select id="class_id" name="class_id" required>
                            <option value="" disabled selected>-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= htmlspecialchars($class['id']) ?>">
                                    <?= htmlspecialchars($class['name']) ?> (ID: <?= htmlspecialchars($class['id']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="additional_payments_status">Additional Payments Status</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">euro</span>
                        <input type="text" id="additional_payments_status" name="additional_payments_status">
                    </div>
                </div>
                <div>
                    <label for="left_to_pay">Left to Pay (€)</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">euro</span>
                        <input type="number" step="0.01" id="left_to_pay" name="left_to_pay">
                    </div>
                </div>
            </div>
            <button type="submit" name="add_student" class="save-btn">SUBMIT</button>
        </form>
    </div>
<script>
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("overlay");
  const content = document.getElementById("content");

  function openSidebar() {
    sidebar.classList.add("open");
    overlay.classList.add("show");
    content.classList.add("shifted");
  }

  function closeSidebar() {
    sidebar.classList.remove("open");
    overlay.classList.remove("show");
    content.classList.remove("shifted");
  }

  function toggleSidebar() {
    sidebar.classList.toggle("open");
    overlay.classList.toggle("show");
    content.classList.toggle("shifted");
  }
</script>
</body>
</html>