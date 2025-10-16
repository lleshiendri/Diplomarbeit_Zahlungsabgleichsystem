<?php 
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db_connect.php';

function normalizeDate($raw) {
    if (!$raw) return null;

    $raw = trim($raw);

    // Match dd.mm.yyyy (e.g. 28.10.2024)
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $matches)) {
        $day   = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year  = $matches[3];
        return "$year-$month-$day";
    }

    // fallback: already YYYY-MM-DD?
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return $raw;
    }

    // fallback using strtotime
    $t = strtotime($raw);
    if ($t !== false) {
        return date('Y-m-d', $t);
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajaxUpload'])) {
    $response = ['success' => false, 'message' => ''];
    $fileType = $_POST['type'] ?? 'other';

    if (isset($_FILES['importFile']) && $_FILES['importFile']['error'] === UPLOAD_ERR_OK) {
        


        if ($fileType === 'Students') {
        $uploadDir = 'student_import_archive/';
    } elseif ($fileType === 'Transactions') {
        $uploadDir = 'transaction_import_archive/';
    } elseif ($fileType === 'LegalGuardians') {
        $uploadDir = 'guardian_import_archive/';
    } else {
        $uploadDir = 'other_imports/';
    }

        $originalName = basename($_FILES['importFile']['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['csv','xls','xlsx'];

        if (in_array($extension, $allowedExtensions)) {
            $newFileName = uniqid('import_', true) . '.' . $extension;
            $destination = $uploadDir . $newFileName;

            if (move_uploaded_file($_FILES['importFile']['tmp_name'], $destination)) {
                $importedBy = 'admin'; // replace with session username if needed

                $stmt = $conn->prepare("
                    INSERT INTO IMPORT_DOCUMENT_TAB (filename, filepath, type, user_id) 
                    VALUES (?, ?, ?, ?)
                ");

                //  Check if prepare succeeded
                if (!$stmt) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'SQL prepare failed: ' . $conn->error
                    ]);
                    exit;
                }

                // Bind parameters
                $user_id = 1; //  temporary for testing — replace later with actual session user ID
                $stmt->bind_param("sssi", $originalName, $destination, $fileType, $user_id);

                // Execute and check for execution errors
                if (!$stmt->execute()) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'SQL execute failed: ' . $stmt->error
                    ]);
                    exit;
                }

                // Get insert ID if needed
                $id = $conn->insert_id;

                if ($fileType === 'Students') {
                $filePath = $destination;

                    if (($handle = fopen($filePath, "r")) !== FALSE) {
                        // Read header row
                        $header = fgetcsv($handle, 1000, ",");

                        // Prepare insert statement for STUDENT table
                        $stmtStudent = $conn->prepare("
                            INSERT INTO STUDENT_TAB (forename, name, long_name, birth_date, left_to_pay, additional_payments_status)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");

                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            // Map CSV columns to variables
                            $forename = $data[0] ?? null;
                            $name = $data[1] ?? null;
                            $long_name = $data[2] ?? null;
                            $birth_date = normalizeDate($data[4] ?? null);
                            $left_to_pay = $data[5] ?? 0;
                            $additional_payments_status = $data[6] ?? 0;

                            $stmtStudent->bind_param(
                                "ssssdd",
                                $forename,
                                $name,
                                $long_name,
                                $birth_date,
                                $left_to_pay,
                                $additional_payments_status
                            );
                            $stmtStudent->execute();
                        }

                        fclose($handle);
                    }
                }

                if ($fileType === 'Transactions') {
                    $filePath = $destination;

                    if (($handle = fopen($filePath, "r")) !== FALSE) {
                        // Read header
                        $header = fgetcsv($handle, 1000, ",");

                        // Prepare insert statement for INVOICE_TAB
                        $stmtTrans = $conn->prepare("
                            INSERT INTO INVOICE_TAB 
                                (reference_number,  beneficiary, reference, transaction_type, processing_date, amount, amount_total) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            error_log("TRANSACTION PREPARE FAILED: " . $conn->error);
                            exit;
                        }

                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            $reference_number = $data[2] ?? null;
                            $beneficiary      = $data[3] ?? null;
                            $reference        = $data[5] ?? null;
                            $transaction_type = $data[6] ?? null;
                            $processing_date  = normalizeDate($data[7] ?? null);
                            $amount           = $data[8] ?? 0;
                            $amount_total     = $data[9] ?? 0;

                            $stmtTrans->bind_param(
                                "sssssdd",
                                $reference_number,
                                $beneficiary,
                                $reference,
                                $transaction_type,
                                $processing_date,
                                $amount,
                                $amount_total
                            );
                            $stmtTrans->execute();
                        }

                        fclose($handle);
                    }
                }

                if ($fileType === 'LegalGuardians') {
                    $filePath = $destination;

                    if (($handle = fopen($filePath, "r")) !== FALSE) {
                        // Read header
                        $header = fgetcsv($handle, 1000, ",");

                        // Prepare insert statement for LEGAL_GUARDIAN_TAB
                        $stmtGuardian = $conn->prepare("
                            INSERT INTO LEGAL_GUARDIAN_TAB 
                                (first_name, last_name, phone, mobile, grade, email, registered_user_names, degree, postgrade, external_key) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");

                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            $first_name = $data[0] ?? null;
                            $last_name  = $data[1] ?? null;
                            $phone      = $data[2] ?? null;
                            $mobile     = $data[3] ?? null;
                            $grade      = $data[4] ?? null;
                            $email      = $data[5] ?? null;
                            $reg_user   = $data[6] ?? null;
                            $degree     = $data[7] ?? null;
                            $postgrade  = $data[8] ?? null;
                            $external   = $data[9] ?? null;

                            $stmtGuardian->bind_param(
                                "ssssssssss",
                                $first_name,
                                $last_name,
                                $phone,
                                $mobile,
                                $grade,
                                $email,
                                $reg_user,
                                $degree,
                                $postgrade,
                                $external
                            );
                            $stmtGuardian->execute();
                        }

                        fclose($handle);
                    }
                }

                // Return JSON with file info to dynamically insert into table
                $response['success'] = true;
                $response['file'] = [
                    'id' => $id,
                    'filename' => $originalName,
                    'filepath' => $destination,
                    'imported_by' => $importedBy,
                    'imported_date' => date('Y-m-d H:i:s'),
                    'type' => $fileType
                ];
            } else {
                $response['message'] = 'Error moving file.';
            }
        } else {
            $response['message'] = 'Invalid file type.';
        }
    } else {
        $response['message'] = 'No file uploaded or upload error.';
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

require 'navigator.php'; 
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
        thead{ 
            background:var(--red-light); 
        }
        th,td{ 
            padding:12px 14px; 
            text-align:left; 
            font-size:14px; 
        }
        th{ 
            font-family:'Montserrat',sans-serif; 
            font-weight:600; 
            color:#333; 
        }
        tbody tr:nth-child(even){ 
            background:#f9f9f9; 
        }
        tbody tr:hover{ 
            background:#FFF8EB; 
        }

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
        .download-btn:hover{ 
            background:var(--red-dark); 
        }

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
             <form class="upload-form" data-type="Transactions" enctype="multipart/form-data">
                <input type="file" name="importFile" style="display:none">
                <button type="button" class="import-button">Import File</button>
            </form>
        </div>
        <table>
            <thead>
            <tr><th>Filename</th><th>Import Date</th><th>Imported By</th><th></th></tr>
            </thead>
            <tbody>

            <?php
            // Fetch imported transaction files from the database
            $result = $conn->query("
                SELECT id, filename, filepath, imported_at 
                FROM IMPORT_DOCUMENT_TAB 
                WHERE type = 'Transactions'
                ORDER BY id DESC
            ");

            if ($result && $result->num_rows > 0) {
                // Use mock date and user for now (adjust when real data is available)
                $mockUser = 'admin';

                while ($row = $result->fetch_assoc()) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($row['filename']) . '</td>';
                    echo '<td>' . htmlspecialchars($row['imported_at']) . '</td>';
                    echo '<td>' . htmlspecialchars($mockUser) . '</td>';
                    echo '<td><a href="' . htmlspecialchars($row['filepath']) . '" target="_blank" class="download-btn">Download</a></td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="4" style="text-align:center; color:#888;">No files imported yet</td></tr>';
            }
            ?>

            </tbody>
        </table>
    </div>

    <!-- Students -->
    <div id="students" class="tab-content card" style="display:none">
        <div class="card-header">
            <h2>Student Imports</h2>
            <form class="upload-form" data-type="Students" enctype="multipart/form-data">
                <input type="file" name="importFile" style="display:none">
                <button type="button" class="import-button">Import File</button>
            </form>
        </div>
        <table>
            <thead>
            <tr><th>Filename</th><th>Import Date</th><th>Imported By</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            // Fetch imported student files from the database
            $result = $conn->query("
            SELECT id, filename, filepath, imported_at 
            FROM IMPORT_DOCUMENT_TAB 
            WHERE type = 'Students'
            ORDER BY id DESC
        ");

        if ($result && $result->num_rows > 0) {
            // Use a static mock date and user for now
            $mockUser = 'admin';

            while ($row = $result->fetch_assoc()) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['filename']) . '</td>';
                echo '<td>' . htmlspecialchars($row['imported_at']) . '</td>';
                echo '<td>' . htmlspecialchars($mockUser) . '</td>';
                echo '<td><a href="' . htmlspecialchars($row['filepath']) . '" target="_blank" class="download-btn">Download</a></td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="4" style="text-align:center; color:#888;">No files imported yet</td></tr>';
        }
            ?>
            </tbody>
        </table>
    </div>

    <!-- Guardians -->
    <div id="guardians" class="tab-content card" style="display:none">
        <div class="card-header">
            <h2>Legal Guardian Imports</h2>
            <form class="upload-form" data-type="LegalGuardians" enctype="multipart/form-data">
                <input type="file" name="importFile" style="display:none">
                <button type="button" class="import-button">Import File</button>
            </form>
        </div>
        <table>
            <thead>
            <tr><th>Filename</th><th>Import Date</th><th>Imported By</th><th></th></tr>
            </thead>
            <tbody>
           
            <?php
            // Fetch imported legal guardian files from the database
            $result = $conn->query("
                SELECT id, filename, filepath, imported_at 
                FROM IMPORT_DOCUMENT_TAB 
                WHERE type = 'LegalGuardians'
                ORDER BY id DESC
            ");

            if ($result && $result->num_rows > 0) {
                // Use mock date and user for now
                $mockUser = 'admin';

                while ($row = $result->fetch_assoc()) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($row['filename']) . '</td>';
                    echo '<td>' . htmlspecialchars($row['imported_at']) . '</td>';
                    echo '<td>' . htmlspecialchars($mockUser) . '</td>';
                    echo '<td><a href="' . htmlspecialchars($row['filepath']) . '" target="_blank" class="download-btn">Download</a></td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="4" style="text-align:center; color:#888;">No files imported yet</td></tr>';
            }
            ?>

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
     document.querySelectorAll('.upload-form').forEach(form => {
        const fileInput = form.querySelector('input[type=file]');
        const button = form.querySelector('.import-button');
        const type = form.dataset.type;
        const tbody = form.closest('.card').querySelector('tbody');

        button.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', () => {
            const formData = new FormData();
            formData.append('importFile', fileInput.files[0]);
            formData.append('type', type);
            formData.append('ajaxUpload', '1');

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const f = data.file;
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${f.filename}</td>
                        <td>${f.imported_date}</td>
                        <td>${f.imported_by}</td>
                        <td><a href="${f.filepath}" target="_blank" class="download-btn">Download</a></td>
                    `;
                    tbody.prepend(row);
    } else {
        alert('❌ Upload failed: ' + data.message);
    }
    fileInput.value = ''; // reset input
})
            .catch(err => {
                alert('❌ Upload error.');
                console.error(err);
            });
        });
    });
</script>
</body>
</html>