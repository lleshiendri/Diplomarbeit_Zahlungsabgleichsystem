<?php 
require_once 'auth_check.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db_connect.php';
require 'matching_functions.php';

function validateCSVStructure($filePath, $fileType) {
    // 1️⃣ Expected header sets
    $expectedHeaders = [
        'Students' => [
            'name', 'longName', 'foreName', 'gender', 'birthDate',
            'klasse.name', 'entryDate', 'exitDate', 'text', 'id',
            'externKey', 'medicalReportDuty', 'schulpflicht', 'majority',
            'address.email', 'address.mobile', 'address.phone',
            'address.city', 'address.postCode', 'address.street'
        ],
        'Transactions' => [
            'No', 'Value Date', 'Reference Number', 'Beneficairy/Ordering name and account number', 'Description',
            'Reference','Transaction Type', 'Processing Date', 'Amount', 'Amount Total'
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

    // 2️⃣ Detect delimiter automatically
    $firstLine = fgets(fopen($filePath, 'r'));
    if (strpos($firstLine, "\t") !== false) $delimiter = "\t";
    elseif (strpos($firstLine, ";") !== false) $delimiter = ";";
    else $delimiter = ",";

    // 3️⃣ Read header
    $handle = fopen($filePath, "r");

    if ($fileType === 'Students' || $fileType === 'LegalGuardians') {
    
        // These file types always have a normal header on the first line.
        $header = fgetcsv($handle, 1000, $delimiter);
    
    } else {
    
        // Transactions CSV: skip metadata until the header row starting with "No"
        do {
            $header = fgetcsv($handle, 2000, $delimiter);
            if (!$header) break;  // safety check
        } while (trim($header[0]) !== "No");
    }
    
    fclose($handle);

    if (!$header) {
        return [
            'valid' => false,
            'message' => 'Unable to read header from file.',
            'missing' => [], 'extra' => [], 'guessedType' => null
        ];
    }

    $header = array_map('trim', $header);
    $expected = $expectedHeaders[$fileType] ?? [];

    // 4️⃣ Compare header with expected columns
    $missing = array_diff($expected, $header);
    $extra   = array_diff($header, $expected);

    // ✅ If structure matches → valid
    if (empty($missing) && empty($extra)) {
        return [
            'valid' => true,
            'message' => "File structure valid for {$fileType}.",
            'missing' => [], 'extra' => [], 'guessedType' => null
        ];
    }

    // 5️⃣ Try to guess which file type it actually is
    $bestMatch = null;
    $bestScore = 0;
    foreach ($expectedHeaders as $type => $cols) {
        $common = count(array_intersect($cols, $header));
        $score = $common / max(count($cols), 1);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $type;
        }
    }

    // 6️⃣ Build return message
    $msg = "Invalid file structure for {$fileType}.";
    if ($bestMatch && $bestMatch !== $fileType && $bestScore > 0.3) {
        $msg .= " This file looks more like a {$bestMatch} file.";
    }

    return [
        'valid' => false,
        'message' => $msg,
        'missing' => array_values($missing),
        'extra' => array_values($extra),
        'guessedType' => $bestMatch
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
            $tmpPath = $_FILES['importFile']['tmp_name'];

            // Validate CSV structure before moving or inserting metadata
            $validation = validateCSVStructure($tmpPath, $fileType);
            if (!$validation['valid']) {
                echo json_encode([
                    'success' => false,
                    'message' => $validation['message'],
                    'missingColumns' => $validation['missing'],
                    'unexpectedColumns' => $validation['extra'],
                    'guessedType' => $validation['guessedType']
                ]);
                exit;
            }

            $newFileName = uniqid('import_', true) . '.' . $extension;
            $destination = $uploadDir . $newFileName;

            if (move_uploaded_file($tmpPath, $destination)) {
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
                        $header = fgetcsv($handle, 1000, "\t");

                        // Prepare insert statement for STUDENT table
                        $stmtStudent = $conn->prepare("
                            INSERT INTO STUDENT_TAB (forename, name, long_name, birth_date, left_to_pay, additional_payments_status, gender, entry_date, exit_date, description, second_ID, extern_key, email)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        if (!$stmtStudent) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'Student import prepare failed: ' . $conn->error
                            ]);
                            exit;
                        }

                        while (($data = fgetcsv($handle, 1000, "\t")) !== FALSE) {
                            // Map CSV columns to variables
                            $forename = $data[2] ?? null;
                            $name = $data[0] ?? null;
                            $long_name = $data[1] ?? null;
                            $gender = $data[3] ?? null;
                            $birth_date = normalizeDate($data[4] ?? null);
                            $left_to_pay = 0;
                            $additional_payments_status = 0;
                            $entry_date = normalizeDate($data[6] ?? null);
                            $exit_date = normalizeDate($data[7] ?? null);
                            $description = $data[8] ?? null;
                            $second_id = $data[9] ?? null;
                            $extern_key = $data[10] ?? null;
                            $email = $data[14] ?? null;

                            $stmtStudent->bind_param(
                                "ssssddssssdds",
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
                            if (!$stmtStudent->execute()) {
                                if ($stmtStudent->errno === 1062) {
                                    continue;
                                } else {
                                    error_log("Student insert error ({$stmtStudent->errno}): {$stmtStudent->error}");
                                }
                            }
                        }

                        fclose($handle);
                        $stmtStudent->close();
                    }
                }

                if ($fileType === 'Transactions') {
                    $filePath = $destination;

                    if (($handle = fopen($filePath, "r")) !== FALSE) {
                        // Skip metadata rows until we find the real header row (first column === "No")
                        $header = null;
                        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            if (isset($row[0]) && trim($row[0]) === "No") {
                                $header = $row;
                                break;
                            }
                        }

                        // If header not found, exit
                        if ($header === null) {
                            fclose($handle);
                            error_log("TRANSACTION HEADER NOT FOUND: Expected row with 'No' as first column");
                            return;
                        }

                        // Prepare insert statement for INVOICE_TAB
                        $stmtTrans = $conn->prepare("
                            INSERT INTO INVOICE_TAB 
                                (reference_number,  beneficiary, reference, transaction_type, processing_date, amount, amount_total) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        if (!$stmtTrans) {
                            error_log("TRANSACTION PREPARE FAILED: " . $conn->error);
                            fclose($handle);
                            return;
                        }

                        // Load matching engine for automatic reference-based matching
                        require_once __DIR__ . '/matching_engine.php';

                        // Parse only valid transaction rows
                        $started = false;
                        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                            // Check if first column exists and get its value
                            $firstCol = isset($data[0]) ? trim($data[0]) : '';

                            // Start parsing when first column is exactly "1"
                            if ($firstCol === "1" && !$started) {
                                $started = true;
                            }

                            // If parsing hasn't started yet, skip this row
                            if (!$started) {
                                continue;
                            }

                            // Stop parsing if first column is empty or non-numeric
                            if ($firstCol === '' || !ctype_digit($firstCol)) {
                                break;
                            }

                            // Only parse rows where first column is numeric (valid transaction rows)
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
                            if ($stmtTrans->execute()) {
                                // Get the inserted invoice ID
                                $invoice_id = $conn->insert_id;
                                
                                // Run matching algorithm and log to MATCHING_HISTORY_TAB
                                if ($invoice_id) {
                                    processInvoiceMatching($conn, $invoice_id, $reference_number, $beneficiary, $reference);
                                }
                            }
                        }

                        $stmtTrans->close();
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
                        if (!$stmtGuardian) {
                            echo json_encode([
                                'success' => false,
                                'message' => 'Guardian import prepare failed: ' . $conn->error
                            ]);
                            exit;
                        }

                        while (($data = fgetcsv($handle, 1000, "\t")) !== FALSE) {
                            $first_name = $data[2] ?? null;
                            $last_name  = $data[1] ?? null;
                            $phone      = $data[7] ?? null;
                            $mobile     = $data[8] ?? null;
                            $email      = $data[6] ?? null;
                            $external_key = $data[10] ?? null;

                            $stmtGuardian->bind_param(
                                "ssssss",
                                $first_name,
                                $last_name,
                                $phone,
                                $mobile,
                                $email,
                                $external_key
                            );
                            if (!$stmtGuardian->execute()) {
                                if ($stmtGuardian->errno === 1062) {
                                    continue;
                                } else {
                                    error_log("Guardian insert error ({$stmtGuardian->errno}): {$stmtGuardian->error}");
                                }
                            }
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

require_once 'navigator.php';
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
<body class="imports-page">

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