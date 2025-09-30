<?php
require 'database.php';  // adjust path if needed

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['importFile'])) {
    $file = $_FILES['importFile'];
    $type = $_POST['type']; // transactions / students / guardians

    if ($file['error'] === 0) {
        $uploadDir = __DIR__ . "/uploads/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = basename($file['name']);
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            // Save to DB
            $stmt = $connection->prepare("INSERT INTO imports (filename, import_date, imported_by, type) VALUES (:filename, NOW(), :imported_by, :type)");
            $stmt->execute([
                ':filename' => $filename,
                ':imported_by' => 'Admin', // TODO: replace with logged-in user
                ':type' => $type
            ]);
        }
    }
}

// Fetch import history by type
function getImports($connection, $type) {
    $stmt = $connection->prepare("SELECT filename, import_date, imported_by FROM imports WHERE type = :type ORDER BY import_date DESC");
    $stmt->execute([':type' => $type]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$imports_transactions = getImports($connection, 'transactions');
$imports_students = getImports($connection, 'students');
$imports_guardians = getImports($connection, 'guardians');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <title>Imports</title>
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

        /* Header bar */
        .header{
            position: fixed; top:0; left:0; right:0;
            z-index:1300; height:70px;
            display:flex; align-items:center; justify-content:space-between;
            padding:0 30px;
            background:var(--red-main); color:#fff;
            box-shadow:0 2px 6px rgba(0,0,0,.1);
        }
        .nav-left{ display:flex; align-items:center; gap:20px; }
        .menu-icon,.nav-icon{ font-size:26px; cursor:pointer; color:#fff; }
        .menu-icon:hover,.nav-icon:hover{ color:var(--red-dark); }
        .logo img{ height:36px; }

        /* Content wrapper */
        #content{ margin-top:90px; padding:30px; }

        /* Tabs */
        .tabs{ display:flex; border-bottom:2px solid var(--gray-light); margin-bottom:20px; }
        .tab{
            padding:12px 20px; cursor:pointer;
            font-family:'Montserrat',sans-serif; font-weight:600;
            border-bottom:3px solid transparent; transition:.3s;
        }
        .tab.active{ border-bottom:3px solid var(--red-dark); color:var(--red-dark); }

        /* Card + header with button */
        .card{ background:#fff; border:1px solid var(--gray-light); border-radius:10px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .card-header{ display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; }
        .card-header h2{ margin:0; font-family:'Montserrat',sans-serif; font-size:18px; color:var(--red-dark); }

        /* Table */
        table{ width:100%; border-collapse:collapse; border-radius:8px; overflow:hidden; }
        thead{ background:var(--red-light); }
        th,td{ padding:12px 14px; text-align:left; font-size:14px; }
        th{ font-family:'Montserrat',sans-serif; font-weight:600; color:#333; }
        tbody tr:nth-child(even){ background:#f9f9f9; }
        tbody tr:hover{ background:#FFF8EB; }

        /* Download button */
        .download-btn{
            padding:6px 12px; border:none; border-radius:6px;
            background:var(--red-main); color:#fff;
            font-size:13px; cursor:pointer; transition:.2s;
        }
        .download-btn:hover{ background:var(--red-dark); }

        /* Import button */
        .import-button{
            padding:10px 18px; border:none; border-radius:6px;
            background:var(--red-main); color:#fff;
            font-family:'Montserrat',sans-serif; font-weight:600;
            cursor:pointer; transition:.2s;
        }
        .import-button:hover{ background:var(--red-dark); }
    </style>
</head>
<body>
<!-- HEADER -->
<div class="header">
    <div class="nav-left">
        <span class="menu-icon material-icons-outlined" onclick="toggleSidebar()">menu</span>
        <span class="nav-icon material-icons-outlined">notifications</span>
        <span class="nav-icon material-icons-outlined">priority_high</span>
        <span class="nav-icon material-icons-outlined" id="filterToggle">filter_list</span>
    </div>
    <div class="logo"><img src="logo1.png" alt="Logo"></div>
</div>

<!-- PAGE CONTENT -->
<div id="content">
    <h1 style="font-family:'Space Grotesk',sans-serif; color:var(--red-dark); margin-bottom:20px;">Imports</h1>

    <!-- Tabs -->
    <div class="tabs">
        <div class="tab active" data-tab="transactions">Transactions</div>
        <div class="tab" data-tab="students">Students</div>
        <div class="tab" data-tab="guardians">Legal Guardians</div>
    </div>

    <!-- Transactions -->
    <div id="transactions" class="tab-content active card">
        <div class="card-header">
            <h2>Transaction Imports</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="type" value="transactions">
                <input type="file" name="importFile" style="display:none" id="file-transactions" onchange="this.form.submit()">
                <button type="button" class="import-button" onclick="document.getElementById('file-transactions').click()">Import File</button>
            </form>
        </div>
        <table>
            <thead>
            <tr><th>Filename</th><th>Import Date</th><th>Imported By</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($imports_transactions as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['filename']) ?></td>
                    <td><?= htmlspecialchars($row['import_date']) ?></td>
                    <td><?= htmlspecialchars($row['imported_by']) ?></td>
                    <td><button class="download-btn">Download</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Students -->
    <div id="students" class="tab-content card" style="display:none">
        <div class="card-header">
            <h2>Student Imports</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="type" value="students">
                <input type="file" name="importFile" style="display:none" id="file-students" onchange="this.form.submit()">
                <button type="button" class="import-button" onclick="document.getElementById('file-students').click()">Import File</button>
            </form>
        </div>
        <table>
            <thead>
            <tr><th>Filename</th><th>Import Date</th><th>Imported By</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($imports_students as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['filename']) ?></td>
                    <td><?= htmlspecialchars($row['import_date']) ?></td>
                    <td><?= htmlspecialchars($row['imported_by']) ?></td>
                    <td><button class="download-btn">Download</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Guardians -->
    <div id="guardians" class="tab-content card" style="display:none">
        <div class="card-header">
            <h2>Legal Guardian Imports</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="type" value="guardians">
                <input type="file" name="importFile" style="display:none" id="file-guardians" onchange="this.form.submit()">
                <button type="button" class="import-button" onclick="document.getElementById('file-guardians').click()">Import File</button>
            </form>
        </div>
        <table>
            <thead>
            <tr><th>Filename</th><th>Import Date</th><th>Imported By</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($imports_guardians as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['filename']) ?></td>
                    <td><?= htmlspecialchars($row['import_date']) ?></td>
                    <td><?= htmlspecialchars($row['imported_by']) ?></td>
                    <td><button class="download-btn">Download</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Tab switching
    const tabs = document.querySelectorAll(".tab");
    const contents = document.querySelectorAll(".tab-content");

    tabs.forEach(tab=>{
        tab.addEventListener("click",()=>{
            tabs.forEach(t=>t.classList.remove("active"));
            contents.forEach(c=>c.style.display="none");

            tab.classList.add("active");
            document.getElementById(tab.dataset.tab).style.display="block";
        });
    });
</script>
</body>
</html>
