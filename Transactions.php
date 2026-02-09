<?php
require_once __DIR__ . '/auth_check.php';
$currentPage = basename($_SERVER['PHP_SELF']);
require_once __DIR__ . '/db_connect.php';

$alert = "";

/* ========= DELETE TRANSACTION ========= */
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $stmt = $conn->prepare("DELETE FROM INVOICE_TAB WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();
        $alert = "<div class='alert alert-success'>Transaction deleted.</div>";
    } else {
        $alert = "<div class='alert alert-error'>Delete failed.</div>";
    }
}

/* ========= UPDATE TRANSACTION (INLINE EDIT) ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_transaction'])) {

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    $description = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');

    // amount: wenn leer -> nimm amount_total (damit amount nie NULL bleibt)
    $amountInput = trim($_POST['amount'] ?? '');
    $amount = ($amountInput === '') ? null : (float)$amountInput;

    if ($id <= 0) {
        $alert = "<div class='alert alert-error'>Invalid transaction id.</div>";
    } elseif ($amount !== null && $amount < 0) {
        $alert = "<div class='alert alert-error'>Amount must be >= 0.</div>";
    } else {

        $stmt = $conn->prepare("
            UPDATE INVOICE_TAB
            SET amount = CASE WHEN ? IS NULL THEN amount_total ELSE ? END,
                description = ?
            WHERE id = ?
        ");

        if ($stmt) {
            $stmt->bind_param("ddsi", $amount, $amount, $description, $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected > 0) {
                $alert = "<div class='alert alert-success'>Transaction updated successfully.</div>";
            } else {
                $alert = "<div class='alert alert-error'>No changes were made.</div>";
            }
        } else {
            $alert = "<div class='alert alert-error'>Update failed: " . htmlspecialchars($conn->error) . "</div>";
        }
    }
}

/* ========= FILTER INPUTS (aus filters.php) ========= */
$filterStudent = trim($_GET['student'] ?? '');
$filterClass   = trim($_GET['class']   ?? '');
$filterStatus  = trim($_GET['status']  ?? '');
$filterFrom    = trim($_GET['from']    ?? '');
$filterTo      = trim($_GET['to']      ?? '');
$filterMin     = trim($_GET['amount_min'] ?? '');
$filterMax     = trim($_GET['amount_max'] ?? '');

$clauses = [];
$joinSql = "";
$needsStudentJoin = false;

if ($filterStudent !== '') {
    $needsStudentJoin = true;
    $like = "%" . $conn->real_escape_string($filterStudent) . "%";
    $clauses[] = "(st.name LIKE '{$like}'
                OR st.long_name LIKE '{$like}'
                OR st.forename LIKE '{$like}')";
}

if ($filterClass !== '' && ctype_digit($filterClass)) {
    $needsStudentJoin = true;
    $clauses[] = "st.class_id = " . (int)$filterClass;
}

/* ========= STATUS FILTER (wie in student_state.php) ========= */
if ($filterStatus !== '') {
    $needsStudentJoin = true;

    if ($filterStatus === 'paid') {
        $clauses[] = "st.left_to_pay = 0";
    }
    elseif ($filterStatus === 'open') {
        $clauses[] = "st.left_to_pay > 0";
    }
    elseif ($filterStatus === 'overdue') {
        $clauses[] = "st.left_to_pay > 0 AND st.exit_date < CURDATE()";
    }
    elseif ($filterStatus === 'partial') {
        $clauses[] = "st.left_to_pay > 0";
    }
}

/* ========= DATE RANGE FILTER ========= */
if ($filterFrom !== '') {
    $clauses[] = "t.processing_date >= '" . $conn->real_escape_string($filterFrom) . "'";
}
if ($filterTo !== '') {
    $clauses[] = "t.processing_date <= '" . $conn->real_escape_string($filterTo) . "'";
}

/* ========= AMOUNT RANGE FILTER ========= */
if ($filterMin !== '' && is_numeric($filterMin)) {
    $clauses[] = "t.amount_total >= " . (float)$filterMin;
}
if ($filterMax !== '' && is_numeric($filterMax)) {
    $clauses[] = "t.amount_total <= " . (float)$filterMax;
}

/* ========= JOINS AKTIVIEREN, FALLS SCHÜLERFILTER GENUTZT ========= */
if ($needsStudentJoin) {
    $joinSql = "
        LEFT JOIN STUDENT_TAB st ON st.id = t.student_id
        LEFT JOIN CLASS_TAB c ON c.id = st.class_id
    ";
}

/* ========= WHERE-BEDINGUNG ========= */
$whereSql = empty($clauses) ? "" : "WHERE " . implode(" AND ", $clauses);

/* ========= PAGINATION ========= */
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

/* ========= GEFILTERTE ANZAHL ZEILEN ========= */
$countSql = "
    SELECT COUNT(*) AS total
    FROM INVOICE_TAB t
    {$joinSql}
    {$whereSql}
";
$countRes = $conn->query($countSql);
$totalRows = $countRes ? (int)$countRes->fetch_assoc()['total'] : 0;

$totalPages = max(1, ceil($totalRows / $limit));

if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $limit;

$paginationBase = $_GET;
unset($paginationBase['page']);

/* ========= HAUPT-SELECT (GEFILTERT + PAGINIERT) ========= */
$selectSql = "
    SELECT 
        t.id,
        t.beneficiary,
        t.description,
        t.reference,
        t.reference_number,
        t.processing_date,
        t.amount,
        t.amount_total
    FROM INVOICE_TAB t
    {$joinSql}
    {$whereSql}
    ORDER BY t.processing_date DESC
    LIMIT {$limit} OFFSET {$offset}
";


$result = $conn->query($selectSql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Transactions</title>

    <link rel="stylesheet" href="student_state_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700&family=Roboto:wght@400;500&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">

    <style>
    body {
        font-family: 'Roboto', sans-serif;
        margin: 0;
    }

    #sidebar a {
        text-decoration: none;
        color: #222;
        transition: 0.2s;
    }
    #sidebar a:hover {
        background-color: #FAE4D5;
        color: #B31E32;
    }

    /* Main content wrapper */
    #content {
        transition: margin-left 0.3s ease;
        margin-left: 0;
        padding: 100px 30px 60px;
    }
    #content.shifted {
        margin-left: 260px;
    }

    .page-title {
        font-family: 'Space Grotesk', sans-serif;
        font-weight: 700;
        color: #B31E32;
        text-align: center;
        margin-bottom: 20px;
        font-size: 28px;
    }

    .table-wrapper {
        width: 100%;
        max-width: 1300px;
        margin: 0 auto;
    }

    /* ================= TABLE ================= */
    .student-table th {
        font-family: 'Montserrat', sans-serif;
        font-weight: 600;
        color: #B31E32;
        background-color: #FAE4D5;
        text-align: center;
    }

    .student-table td {
        font-family: 'Roboto', sans-serif;
        color: #222;
        vertical-align: middle;
        text-align: center;
    }

 .student-table tbody tr.row-odd  { background-color:#FFFFFF; }
.student-table tbody tr.row-even { background-color:#FFF8EB; }


    /* Hover */
    .student-table tr:hover {
        background-color: #FAE4D5;
        transition: 0.2s;
    }

    .material-icons-outlined {
        font-size: 24px;
        vertical-align: middle;
        cursor: pointer;
    }

    /* ================= ALERTS ================= */
    .alert {
        max-width: 600px;
        margin: 0 auto 20px auto;
        padding: 10px 15px;
        border-radius: 8px;
        font-weight: 500;
        text-align: center;
    }
    .alert-success {
        background: #EAF9E6;
        color: #2E7D32;
        border: 1px solid #C5E1A5;
    }
    .alert-error {
        background: #FFE4E1;
        color: #B71C1C;
        border: 1px solid #FFAB91;
    }

    /* ================= PAGINATION ================= */
    .pagination {
        font-family: 'Montserrat', sans-serif;
    }
    .pagination .page-link {
        color: #B31E32;
        border: 1px solid #FAE4D5;
        background-color: #fff;
        font-weight: 500;
    }
    .pagination .page-link:hover {
        background-color: #FAE4D5;
        color: #B31E32;
    }
    .pagination .active .page-link {
        background-color: #B31E32;
        border-color: #B31E32;
        color: #fff;
    }
    .pagination .disabled .page-link {
        color: #ccc;
        border-color: #eee;
    }

    /* ================= INLINE EDIT ================= */
   .edit-row td {
    background: inherit;
    border-top: 2px solid #D4463B;
    padding: 15px;
}


    .edit-row input {
        background-color: #FFFFFF;
        border: 1px solid #ccc;
        border-radius: 5px;
        padding: 5px 8px;
    }

    .edit-row button {
        background: #D4463B;
        color: white;
        border: none;
        border-radius: 5px;
        padding: 5px 12px;
        font-weight: 600;
        cursor: pointer;
    }

    .edit-row button:hover {
        background: #B31E32;
    }
</style>
</head>
<body>

<?php require __DIR__ . '/navigator.php'; ?>

<div id="content">
    <h1 class="page-title">TRANSACTIONS</h1>
    <?= $alert ?>

    <?php require __DIR__ . '/filters.php'; ?>

    <div class="table-wrapper">

        <table class="student-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th>Beneficiary</th>
                    <th>Reference</th>
                    <th>Reference Nr</th>
                    <th>Description</th>
                    <th>Processing Date</th>
                    <th>Amount</th>
                    <?php if ($isAdmin): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php 
            if ($result && $result->num_rows > 0) {
                $rowIndex = 0;
    while ($t = $result->fetch_assoc()) {
        $rowIndex++;
$rowClass = ($rowIndex % 2 === 1) ? 'row-odd' : 'row-even';
        $txId = (int)$t['id'];

        echo "<tr id='row-{$txId}' class='{$rowClass}'>";
        echo "<td>".htmlspecialchars($t['beneficiary'])."</td>";
        echo "<td>".htmlspecialchars($t['reference'])."</td>";
        echo "<td>".htmlspecialchars($t['reference_number'])."</td>";
        echo "<td>".htmlspecialchars($t['description'])."</td>";
        echo "<td>".htmlspecialchars($t['processing_date'])."</td>";

        // Falls amount NULL ist, fallback auf amount_total.
        $shownAmount = ($t['amount'] !== null) ? (float)$t['amount'] : (float)$t['amount_total'];
        echo "<td>" . number_format($shownAmount, 2, ',', '.') . " Lek </td>";

        if ($isAdmin) {
            echo "<td style='text-align:center;'>
                    <span class='material-icons-outlined' style='color:#D4463B;'
                          onclick='toggleEditTx(\"{$txId}\")'>edit</span>
                    &nbsp;
                    <a href='?delete_id=".$txId."' onclick='return confirm(\"Delete transaction?\")'>
                        <span class='material-icons-outlined' style='color:#B31E32;'>delete</span>
                    </a>
                  </td>";
        }
        echo "</tr>";

        // Inline edit row (nur Admin)
        if ($isAdmin) {
            $editAmount = ($t['amount'] !== null) ? (float)$t['amount'] : (float)$t['amount_total'];

            echo "<tr class='edit-row' id='edit-{$txId}' style='display:none;'>
                    <td colspan='7'>
                        <form method='POST' style='display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:center;'>
                            <input type='hidden' name='id' value='{$txId}'>
                            <input type='hidden' name='update_transaction' value='1'>

                            <label style='margin-right:5px;'>Amount (Lek):</label>
                            <input type='number' step='0.01' min='0' name='amount'
                                   value='".htmlspecialchars((string)$editAmount, ENT_QUOTES, 'UTF-8')."'
                                   style='width:140px;' required>

                            <label style='margin-right:5px;'>Description:</label>
                            <input type='text' name='description'
                                   value='".htmlspecialchars($t['description'], ENT_QUOTES, 'UTF-8')."'
                                   style='width:360px;'>

                            <button type='submit'>Save</button>
                            <button type='button' onclick='toggleEditTx(\"{$txId}\")'>Cancel</button>
                        </form>
                    </td>
                  </tr>";
        }
    }
} else {
    $colspan = $isAdmin ? 7 : 6;
    echo "<tr><td colspan='{$colspan}' style='text-align:center;color:#888;'>No transactions found</td></tr>";
}            
            ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav aria-label="Trans pagination" style="margin-top:20px;">
        <ul class="pagination justify-content-center">
            <?php
            $prevParams = $paginationBase;
            $prevParams['page'] = max(1, $page - 1);
            ?>
            <li class="page-item <?= ($page <= 1 ? 'disabled':'') ?>">
                <a class="page-link" href="?<?= http_build_query($prevParams) ?>">Previous</a>
            </li>

            <?php for ($i=1;$i<=$totalPages;$i++): ?>
                <li class="page-item <?= ($i==$page?'active':'') ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($paginationBase,['page'=>$i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>

            <?php
            $nextParams = $paginationBase;
            $nextParams['page'] = min($totalPages, $page + 1);
            ?>
            <li class="page-item <?= ($page >= $totalPages ? 'disabled':'') ?>">
                <a class="page-link" href="?<?= http_build_query($nextParams) ?>">Next</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>
<script>
function toggleEditTx(id) {
    const row = document.getElementById("edit-" + id);
    if (!row) return;

    row.style.display =
        (row.style.display === "none" || row.style.display === "")
        ? "table-row"
        : "none";
}
</script>
</body>
</html>
