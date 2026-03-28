<?php
require_once 'auth_check.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

$currentPage = basename($_SERVER['PHP_SELF']);

require 'navigator.php';
require 'db_connect.php';

$success_message = "";
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_guardian'])) {
    $first_name = htmlspecialchars(trim($_POST['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $last_name  = htmlspecialchars(trim($_POST['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $extern_key = trim($_POST['extern_key'] ?? '');
    $email      = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $phone      = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
    $mobile     = htmlspecialchars(trim($_POST['mobile'] ?? ''), ENT_QUOTES, 'UTF-8');

    if ($first_name && $last_name) {
        $duplicateFound = false;

        $stmtCheck = $conn->prepare("
            SELECT id FROM LEGAL_GUARDIAN_TAB
            WHERE first_name = ? AND last_name = ? AND extern_key = ?
        ");
        if ($stmtCheck) {
            $stmtCheck->bind_param("sss", $first_name, $last_name, $extern_key);
            $stmtCheck->execute();
            $dupRes = $stmtCheck->get_result();
            if ($dupRes && $dupRes->num_rows > 0) {
                $duplicateFound = true;
                $error_message = "This guardian already exists in the database.";
            }
            $stmtCheck->close();
        }

        if (!$duplicateFound) {
            $extern_key_val = ($extern_key !== '') ? $extern_key : null;

            $stmt = $conn->prepare("
                INSERT INTO LEGAL_GUARDIAN_TAB
                    (first_name, last_name, extern_key, email, phone, mobile)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            if ($stmt) {
                $stmt->bind_param("ssssss", $first_name, $last_name, $extern_key_val, $email, $phone, $mobile);

                if ($stmt->execute()) {
                    $success_message = "Guardian successfully added.";
                } else {
                    $error_message = "Error while saving: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Statement error: " . $conn->error;
            }
        }
    } else {
        $error_message = "Please fill in all required fields (First Name, Last Name).";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Add Legal Guardian</title>

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
        .input-group input{
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
    <h1>ADD LEGAL GUARDIAN</h1>

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
                    <label for="first_name">First Name *</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">badge</span>
                        <input type="text" id="first_name" name="first_name" required>
                    </div>
                </div>
                <div>
                    <label for="last_name">Last Name *</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">person</span>
                        <input type="text" id="last_name" name="last_name" required>
                    </div>
                </div>
                <div>
                    <label for="extern_key">Extern Key</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">vpn_key</span>
                        <input type="text" id="extern_key" name="extern_key" placeholder="Auto-links to matching student">
                    </div>
                </div>
                <div>
                    <label for="email">Email</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">email</span>
                        <input type="email" id="email" name="email">
                    </div>
                </div>
                <div>
                    <label for="phone">Phone</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">phone</span>
                        <input type="text" id="phone" name="phone">
                    </div>
                </div>
                <div>
                    <label for="mobile">Mobile</label>
                    <div class="input-group">
                        <span class="material-icons-outlined">smartphone</span>
                        <input type="text" id="mobile" name="mobile">
                    </div>
                </div>
            </div>
            <button type="submit" name="add_guardian" class="save-btn">SUBMIT</button>
        </form>
    </div>
</div>

</body>
</html>
