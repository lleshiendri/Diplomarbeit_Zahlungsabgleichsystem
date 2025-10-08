<?php 
require 'navigator.php'; 
require 'db_connect.php';
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
            --sidebar-w:260px;
        }

        body{ 
            margin:0; 
            font-family:'Roboto',sans-serif; 
            background:#fff; 
            color:#222; 
            overflow-x:hidden;
        }

        /* Content wrapper */
        #content{ 
            margin-top:90px; 
            padding:30px; 
            transition: margin-left 0.3s ease;
        }
        #content.shifted{
            margin-left: var(--sidebar-w);
        }

        /* Tabs */
        .tabs{ 
            display:flex; 
            border-bottom:2px solid var(--gray-light); 
            margin-bottom:20px; 
        }
        .tab{
            padding:12px 20px; 
            cursor:pointer;
            font-family:'Montserrat',sans-serif; 
            font-weight:600;
            border-bottom:3px solid transparent; 
            transition:.3s;
        }
        .tab.active{ 
            border-bottom:3px solid var(--red-dark); 
            color:var(--red-dark); 
        }

        /* Card + header with button */
        .card{ 
            background:#fff; 
            border:1px solid var(--gray-light); 
            border-radius:10px; 
            padding:20px; 
            box-shadow:0 1px 3px rgba(0,0,0,.08); 
        }
        .card-header{ 
            display:flex; 
            justify-content:space-between; 
            align-items:center; 
            margin-bottom:15px; 
        }
        .card-header h2{ 
            margin:0; 
            font-family:'Montserrat',sans-serif; 
            font-size:18px; 
            color:var(--red-dark); 
        }

        /* Table */
        table{ 
            width:100%; 
            border-collapse:collapse; 
            border-radius:8px; 
            overflow:hidden; 
        }
        thead{ background:var(--red-light); }
        th,td{ padding:12px 14px; text-align:left; font-size:14px; }
        th{ font-family:'Montserrat',sans-serif; font-weight:600; color:#333; }
        tbody tr:nth-child(even){ background:#f9f9f9; }
        tbody tr:hover{ background:#FFF8EB; }

        /* Download button */
        .download-btn{
            padding:6px 12px; 
            border:none; 
            border-radius:6px;
            background:var(--red-main); 
            color:#fff;
            font-size:13px; 
            cursor:pointer; 
            transition:.2s;
        }
        .download-btn:hover{ background:var(--red-dark); }

        /* Import button */
        .import-button{
            padding:10px 18px; 
            border:none; 
            border-radius:6px;
            background:var(--red-main); 
            color:#fff;
            font-family:'Montserrat',sans-serif; 
            font-weight:600;
            cursor:pointer; 
            transition:.2s;
        }
        .import-button:hover{ background:var(--red-dark); }

        /* Sidebar (von navigator) */
        .sidebar {
            position: fixed;
            top: 70px;
            left: 0;
            bottom: 0;
            width: var(--sidebar-w);
            background: var(--off-white);
            border-right: 1px solid var(--gray-light);
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1200;
            overflow-y:auto;
        }
        .sidebar.open {
            transform: translateX(0);
        }

        /* Overlay */
        #overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.2);
            display: none;
            z-index: 1100;
        }
        #overlay.show {
            display: block;
        }

        /* Home icon styling (in navbar) */
        .nav-home {
            display: flex;
            align-items: center;
            text-decoration: none;
            color: white;
            margin-right: 20px;
        }
        .nav-home .material-icons-outlined {
            font-size: 26px;
            margin-right: 4px;
        }
        .nav-home:hover {
            color: var(--red-light);
        }
    </style>
</head>
<body>

<!-- OVERLAY -->
<div id="overlay" onclick="closeSidebar()"></div>

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
            <tr>
                <td>transactions_sep.csv</td>
                <td>2025-09-29</td>
                <td>Admin</td>
                <td><button class="download-btn">Download</button></td>
            </tr>
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
            <tr>
                <td>students.csv</td>
                <td>2025-09-20</td>
                <td>Teacher1</td>
                <td><button class="download-btn">Download</button></td>
            </tr>
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
            <tr>
                <td>guardians.csv</td>
                <td>2025-09-10</td>
                <td>Admin2</td>
                <td><button class="download-btn">Download</button></td>
            </tr>
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

    // Sidebar toggle
    const sidebar   = document.getElementById("sidebar");
    const content   = document.getElementById("content");
    const overlay   = document.getElementById("overlay");

    function openSidebar(){
        sidebar.classList.add("open");
        content.classList.add("shifted");
        overlay.classList.add("show");
    }
    function closeSidebar(){
        sidebar.classList.remove("open");
        content.classList.remove("shifted");
        overlay.classList.remove("show");
    }
    function toggleSidebar(){ 
        sidebar.classList.contains("open") ? closeSidebar() : openSidebar(); 
    }
</script>
</body>
</html>