<?php
require_once __DIR__ . '/auth_check.php';
$currentPage = basename($_SERVER['PHP_SELF']);
require_once __DIR__ . '/db_connect.php';

$alert = '';

/* ========= DELETE MATCHING HISTORY ROW ========= */
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    if ($delete_id > 0) {
        $row = $conn->query("SELECT invoice_id FROM MATCHING_HISTORY_TAB WHERE id = $delete_id LIMIT 1");
        $invoice_id = ($row && ($r = $row->fetch_assoc())) ? (int)$r['invoice_id'] : 0;

        $stmt = $conn->prepare("DELETE FROM MATCHING_HISTORY_TAB WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $delete_id);
            $stmt->execute();
            $stmt->close();

            if ($invoice_id > 0) {
                $conn->query("UPDATE INVOICE_TAB SET student_id = NULL WHERE id = $invoice_id");
            }
            $alert = "<div class='alert alert-success'>Matching record deleted. Student left_to_pay was adjusted by trigger.</div>";
        } else {
            $alert = "<div class='alert alert-error'>Delete failed.</div>";
        }
    }
}

/* ========= UPDATE MATCHING HISTORY (CHANGE STUDENT) ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_matching'])) {
    $history_id  = isset($_POST['history_id']) ? (int)$_POST['history_id'] : 0;
    $new_student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;

    if ($history_id <= 0) {
        $alert = "<div class='alert alert-error'>Invalid matching record id.</div>";
    } else {
        $row = $conn->query("SELECT invoice_id, student_id FROM MATCHING_HISTORY_TAB WHERE id = $history_id LIMIT 1");
        if (!$row || !($r = $row->fetch_assoc())) {
            $alert = "<div class='alert alert-error'>Matching record not found.</div>";
        } else {
            $invoice_id = (int)$r['invoice_id'];
            $stmt = $conn->prepare("UPDATE MATCHING_HISTORY_TAB SET student_id = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $new_student_id, $history_id);
                $stmt->execute();
                $stmt->close();
                $conn->query("UPDATE INVOICE_TAB SET student_id = " . ($new_student_id > 0 ? $new_student_id : "NULL") . " WHERE id = $invoice_id");
                $alert = "<div class='alert alert-success'>Student assignment updated. left_to_pay was adjusted by trigger.</div>";
            } else {
                $alert = "<div class='alert alert-error'>Update failed.</div>";
            }
        }
    }
}

/* ========= FILTERS ========= */
$filterStudent   = trim($_GET['student'] ?? '');
$filterRef       = trim($_GET['reference_number'] ?? '');
$filterFrom      = trim($_GET['from'] ?? '');
$filterTo        = trim($_GET['to'] ?? '');

$clauses = [];
$clauses[] = "h.is_confirmed = 1";
if ($filterStudent !== '') {
    $like = '%' . $conn->real_escape_string($filterStudent) . '%';
    $clauses[] = "(st.long_name LIKE '{$like}' OR st.name LIKE '{$like}' OR st.forename LIKE '{$like}')";
}
if ($filterRef !== '') {
    $like = '%' . $conn->real_escape_string($filterRef) . '%';
    $clauses[] = "i.reference_number LIKE '{$like}'";
}
if ($filterFrom !== '') {
    $clauses[] = "DATE(h.created_at) >= '" . $conn->real_escape_string($filterFrom) . "'";
}
if ($filterTo !== '') {
    $clauses[] = "DATE(h.created_at) <= '" . $conn->real_escape_string($filterTo) . "'";
}

$whereSql = empty($clauses) ? '' : 'WHERE ' . implode(' AND ', $clauses);

/* ========= PAGINATION ========= */
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$countSql = "
    SELECT COUNT(*) AS total
    FROM MATCHING_HISTORY_TAB h
    JOIN INVOICE_TAB i ON i.id = h.invoice_id
    LEFT JOIN STUDENT_TAB st ON st.id = h.student_id
    $whereSql
";
$countRes = $conn->query($countSql);
$totalRows = $countRes ? (int)$countRes->fetch_assoc()['total'] : 0;
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $limit;

$paginationBase = $_GET;
unset($paginationBase['page']);

/* ========= FETCH ALL STUDENTS FOR DROPDOWN ========= */
$studentsList = [];
$studentsRes = $conn->query("SELECT id, long_name FROM STUDENT_TAB ORDER BY long_name ASC");
if ($studentsRes) {
    while ($s = $studentsRes->fetch_assoc()) {
        $studentsList[] = ['id' => (int)$s['id'], 'long_name' => $s['long_name']];
    }
}

/* ========= MAIN QUERY ========= */
$selectSql = "
    SELECT
        h.id AS history_id,
        h.invoice_id,
        h.student_id,
        h.confidence_score,
        h.matched_by,
        h.is_confirmed,
        h.created_at,
        i.reference_number,
        i.amount_total,
        i.processing_date,
        i.beneficiary,
        st.long_name AS student_name
    FROM MATCHING_HISTORY_TAB h
    JOIN INVOICE_TAB i ON i.id = h.invoice_id
    LEFT JOIN STUDENT_TAB st ON st.id = h.student_id
    $whereSql
    ORDER BY h.created_at DESC
    LIMIT $limit OFFSET $offset
";
$result = $conn->query($selectSql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Matching History</title>

    <link rel="stylesheet" href="student_state_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700&family=Roboto:wght@400;500&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">

    <style>
    body { font-family: 'Roboto', sans-serif; margin: 0; }
    #sidebar a { text-decoration: none; color: #222; transition: 0.2s; }
    #sidebar a:hover { background-color: #FAE4D5; color: #B31E32; }

    #content {
        transition: margin-left 0.3s ease;
        margin-left: 0;
        padding: 100px 30px 60px;
    }
    #content.shifted { margin-left: 260px; }

    .page-title {
        font-family: 'Space Grotesk', sans-serif;
        font-weight: 700;
        color: #B31E32;
        text-align: center;
        margin-bottom: 20px;
        font-size: 28px;
    }

    .table-wrapper { width: 100%; max-width: 1400px; margin: 0 auto; }

    .student-table th {
        font-family: 'Montserrat', sans-serif;
        font-weight: 600;
        color: #B31E32;
        background-color: #FAE4D5;
        text-align: center;
        padding: 12px 8px;
    }
    .student-table td {
        font-family: 'Roboto', sans-serif;
        color: #222;
        vertical-align: middle;
        text-align: center;
        padding: 10px 8px;
    }
    .student-table tbody tr.row-odd { background-color: #FFFFFF; }
    .student-table tbody tr.row-even { background-color: #FFF8EB; }
    .student-table tbody tr:hover { background-color: #FAE4D5; transition: 0.2s; }

    .material-icons-outlined { font-size: 24px; vertical-align: middle; cursor: pointer; }

    .alert {
        max-width: 600px;
        margin: 0 auto 20px auto;
        padding: 10px 15px;
        border-radius: 8px;
        font-weight: 500;
        text-align: center;
    }
    .alert-success { background: #EAF9E6; color: #2E7D32; border: 1px solid #C5E1A5; }
    .alert-error { background: #FFE4E1; color: #B71C1C; border: 1px solid #FFAB91; }

    .pagination { font-family: 'Montserrat', sans-serif; }
    .pagination .page-link {
        color: #B31E32;
        border: 1px solid #FAE4D5;
        background-color: #fff;
        font-weight: 500;
    }
    .pagination .page-link:hover { background-color: #FAE4D5; color: #B31E32; }
    .pagination .active .page-link {
        background-color: #B31E32;
        border-color: #B31E32;
        color: #fff;
    }
    .pagination .disabled .page-link { color: #ccc; border-color: #eee; }

    .edit-row { display: none; }
    .edit-row td {
        background: #FFF8EB;
        border-top: 2px solid #D4463B;
        padding: 15px;
        text-align: left;
    }
    .edit-row select {
        background-color: #fff;
        border: 1px solid #ccc;
        border-radius: 5px;
        padding: 6px 10px;
        min-width: 220px;
    }
    .edit-row button {
        background: #D4463B;
        color: white;
        border: none;
        border-radius: 5px;
        padding: 6px 14px;
        font-weight: 600;
        cursor: pointer;
        margin-right: 8px;
    }
    .edit-row button:hover { background: #B31E32; }
    .edit-row button[type="button"] { background: #666; }
    .edit-row button[type="button"]:hover { background: #444; }

    .badge-confirmed { background: #4CAF50; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
    .badge-unconfirmed { background: #FF9800; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>

<?php require __DIR__ . '/navigator.php'; ?>

<div id="content">
    <h1 class="page-title">MATCHING HISTORY</h1>
    <?= $alert ?>

    <?php require __DIR__ . '/filters.php'; ?>

    <div class="table-wrapper">
        <table class="student-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th>Id</th>
                    <th>Invoice Ref</th>
                    <th>Amount</th>
                    <th>Processing Date</th>
                    <th>Student</th>
                    <th>Matched By</th>
                    <th>Confidence</th>
                    <th>Confirmed</th>
                    <th>Created</th>
                    <?php if ($isAdmin): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            if ($result && $result->num_rows > 0) {
                $rowIndex = 0;
                while ($row = $result->fetch_assoc()) {
                    $rowIndex++;
                    $rowClass = ($rowIndex % 2 === 1) ? 'row-odd' : 'row-even';
                    $hid = (int)$row['history_id'];
                    $studentName = $row['student_name'] !== null ? htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8') : '—';
                    $confirmed = !empty($row['is_confirmed']);
                    $createdAt = $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '—';
                    $procDate = $row['processing_date'] ? date('Y-m-d', strtotime($row['processing_date'])) : '—';

                    echo "<tr id='row-{$hid}' class='{$rowClass}'>";
                    echo '<td>' . (int)$row['history_id'] . '</td>';
                    echo '<td>' . htmlspecialchars($row['reference_number'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td>' . number_format((float)($row['amount_total'] ?? 0), 2, ',', '.') . ' ' . CURRENCY . '</td>';
                    echo '<td>' . $procDate . '</td>';
                    echo '<td>' . $studentName . '</td>';
                    echo '<td>' . htmlspecialchars($row['matched_by'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
                    echo '<td>' . (isset($row['confidence_score']) ? round((float)$row['confidence_score'], 0) . '%' : '—') . '</td>';
                    echo '<td><span class="' . ($confirmed ? 'badge-confirmed' : 'badge-unconfirmed') . '">' . ($confirmed ? 'Yes' : 'No') . '</span></td>';
                    echo '<td>' . $createdAt . '</td>';

                    if ($isAdmin) {
                        $delUrl = '?' . http_build_query(array_merge($paginationBase, ['delete_id' => $hid]));
                        echo "<td style='text-align:center;'>";
                        echo "<span class='material-icons-outlined' style='color:#D4463B;' onclick='toggleEditMh({$hid})' title='Edit student'>edit</span> ";
                        echo "<a href='" . htmlspecialchars($delUrl) . "' onclick='return confirm(\"Delete this matching record? The amount will be added back to the student\\'s left_to_pay.\");'>";
                        echo "<span class='material-icons-outlined' style='color:#B31E32;' title='Delete'>delete</span></a>";
                        echo '</td>';
                    }
                    echo '</tr>';

                    if ($isAdmin) {
                        $currentStudentId = (int)($row['student_id'] ?? 0);
                        echo "<tr class='edit-row' id='edit-{$hid}'>";
                        echo '<td colspan="10">';
                        echo '<form method="POST" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">';
                        echo '<input type="hidden" name="history_id" value="' . $hid . '">';
                        echo '<input type="hidden" name="update_matching" value="1">';
                        echo '<label style="font-weight:600;">Assign to student:</label>';
                        echo '<select name="student_id">';
                        echo '<option value="0">— Unassign —</option>';
                        foreach ($studentsList as $s) {
                            $sel = ($s['id'] === $currentStudentId) ? ' selected' : '';
                            echo '<option value="' . $s['id'] . '"' . $sel . '>' . htmlspecialchars($s['long_name'], ENT_QUOTES, 'UTF-8') . ' (#' . $s['id'] . ')</option>';
                        }
                        echo '</select>';
                        echo '<button type="submit">Save</button>';
                        echo '<button type="button" onclick="toggleEditMh(' . $hid . ')">Cancel</button>';
                        echo '</form>';
                        echo '</td></tr>';
                    }
                }
            } else {
                $colspan = $isAdmin ? 10 : 9;
                echo "<tr><td colspan='{$colspan}' style='text-align:center;color:#888;'>No matching history records found.</td></tr>";
            }
            ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav aria-label="Matching history pagination" style="margin-top:20px;">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($paginationBase, ['page' => max(1, $page - 1)]))) ?>">Previous</a>
            </li>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($paginationBase, ['page' => $i]))) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($paginationBase, ['page' => min($totalPages, $page + 1)]))) ?>">Next</a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<script>
function toggleEditMh(id) {
    var row = document.getElementById('edit-' + id);
    if (!row) return;
    row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
}
</script>
</body>
</html>
