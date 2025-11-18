<?php
require_once __DIR__ . '/auth_check.php';
$currentPage = basename($_SERVER['PHP_SELF']);
require_once __DIR__ . '/db_connect.php';
require __DIR__ . '/filters.php'; 

$alert = "";

// ===== DELETE STUDENT =====
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM STUDENT_TAB WHERE extern_key = ?");
    if ($stmt) {
        $stmt->bind_param("s", $delete_id);
        $stmt->execute();
        $stmt->close();
        $alert = "<div class='alert alert-success'>Student successfully deleted.</div>";
    } else {
        $alert = "<div class='alert alert-error'>Delete failed.</div>";
    }
}

// ===== UPDATE STUDENT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    $extern_key  = $_POST['extern_key'];
    $name        = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $long_name   = htmlspecialchars(trim($_POST['long_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $left_to_pay = isset($_POST['left_to_pay']) ? (float)$_POST['left_to_pay'] : 0;

    $stmt = $conn->prepare("UPDATE STUDENT_TAB SET name=?, long_name=?, left_to_pay=? WHERE extern_key=?");
    if ($stmt) {
        $stmt->bind_param("ssds", $name, $long_name, $left_to_pay, $extern_key);
        $stmt->execute();
        $stmt->close();
        $alert = "<div class='alert alert-success'>Student successfully updated.</div>";
    } else {
        $alert = "<div class='alert alert-error'>Update failed.</div>";
    }
}

// ===== FILTER HANDLING =====
$filterStudent = trim($_GET['student'] ?? '');
$filterClass   = trim($_GET['class'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');
$filterFrom    = trim($_GET['from'] ?? '');
$filterTo      = trim($_GET['to'] ?? '');
$filterMin     = trim($_GET['amount_min'] ?? '');
$filterMax     = trim($_GET['amount_max'] ?? '');
$filterApplied = isset($_GET['applied']);

$clauses = [];
$needsInvoiceJoin = false;

if ($filterStudent !== '') {
    $like = "%" . $conn->real_escape_string($filterStudent) . "%";
    $clauses[] = "(s.name LIKE '{$like}' OR s.long_name LIKE '{$like}' OR s.forename LIKE '{$like}')";
}

if ($filterClass !== '' && ctype_digit($filterClass)) {
    $clauses[] = "s.class_id = " . (int)$filterClass;
}

if ($filterStatus === "open") {
    $clauses[] = "s.left_to_pay > 0";
} elseif ($filterStatus === "paid") {
    $clauses[] = "s.left_to_pay = 0";
} elseif ($filterStatus === "overdue") {
    $clauses[] = "s.left_to_pay > 0 AND s.exit_date < NOW()";
} elseif ($filterStatus === "partial") {
    $clauses[] = "s.left_to_pay > 0";
}

if ($filterFrom !== '') {
    $needsInvoiceJoin = true;
    $clauses[] = "i.processing_date >= '" . $conn->real_escape_string($filterFrom) . "'";
}
if ($filterTo !== '') {
    $needsInvoiceJoin = true;
    $clauses[] = "i.processing_date <= '" . $conn->real_escape_string($filterTo) . "'";
}
if ($filterMin !== '' && is_numeric($filterMin)) {
    $needsInvoiceJoin = true;
    $clauses[] = "i.amount_total >= " . (float)$filterMin;
}
if ($filterMax !== '' && is_numeric($filterMax)) {
    $needsInvoiceJoin = true;
    $clauses[] = "i.amount_total <= " . (float)$filterMax;
}

$joinSql = "";
if ($needsInvoiceJoin) {
    $joinSql = "
        LEFT JOIN LEGAL_GUARDIAN_STUDENT_TAB lgs ON lgs.student_id = s.id
        LEFT JOIN INVOICE_TAB i ON i.legal_guardian_id = lgs.legal_guardian_id
    ";
}

$whereSql = empty($clauses) ? "" : "WHERE " . implode(" AND ", $clauses);

// ===== PAGINATION =====
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$countSql = "
    SELECT COUNT(DISTINCT s.id) AS total
    FROM STUDENT_TAB s
    {$joinSql}
    {$whereSql}
";
$countRes = $conn->query($countSql);
$totalRows = $countRes ? (int)($countRes->fetch_assoc()['total'] ?? 0) : 0;
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $limit;

$paginationBase = $_GET;
unset($paginationBase['page']);

$selectSql = "
    SELECT
        s.id,
        s.extern_key AS student_id,
        s.long_name AS student_name,
        s.name,
        s.left_to_pay
    FROM STUDENT_TAB s
    {$joinSql}
    {$whereSql}
    GROUP BY s.id, s.extern_key, s.long_name, s.name, s.left_to_pay
    ORDER BY s.id ASC
    LIMIT {$limit} OFFSET {$offset}
";
$result = $conn->query($selectSql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student State</title>

    <link rel="stylesheet" href="student_state_style.css">

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700&family=Roboto:wght@400;500&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

    <!-- Bootstrap für Pagination -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">

    <style>
        body { font-family: 'Roboto', sans-serif; }
        #sidebar a { text-decoration:none; color:#222; transition:0.2s; }
        #sidebar a:hover { background-color:#FAE4D5; color:#B31E32; }
        .content { transition:margin-left 0.3s ease; margin-left:0; padding:100px 30px 60px; }
        .content.shifted { margin-left:260px; }
        #overlay { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.2); opacity:0; visibility:hidden; transition:opacity 0.3s ease; z-index:8; }
        #overlay.show { opacity:1; visibility:visible; }
        .page-title { font-family:'Space Grotesk',sans-serif; font-weight:700; color:#B31E32; text-align:center; margin-bottom:20px; font-size:28px;  top: 0;}

        /* === Table Design === */
        .student-table th { 
            font-family:'Montserrat',sans-serif; 
            font-weight:00; 
            color:#B31E32; 
            background-color:#FAE4D5; 
            text-align:center;
        }
        .student-table td { 
            font-family:'Roboto',sans-serif; 
            color:#222; 
            vertical-align:middle;
            text-align:center;
        }
        .student-table tbody tr:nth-child(odd){background-color:#FFFFFF;}
        .student-table tbody tr:nth-child(even){background-color:#fff8eb;}
        .student-table tr:hover { background-color:#FAE4D5; transition:0.2s; }

        .material-icons-outlined { font-size:24px; vertical-align:middle; cursor:pointer; }
        .edit-row td { background:#FFF8EB; border-top:2px solid #D4463B; padding:15px; }
        .edit-row input { width:100%; border:1px solid #ccc; border-radius:5px; padding:5px 8px; }
        .edit-row button { background:#D4463B; color:white; border:none; border-radius:5px; padding:5px 12px; font-weight:600; }
        .edit-row button:hover { background:#B31E32; }
        .alert { max-width:600px; margin:0 auto 20px auto; padding:10px 15px; border-radius:8px; font-weight:500; text-align:center; }
        .alert-success { background:#EAF9E6; color:#2E7D32; border:1px solid #C5E1A5; }
        .alert-error { background:#FFE4E1; color:#B71C1C; border:1px solid #FFAB91; }

        /* === Pagination Styling === */
        .pagination { font-family:'Montserrat',sans-serif; }
        .pagination .page-link {
            color:#B31E32;
            border:1px solid #FAE4D5;
            background-color:#fff;
            font-weight:500;
        }
        .pagination .page-link:hover {
            background-color:#FAE4D5;
            color:#B31E32;
        }
        .pagination .active .page-link {
            background-color:#B31E32;
            border-color:#B31E32;
            color:#fff;
        }
        .pagination .disabled .page-link {
            color:#ccc;
            border-color:#eee;
        }

        .content {
    display: flex;
    flex-direction: column;
    align-items: center;     
    text-align: center;     
}
.table-wrapper {
    width: 100%;
    max-width: 1300px;      
    margin: 0 auto;
}
.student-table {
    margin: 0 auto;         
}
    </style>
</head>
<body>
<?php require __DIR__ . '/navigator.php'; ?>

<?php
require "db_connect.php";
require "navigator.php";

$alert = "";

// ===== DELETE STUDENT =====
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM STUDENT_TAB WHERE extern_key = ?");
    if ($stmt) {
        $stmt->bind_param("s", $delete_id);
        $stmt->execute();
        $stmt->close();
        $alert = "<div class='alert alert-success'>Student successfully deleted.</div>";
    } else {
        $alert = "<div class='alert alert-error'>❌ Delete failed.</div>";
    }
}

// ===== UPDATE STUDENT =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    $extern_key  = $_POST['extern_key'];
    $name        = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $long_name   = htmlspecialchars(trim($_POST['long_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $left_to_pay = isset($_POST['left_to_pay']) ? (float)$_POST['left_to_pay'] : 0;

    $stmt = $conn->prepare("UPDATE STUDENT_TAB SET name=?, long_name=?, left_to_pay=? WHERE extern_key=?");
    if ($stmt) {
        $stmt->bind_param("ssds", $name, $long_name, $left_to_pay, $extern_key);
        $stmt->execute();
        $stmt->close();
        $alert = "<div class='alert alert-success'>Student successfully updated.</div>";
    } else {
        $alert = "<div class='alert alert-error'>Update failed.</div>";
    }
}

// ===== FETCH TABLE =====
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$countRes = $conn->query("SELECT COUNT(*) AS total FROM STUDENT_TAB");
$totalRows = $countRes->fetch_assoc()['total'] ?? 0;
$totalPages = ceil($totalRows / $limit);

/*  
------------------------------------------
 FIX #1 — USE THE REAL COLUMN NAME "id"
------------------------------------------
*/
$result = $conn->query("
    SELECT id AS student_id, long_name AS student_name, name, left_to_pay
    FROM STUDENT_TAB
    ORDER BY id ASC
    LIMIT $limit OFFSET $offset
");
?>

<div class="content">
    <h1 class="page-title">STUDENT STATE</h1>
    <?= $alert ?>

    <div class="table-wrapper">
        <table class="student-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Amount Paid</th>
                    <th>Left to Pay</th>
                    <th>Total Amount</th>
                    <th>Last Transaction</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $totalAmount = 1300;
                    $amountPaid  = rand(0, $totalAmount);
                    $leftToPay   = $totalAmount - $amountPaid;
                    $mockDate    = date('d/m/Y', mt_rand(strtotime('2025-01-01'), strtotime('2025-11-30')));

                    echo '<tr id="row-'.$row['student_id'].'">';
                    echo '<td>' . htmlspecialchars($row['student_id']) . '</td>';
                    echo '<td>' . htmlspecialchars($row['student_name']) . '</td>';
                    echo '<td>' . number_format($amountPaid, 2, ',', '.') . ' €</td>';
                    echo '<td>' . number_format($leftToPay, 2, ',', '.') . ' €</td>';
                    echo '<td>' . number_format($totalAmount, 2, ',', '.') . ' €</td>';
                    echo '<td>' . htmlspecialchars($mockDate) . '</td>';

                    // ✳️ CHANGE 1: use studentStateToggleEdit instead of toggleEdit
                    echo '<td style="text-align:center;">
                            <span class="material-icons-outlined" style="color:#D4463B;" onclick="studentStateToggleEdit(\''.$row['student_id'].'\')">edit</span>
                            &nbsp;
                            <a href="?delete_id=' . urlencode($row['student_id']) . '" onclick="return confirm(\'Are you sure you want to delete this student?\');">
                                <span class="material-icons-outlined" style="color:#B31E32;">delete</span>
                            </a>
                          </td>';

                    echo '</tr>';

                    // Hidden inline edit row
                    echo '<tr class="edit-row" id="edit-'.$row['student_id'].'" style="display:none;">
                            <td colspan="7">
                                <form method="POST" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <input type="hidden" name="extern_key" value="'.$row['student_id'].'">
                                    <label style="margin-right:5px;">Name:</label>
                                    <input type="text" name="name" value="'.htmlspecialchars($row['name']).'" required style="width:120px;">
                                    <label style="margin-right:5px;">Long Name:</label>
                                    <input type="text" name="long_name" value="'.htmlspecialchars($row['student_name']).'" style="width:150px;">
                                    <label style="margin-right:5px;">Left to Pay (€):</label>
                                    <input type="number" step="0.01" name="left_to_pay" value="'.htmlspecialchars($row['left_to_pay']).'" style="width:100px;">
                                    <button type="submit" name="update_student">Save</button>
                                    <!-- ✳️ CHANGE 2: also use studentStateToggleEdit here -->
                                    <button type="button" onclick="studentStateToggleEdit(\''.$row['student_id'].'\')">Cancel</button>
                                </form>
                            </td>
                          </tr>';
                }
            } else {
                echo '<tr><td colspan="7" style="text-align:center; color:#888;">No students found</td></tr>';
            }
            ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav aria-label="Student pagination" style="margin-top:20px;">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= max(1, $page - 1) ?>">Previous</a>
            </li>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="?page=<?= min($totalPages, $page + 1) ?>">Next</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.2.1.slim.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>

<script>
   let studentSidebar = document.getElementById("sidebar");
let studentContent = document.querySelector(".content");
let studentOverlay = document.getElementById("overlay");
function openSidebar() {
    if (studentSidebar) studentSidebar.classList.add("open");
    if (studentContent) studentContent.classList.add("shifted");
    if (studentOverlay) studentOverlay.classList.add("show");
}
function closeSidebar() {
    if (studentSidebar) studentSidebar.classList.remove("open");
    if (studentContent) studentContent.classList.remove("shifted");
    if (studentOverlay) studentOverlay.classList.remove("show");
}
function toggleSidebar() {
    if (!studentSidebar) return;
    studentSidebar.classList.contains("open") ? closeSidebar() : openSidebar();
}


    // ✳️ CHANGE 3: unique function name to avoid collisions
    function studentStateToggleEdit(id) {
        const editRow = document.getElementById("edit-" + id);
        if (!editRow) return;
        editRow.style.display =
            (editRow.style.display === "none" || editRow.style.display === "")
            ? "table-row"
            : "none";
    }
</script>
</body>
</html>
