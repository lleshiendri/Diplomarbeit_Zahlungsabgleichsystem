<?php 
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db_connect.php';

function detectDelimiter($filePath, $fileType) {
    $priorityMap = [
        'Students'       => ["\t", ";", ",", "|"],
        'LegalGuardians' => ["\t", ";", ",", "|"],
        'Transactions'   => [",", ";", "\t", "|"],
    ];
    $candidates = $priorityMap[$fileType] ?? [",", "\t", ";", "|"];

    if (!is_readable($filePath)) {
        return $candidates[0];
    }

    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return $candidates[0];
    }

    $line = fgets($handle);
    fclose($handle);
    if ($line === false) {
        return $candidates[0];
    }

    $line = ltrim($line, "\xEF\xBB\xBF");
    foreach ($candidates as $delimiter) {
        $columns = str_getcsv($line, $delimiter);
        if (count($columns) > 1) {
            return $delimiter;
        }
    }

    return $candidates[0];
}

function validateCSVStructure($filePath, $fileType) {
    // Expected headers for each type
    $expectedHeaders = [
        'Students' => [
            'name', 'longName', 'foreName', 'gender', 'birthDate',
            'klasse.name', 'entryDate', 'exitDate', 'text', 'id',
            'externKey', 'medicalReportDuty', 'schulpflicht', 'majority',
            'address.email', 'address.mobile', 'address.phone',
            'address.city', 'address.postCode', 'address.street'
        ],
        'Transactions' => [
            'reference_number', 'beneficiary', 'reference',
            'transaction_type', 'processing_date', 'amount', 'amount_total'
        ],
        'LegalGuardians' => [
            'id', 'lastName', 'firstName', 'degree', 'grade',
            'postgrade', 'email', 'phone', 'mobile',
            'displayRegisteredUserNames', 'externalKey',
            'studentLastName', 'studentFirstName', 'studentShortName',
            'studentInternalId', 'studentExternalId',
            'addressStreet', 'addressCity', 'addressPostCode'
        ]
    ];

    // Make sure we know this file type
    if (!isset($expectedHeaders[$fileType])) {
        return [
            'valid' => false,
            'message' => "Unknown file type: {$fileType}",
            'missing' => [],
            'extra' => []
        ];
    }

    // Choose delimiter dynamically based on file contents
    $delimiter = detectDelimiter($filePath, $fileType);

    // Open file
    $handle = fopen($filePath, "r");
    if (!$handle) {
        return [
            'valid' => false,
            'message' => "Cannot open uploaded file.",
            'missing' => [],
            'extra' => []
        ];
    }

    // Read header
    $header = fgetcsv($handle, 2000, $delimiter);
    fclose($handle);

    if (!$header) {
        return [
            'valid' => false,
            'message' => "Cannot read header row.",
            'missing' => [],
            'extra' => []
        ];
    }

    // Normalize
    $header = array_map('trim', $header);
    $expected = $expectedHeaders[$fileType];

    // Compare
    $missing = array_diff($expected, $header);
    $extra   = array_diff($header, $expected);

    if (empty($missing) && empty($extra)) {
        return [
            'valid' => true,
            'message' => "File structure valid for {$fileType}.",
            'missing' => [],
            'extra' => [],
            'delimiter' => $delimiter
        ];
    }

    return [
        'valid' => false,
        'message' => "Invalid file structure for {$fileType}.",
        'missing' => array_values($missing),
        'extra' => array_values($extra),
        'delimiter' => $delimiter
    ];
}

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

function saveFileAndMetadata($conn, $file, $fileType, $uploadDir) {
    $originalName = basename($file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['csv','tsv','txt','xls','xlsx'];

    if (!in_array($extension, $allowedExtensions)) {
        return ['success' => false, 'message' => 'Invalid file type.'];
    }

    $allowedMimeTypes = [
        'csv' => ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/octet-stream'],
        'tsv' => ['text/tab-separated-values', 'text/plain', 'application/octet-stream'],
        'txt' => ['text/plain', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx'=> ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'],
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
    if ($finfo) {
        finfo_close($finfo);
    }

    if (
        $mimeType &&
        isset($allowedMimeTypes[$extension]) &&
        !in_array($mimeType, $allowedMimeTypes[$extension])
    ) {
        return ['success' => false, 'message' => 'Unsupported MIME type: ' . $mimeType];
    }

    // Generate new name & destination
    $newFileName = uniqid('import_', true) . '.' . $extension;
    $relativeDir = rtrim($uploadDir, '/\\') . '/';
    $storageDir = __DIR__ . '/' . $relativeDir;

    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true)) {
        return ['success' => false, 'message' => 'Unable to create upload directory.'];
    }

    $diskPath = $storageDir . $newFileName;
    $relativePath = $relativeDir . $newFileName;

    // 🗂️ 1. Save file physically first
    if (!move_uploaded_file($file['tmp_name'], $diskPath)) {
        return ['success' => false, 'message' => 'Error saving uploaded file.'];
    }

    // 🧾 2. Insert metadata record
    $stmt = $conn->prepare("
        INSERT INTO IMPORT_DOCUMENT_TAB (filename, filepath, type, user_id)
        VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
        unlink($diskPath); // clean up if DB prepare fails
        return ['success' => false, 'message' => 'SQL prepare failed: ' . $conn->error];
    }

    $user_id = 1; // replace later with session user id
    $stmt->bind_param("sssi", $originalName, $relativePath, $fileType, $user_id);

    if (!$stmt->execute()) {
        unlink($diskPath);
        return ['success' => false, 'message' => 'SQL execute failed: ' . $stmt->error];
    }

    return [
        'success' => true,
        'id' => $conn->insert_id,
        'destination' => $relativePath,
        'diskPath' => $diskPath,
        'originalName' => $originalName
    ];
}






if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajaxUpload'])) {
    $response = ['success' => false, 'message' => ''];
    $fileType = $_POST['type'] ?? 'other';
    $file = $_FILES['importFile'];

    if (isset($_FILES['importFile']) && $_FILES['importFile']['error'] === UPLOAD_ERR_OK) {
        switch ($fileType) {
            case 'Students':       $uploadDir = 'student_import_archive/'; break;
            case 'Transactions':   $uploadDir = 'transaction_import_archive/'; break;
            case 'LegalGuardians': $uploadDir = 'guardian_import_archive/'; break;
            default:               $uploadDir = 'other_imports/';
        }
     
        $validation = validateCSVStructure($file['tmp_name'], $fileType);
        if (!$validation['valid']) {
            echo json_encode([
                'success' => false,
                'message' => $validation['message'],
                'missingColumns' => $validation['missing'],
                'unexpectedColumns' => $validation['extra'],
            ]);
            exit;
        }
        else{
            $result = saveFileAndMetadata($conn, $file, $fileType, $uploadDir);
            if (!$result['success']) {
                echo json_encode($result);
                exit;
            }
        }

        $destination = $result['destination'];
        $diskPath    = $result['diskPath'] ?? (__DIR__ . '/' . ltrim($destination, '/\\'));
        $originalName  = $result['originalName'];
        $id            = $result['id'];
        $importedBy    = 'admin'; // same as metadata insert
        $studentImportSummary = null;


             if ($fileType === 'Students') {
                $filePath = $diskPath;
                $studentDelimiter = $validation['delimiter'] ?? detectDelimiter($filePath, 'Students');
                if (!is_readable($filePath)) {
                    echo json_encode(['success' => false, 'message' => 'Uploaded file cannot be read.']);
                    exit;
                }

                if (($handle = fopen($filePath, "r")) === FALSE) {
                    echo json_encode(['success' => false, 'message' => 'Unable to reopen uploaded file.']);
                    exit;
                }

                // Read header row
                fgetcsv($handle, 4000, $studentDelimiter);

                // Prepare insert statement for STUDENT table
                $stmtStudent = $conn->prepare("
                    INSERT INTO STUDENT_TAB (forename, name, long_name, birth_date, left_to_pay, additional_payments_status, gender, entry_date, exit_date, description, second_ID, extern_key, email)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmtStudent) {
                    fclose($handle);
                    echo json_encode(['success' => false, 'message' => 'Student insert prepare failed: ' . $conn->error]);
                    exit;
                }

                $forename = $name = $long_name = $birth_date = $gender = $entry_date = $exit_date = $description = $second_id = $extern_key = $email = null;
                $left_to_pay = 0.0;
                $additional_payments_status = 0.0;

                $stmtStudent->bind_param(
                    "ssssddsssssss",
                    $forename,
                    $name,
                    $long_name,
                    $birth_date,
                    $left_to_pay,
                    $additional_payments_status,
                    $gender,
                    $entry_date,
                    $exit_date,
                    $description,
                    $second_id,
                    $extern_key,
                    $email
                );

                $rowNumber = 1;
                $insertedStudents = 0;
                $studentErrors = [];

                while (($data = fgetcsv($handle, 4000, $studentDelimiter)) !== FALSE) {
                    $rowNumber++;
                    if ($data === null || count(array_filter($data, function ($value) {
                        return $value !== null && trim($value) !== '';
                    })) === 0) {
                        continue;
                    }

                    $forename = isset($data[2]) ? trim($data[2]) : null;
                    $name = isset($data[0]) ? trim($data[0]) : null;
                    $long_name = isset($data[1]) ? trim($data[1]) : null;
                    $birth_date = normalizeDate($data[4] ?? null);
                    $left_to_pay = 0.0;
                    $additional_payments_status = 0.0;
                    $gender = isset($data[3]) ? trim($data[3]) : null;
                    $entry_date = normalizeDate($data[6] ?? null);
                    $exit_date = normalizeDate($data[7] ?? null);
                    $description = isset($data[8]) ? trim($data[8]) : null;
                    $second_id = isset($data[9]) ? trim($data[9]) : null;
                    $extern_key = isset($data[10]) ? trim($data[10]) : null;
                    $email = isset($data[14]) ? trim($data[14]) : null;

                    if (!$stmtStudent->execute()) {
                        $studentErrors[] = "Row {$rowNumber}: " . $stmtStudent->error;
                        error_log("STUDENT INSERT ERROR (Row {$rowNumber}): " . $stmtStudent->error);
                    } else {
                        $insertedStudents++;
                    }
                }

                fclose($handle);
                $stmtStudent->close();

                if (!empty($studentErrors)) {
                    echo json_encode([
                        'success' => false,
                        'message' => $studentErrors[0],
                        'failedRows' => $studentErrors
                    ]);
                    exit;
                }

                $studentImportSummary = "Imported {$insertedStudents} students.";
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
                        if (!$stmtTrans) {
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
                        $header = fgetcsv($handle, 1000, "\t");

                        // Prepare insert statement for LEGAL_GUARDIAN_TAB
                        $stmtGuardian = $conn->prepare("
                            INSERT INTO LEGAL_GUARDIAN_TAB 
                                (first_name, last_name, phone, mobile, email, external_key) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");

                        while (($data = fgetcsv($handle, 1000, "\t")) !== FALSE) {
                            $first_name = $data[2] ?? null;
                            $last_name  = $data[1] ?? null;
                            $phone      = $data[7] ?? null;
                            $mobile     = $data[8] ?? null;
                            $email      = $data[6] ?? null;
                            $external   = $data[10] ?? null;

                            $stmtGuardian->bind_param(
                                "ssssss",
                                $first_name,
                                $last_name,
                                $phone,
                                $mobile,
                                $email,
                                $external
                            );
                            $stmtGuardian->execute();
                                 if ($stmtGuardian->error) {
                                error_log("STUDENT INSERT ERROR: " . $stmtGuardian->error);
                                echo "ERROR: " . $stmtGuardian->error;
                            }
                        }

                        fclose($handle);
                    }
                }


                   // Return JSON with file info to dynamically insert into table
                $response['success'] = true;
                if ($studentImportSummary !== null) {
                    $response['message'] = $studentImportSummary;
                }

                $response['file'] = [
                    'id' => $id,
                    'filename' => $originalName,
                    'filepath' => $destination,
                    'imported_by' => $importedBy,
                    'imported_date' => date('Y-m-d H:i:s'),
                    'type' => $fileType
                ];

                echo json_encode($response);
                exit;
            }
        }
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