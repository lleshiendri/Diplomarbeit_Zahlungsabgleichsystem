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

/* ========= FILTERS ========= */
$filterReference = trim($_GET['reference'] ?? '');
$filterType      = trim($_GET['type'] ?? '');
$filterFrom      = trim($_GET['from'] ?? '');
$filterTo        = trim($_GET['to'] ?? '');
$filterMin       = trim($_GET['min'] ?? '');
$filterMax       = trim($_GET['max'] ?? '');

$clauses = [];

if ($filterReference !== "") {
    $like = "%" . $conn->real_escape_string($filterReference) . "%";
    $clauses[] = "(reference_number LIKE '{$like}' OR reference LIKE '{$like}' OR beneficiary LIKE '{$like}')";
}
if ($filterType !== "") {
    $clauses[] = "transaction_type = '" . $conn->real_escape_string($filterType) . "'";
}
if ($filterFrom !== "") {
    $clauses[] = "processing_date >= '" . $conn->real_escape_string($filterFrom) . "'";
}
if ($filterTo !== "") {
    $clauses[] = "processing_date <= '" . $conn->real_escape_string($filterTo) . "'";
}
if ($filterMin !== "" && is_numeric($filterMin)) {
    $clauses[] = "amount_total >= " . (float)$filterMin;
}
if ($filterMax !== "" && is_numeric($filterMax)) {
    $clauses[] = "amount_total <= " . (float)$filterMax;
}

$whereSql = empty($clauses) ? "" : "WHERE " . implode(" AND ", $clauses);

/* ========= PAGINATION ========= */
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$countSql = "SELECT COUNT(*) AS total FROM INVOICE_TAB {$whereSql}";
$countRes = $conn->query($countSql);
$totalRows = $countRes ? (int)$countRes->fetch_assoc()['total'] : 0;
$totalPages = max(1, ceil($totalRows / $limit));

if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$paginationBase = $_GET;
unset($paginationBase['page']);

/* ========= SELECT PAGINATED TRANSACTIONS ========= */
$selectSql = "
    SELECT id, beneficiary, reference_number, reference, processing_date
    FROM INVOICE_TAB
    ORDER BY processing_date DESC
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
        body { font-family: 'Roboto', sans-serif; margin:0; }
        #sidebar a { text-decoration:none; color:#222; transition:0.2s; }
        #sidebar a:hover { background-color:#FAE4D5; color:#B31E32; }

        /* Main content wrapper (same behavior as other pages) */
        #content {
            transition: margin-left 0.3s ease;
            margin-left: 0;
            padding: 100px 30px 60px;
        }
        #content.shifted { margin-left: 260px; }

        .page-title {
            font-family:'Space Grotesk',sans-serif;
            font-weight:700;
            color:#B31E32;
            text-align:center;
            margin-bottom:20px;
            font-size:28px;
        }

        .table-wrapper {
            width:100%;
            max-width:1300px;
            margin:0 auto;
        }

        /* === Table Design (identical to student_state) === */
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

        .alert { max-width:600px; margin:0 auto 20px auto; padding:10px 15px; border-radius:8px; font-weight:500; text-align:center; }
        .alert-success { background:#EAF9E6; color:#2E7D32; border:1px solid #C5E1A5; }
        .alert-error { background:#FFE4E1; color:#B71C1C; border:1px solid #FFAB91; }

        /* === Pagination Styling (identical to student_state) === */
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
    </style>
</head>
<body>

<?php require __DIR__ . '/navigator.php'; ?>

<div id="content">
    <h1 class="page-title">TRANSACTIONS</h1>
    <?= $alert ?>

    <div class="table-wrapper">
        <!-- TABLE -->
        <table class="student-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th>Beneficiary</th>
                    <th>Reference Nr</th>
                    <th>Reference</th>
                    <th>Processing Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php 
            if ($result && $result->num_rows > 0) {
                while ($t = $result->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td>".htmlspecialchars($t['beneficiary'])."</td>";
                    echo "<td>".htmlspecialchars($t['reference_number'])."</td>";
                    echo "<td>".htmlspecialchars($t['reference'])."</td>";
                    echo "<td>".htmlspecialchars($t['processing_date'])."</td>";
                    echo "<td>
                            <a href='?delete_id=".$t['id']."' onclick='return confirm(\"Delete transaction?\")'>
                                <span class='material-icons-outlined' style='color:#B31E32;'>delete</span>
                            </a>
                          </td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='5' style='text-align:center;color:#888;'>No transactions found</td></tr>";
            }
            ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
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

</body>
</html>
