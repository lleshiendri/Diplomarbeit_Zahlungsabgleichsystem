<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$alert = "";

/* ============================================================
   DELETE STUDENT
   ============================================================ */
if (isset($_GET['delete_id']) && $_GET['delete_id'] !== '') {
    $delete_id = $_GET['delete_id'];

    $stmt = $conn->prepare("DELETE FROM STUDENT_TAB WHERE extern_key = ?");
    if ($stmt) {
        $stmt->bind_param("s", $delete_id);
        $stmt->execute();
        $stmt->close();
        $alert = "<div class='alert alert-success'>✅ Student successfully deleted.</div>";
    } else {
        $alert = "<div class='alert alert-error'>❌ Delete failed.</div>";
    }
}

/* ============================================================
   UPDATE STUDENT
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    $extern_key  = $_POST['extern_key'];
    $student_id  = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $name        = htmlspecialchars(trim($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $long_name   = htmlspecialchars(trim($_POST['long_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $left_to_pay = isset($_POST['left_to_pay']) ? (float)$_POST['left_to_pay'] : 0;

    // 1) Versuch: über extern_key updaten
    $stmt = $conn->prepare("
        UPDATE STUDENT_TAB 
        SET name = ?, long_name = ?, left_to_pay = ?
        WHERE extern_key = ?
    ");

    if ($stmt) {
        $stmt->bind_param("ssds", $name, $long_name, $left_to_pay, $extern_key);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
    } else {
        $alert = "<div class='alert alert-error'>Update fehlgeschlagen (extern_key): ".htmlspecialchars($conn->error)."</div>";
        $affected = -1;
    }

    // 2) Falls keine Zeile über extern_key geändert wurde, versuche Fallback über id
    if ($affected === 0 && $student_id > 0) {
        $stmt2 = $conn->prepare("
            UPDATE STUDENT_TAB 
            SET name = ?, long_name = ?, left_to_pay = ?
            WHERE id = ?
        ");

        if ($stmt2) {
            $stmt2->bind_param("ssdi", $name, $long_name, $left_to_pay, $student_id);
            $stmt2->execute();
            $affected = $stmt2->affected_rows;
            $stmt2->close();
        } else {
            $alert = "<div class='alert alert-error'>Update fehlgeschlagen (id): ".htmlspecialchars($conn->error)."</div>";
        }
    }

    if ($affected > 0) {
        $alert = "<div class='alert alert-success'>Student updated successfully.</div>";
    } elseif ($affected === 0) {
        $alert = "<div class='alert alert-error'>Update hat keine Zeile verändert (weder extern_key noch id gefunden).</div>";
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

/* STUDENT NAME */
if ($filterStudent !== '') {
    $like = "%" . $conn->real_escape_string($filterStudent) . "%";
    $clauses[] = "(s.name LIKE '{$like}' OR s.long_name LIKE '{$like}' OR s.forename LIKE '{$like}')";
}

/* CLASS */
if ($filterClass !== '' && ctype_digit($filterClass)) {
    $clauses[] = "s.class_id = " . (int)$filterClass;
}

/* STATUS via left_to_pay */
if ($filterStatus === "open") {
    $clauses[] = "s.left_to_pay > 0";
} elseif ($filterStatus === "paid") {
    $clauses[] = "s.left_to_pay = 0";
} elseif ($filterStatus === "overdue") {
    $clauses[] = "s.left_to_pay > 0 AND s.exit_date < CURDATE()";
} elseif ($filterStatus === "partial") {
    $clauses[] = "s.left_to_pay > 0";
}

/* DATE / AMOUNT filters require INVOICE_TAB */
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

/* Build JOIN only if needed */
$joinSql = "";
if ($needsInvoiceJoin) {
    $joinSql = "
        LEFT JOIN INVOICE_TAB i ON i.student_id = s.id
    ";
}

$whereSql = empty($clauses) ? "" : "WHERE " . implode(" AND ", $clauses);
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

/* ============================================================
   HAUPT-SELECT (GEFILTERT!)
   ============================================================ */
$selectSql = "
    SELECT
        s.id AS student_id,
        s.extern_key AS extern_key,
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

        .page-title { font-family:'Space Grotesk',sans-serif; font-weight:700; color:#B31E32; text-align:center; margin-bottom:20px; font-size:28px; }

        /* === Table Design === */
        .student-table th {
            font-family:'Montserrat',sans-serif;
            font-weight:600;
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

<div id="overlay"></div>

<div class="content">
    <h1 class="page-title">STUDENT STATE</h1>
    <?= $alert ?>

    <?php require __DIR__ . '/filters.php'; ?>

    <div class="table-wrapper" style="margin-top:25px;">
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

                    // 📌 Hier sind deine Mock-Werte – kannst du später durch echte DB-Werte ersetzen
                    $totalAmount = 1300;
                    $amountPaid  = rand(0, $totalAmount);
                    $leftToPay   = $totalAmount - $amountPaid;
                    $mockDate    = date('d/m/Y', mt_rand(strtotime('2025-01-01'), strtotime('2025-11-30')));

                    $studentId   = $row['student_id'];
                    $externKey   = $row['extern_key'];
                    $studentName = $row['student_name'];

                    echo '<tr id="row-'.$studentId.'">';
                    echo '<td>' . htmlspecialchars($studentId) . '</td>';
                    echo '<td>' . htmlspecialchars($studentName) . '</td>';
                    echo '<td>' . number_format($amountPaid, 2, ',', '.') . ' €</td>';
                    echo '<td>' . number_format($row['left_to_pay'], 2, ',', '.') . ' €</td>';
                    echo '<td>' . number_format($totalAmount, 2, ',', '.') . ' €</td>';
                    echo '<td>' . htmlspecialchars($mockDate) . '</td>';

                    // Actions
                    echo '<td style="text-align:center;">
                            <span class="material-icons-outlined" style="color:#D4463B;"
                                  onclick="toggleEdit(\''.$studentId.'\')">edit</span>
                            &nbsp;
                            <a href="?'.htmlspecialchars(http_build_query(array_merge($paginationBase, ['delete_id' => $externKey]))).'"
                               onclick="return confirm(\'Are you sure you want to delete this student?\');">
                                <span class="material-icons-outlined" style="color:#B31E32;">delete</span>
                            </a>
                          </td>';
                    echo '</tr>';

                    // Inline-Edit-Zeile
                    echo '<tr class="edit-row" id="edit-'.$studentId.'" style="display:none;">
                            <td colspan="7">
                                <form method="POST" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <input type="hidden" name="extern_key" value="'.htmlspecialchars($externKey).'">
                                    <input type="hidden" name="student_id" value="'.htmlspecialchars($studentId).'">
                                    <input type="hidden" name="update_student" value="1">

                                    <label style="margin-right:5px;">Name:</label>
                                    <input type="text" name="name" value="'.htmlspecialchars($row['name']).'" required style="width:120px;">

                                    <label style="margin-right:5px;">Long Name:</label>
                                    <input type="text" name="long_name" value="'.htmlspecialchars($studentName).'" style="width:150px;">

                                    <label style="margin-right:5px;">Left to Pay (€):</label>
                                    <input type="number" step="0.01" name="left_to_pay" value="'.htmlspecialchars($row['left_to_pay']).'" style="width:100px;">

                                    <button type="submit">Save</button>
                                    <button type="button" onclick="toggleEdit(\''.$studentId.'\')">Cancel</button>
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
            <?php
            // Helper für Link mit Filtern
            function buildPageLink($pageNum, $base) {
                $base['page'] = $pageNum;
                return '?' . http_build_query($base);
            }
            ?>
            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= ($page <= 1) ? '#' : htmlspecialchars(buildPageLink($page - 1, $paginationBase)) ?>">Previous</a>
            </li>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars(buildPageLink($i, $paginationBase)) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= ($page >= $totalPages) ? '#' : htmlspecialchars(buildPageLink($page + 1, $paginationBase)) ?>">Next</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<!-- Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.2.1.slim.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>

<script>
    // Sidebar (falls du sie aus navigator.php nutzt)
    const sidebar = document.getElementById("sidebar");
    const content = document.querySelector(".content");
    const overlay = document.getElementById("overlay");

    function openSidebar() {
        if (sidebar) sidebar.classList.add("open");
        if (content) content.classList.add("shifted");
        if (overlay) overlay.classList.add("show");
    }
    function closeSidebar() {
        if (sidebar) sidebar.classList.remove("open");
        if (content) content.classList.remove("shifted");
        if (overlay) overlay.classList.remove("show");
    }
    function toggleSidebar() {
        if (!sidebar) return;
        sidebar.classList.contains("open") ? closeSidebar() : openSidebar();
    }
</script>

<script>
function toggleEdit(id) {
    const row = document.getElementById("edit-" + id);
    if (!row) return;

    // Toggle visibility
    row.style.display =
        (row.style.display === "none" || row.style.display === "")
        ? "table-row"
        : "none";
}
</script>

</body>
</html>
