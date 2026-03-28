<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$alert = "";

/* ============================================================
   DELETE GUARDIAN
   ============================================================ */
if (isset($_GET['delete_id']) && is_numeric($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];

    $conn->begin_transaction();

    $stmtLink = $conn->prepare("DELETE FROM LEGAL_GUARDIAN_STUDENT_TAB WHERE legal_guardian_id = ?");
    if ($stmtLink) {
        $stmtLink->bind_param("i", $delete_id);
        $stmtLink->execute();
        $stmtLink->close();
    }

    $stmt = $conn->prepare("DELETE FROM LEGAL_GUARDIAN_TAB WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $delete_id);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($ok && $affected > 0) {
            $conn->commit();
            $alert = "<div class='alert alert-success'>Guardian successfully deleted.</div>";
        } else {
            $conn->rollback();
            $alert = "<div class='alert alert-error'>Delete failed — guardian not found.</div>";
        }
    } else {
        $conn->rollback();
        $alert = "<div class='alert alert-error'>Delete failed: " . htmlspecialchars($conn->error) . "</div>";
    }
}

/* ============================================================
   INLINE EDIT (UPDATE)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_guardian'])) {
    $gid        = isset($_POST['guardian_id']) ? (int)$_POST['guardian_id'] : 0;
    $first_name = htmlspecialchars(trim($_POST['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $last_name  = htmlspecialchars(trim($_POST['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $email      = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $phone      = htmlspecialchars(trim($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
    $mobile     = htmlspecialchars(trim($_POST['mobile'] ?? ''), ENT_QUOTES, 'UTF-8');
    $extern_key = htmlspecialchars(trim($_POST['extern_key'] ?? ''), ENT_QUOTES, 'UTF-8');

    if ($gid > 0) {
        $stmt = $conn->prepare("
            UPDATE LEGAL_GUARDIAN_TAB
            SET first_name = ?, last_name = ?, email = ?, phone = ?, mobile = ?, extern_key = ?
            WHERE id = ?
        ");
        if ($stmt) {
            $stmt->bind_param("ssssssi", $first_name, $last_name, $email, $phone, $mobile, $extern_key, $gid);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected > 0) {
                $alert = "<div class='alert alert-success'>Guardian updated successfully.</div>";
            } else {
                $alert = "<div class='alert alert-error'>No changes were made.</div>";
            }
        } else {
            $alert = "<div class='alert alert-error'>Update failed: " . htmlspecialchars($conn->error) . "</div>";
        }
    }
}

/* ============================================================
   FILTER + SEARCH + SORT + PAGINATION
   ============================================================ */
$searchQ       = trim($_GET['q'] ?? '');
$filterFirst   = trim($_GET['first_name'] ?? '');
$filterLast    = trim($_GET['last_name'] ?? '');
$filterExtKey  = trim($_GET['extern_key'] ?? '');
$filterEmail   = trim($_GET['email'] ?? '');
$sortKey       = trim($_GET['sort'] ?? 'id');
$sortDir       = strtolower(trim($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

$allowedSort = [
    'id'         => 'g.id',
    'first_name' => 'g.first_name',
    'last_name'  => 'g.last_name',
    'extern_key' => 'g.extern_key',
    'email'      => 'g.email',
];
$orderByCol = $allowedSort[$sortKey] ?? 'g.id';
$orderBySql = $orderByCol . ' ' . strtoupper($sortDir);

$clauses = [];

if ($searchQ !== '') {
    $like = "%" . $conn->real_escape_string($searchQ) . "%";
    $qId  = ctype_digit($searchQ) ? (int)$searchQ : 0;
    $idClause = $qId > 0 ? " OR g.id = {$qId}" : "";

    $clauses[] = "(g.first_name LIKE '{$like}'
                   OR g.last_name LIKE '{$like}'
                   OR g.extern_key LIKE '{$like}'
                   OR g.email LIKE '{$like}'
                   {$idClause})";
}

if ($filterFirst !== '') {
    $like = "%" . $conn->real_escape_string($filterFirst) . "%";
    $clauses[] = "g.first_name LIKE '{$like}'";
}
if ($filterLast !== '') {
    $like = "%" . $conn->real_escape_string($filterLast) . "%";
    $clauses[] = "g.last_name LIKE '{$like}'";
}
if ($filterExtKey !== '') {
    $like = "%" . $conn->real_escape_string($filterExtKey) . "%";
    $clauses[] = "g.extern_key LIKE '{$like}'";
}
if ($filterEmail !== '') {
    $like = "%" . $conn->real_escape_string($filterEmail) . "%";
    $clauses[] = "g.email LIKE '{$like}'";
}

$whereSql = empty($clauses) ? "" : "WHERE " . implode(" AND ", $clauses);

$limit = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$countSql = "SELECT COUNT(*) AS total FROM LEGAL_GUARDIAN_TAB g {$whereSql}";
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
   MAIN SELECT
   ============================================================ */
$selectSql = "
    SELECT
        g.id,
        g.first_name,
        g.last_name,
        g.extern_key,
        g.email,
        g.phone,
        g.mobile
    FROM LEGAL_GUARDIAN_TAB g
    {$whereSql}
    ORDER BY {$orderBySql}
    LIMIT {$limit} OFFSET {$offset}
";
$result = $conn->query($selectSql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Legal Guardians</title>

    <link rel="stylesheet" href="student_state_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700&family=Roboto:wght@400;500&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css">

    <style>
    body {
        font-family: 'Roboto', sans-serif;
        margin: 0;
    }

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
    .student-table tr:hover {
        background-color: #FAE4D5;
        transition: 0.2s;
    }

    .material-icons-outlined {
        font-size: 24px;
        vertical-align: middle;
        cursor: pointer;
    }

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

    .edit-row { display: none; }
    .edit-row.is-open { display: table-row; }
    .edit-row td {
        background: #FFF8EB;
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
    .edit-row form {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
        justify-content: center;
    }

    </style>
</head>
<body>

<?php require __DIR__ . '/navigator.php'; ?>

<div id="content">
    <h1 class="page-title">LEGAL GUARDIANS</h1>
    <?= $alert ?>

    <?php require __DIR__ . '/filters.php'; ?>

    <div class="table-wrapper">
        <table class="student-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Extern Key</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Mobile</th>
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
                    $gid = (int)$row['id'];

                    echo "<tr id='row-{$gid}' class='{$rowClass}'>";
                    echo "<td>" . htmlspecialchars($gid) . "</td>";
                    echo "<td>" . htmlspecialchars($row['first_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['last_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['extern_key'] ?? '') . "</td>";
                    echo "<td>" . htmlspecialchars($row['email'] ?? '') . "</td>";
                    echo "<td>" . htmlspecialchars($row['phone'] ?? '') . "</td>";
                    echo "<td>" . htmlspecialchars($row['mobile'] ?? '') . "</td>";

                    if ($isAdmin) {
                        echo "<td style='text-align:center;'>
                                <span class='material-icons-outlined' style='color:#D4463B;cursor:pointer;'
                                      onclick='toggleEdit(\"{$gid}\")' title='Edit'>edit</span>
                                &nbsp;
                                <a href='?" . htmlspecialchars(http_build_query(array_merge($paginationBase, ['delete_id' => $gid]))) . "'
                                   onclick='return confirm(\"Delete this guardian and all student links?\");'
                                   title='Delete'>
                                    <span class='material-icons-outlined' style='color:#B31E32;'>delete</span>
                                </a>
                              </td>";
                    }
                    echo "</tr>";

                    if ($isAdmin) {
                        echo "<tr class='edit-row' id='edit-{$gid}'>
                                <td colspan='8'>
                                    <form method='POST'>
                                        <input type='hidden' name='guardian_id' value='{$gid}'>
                                        <input type='hidden' name='update_guardian' value='1'>

                                        <label>First Name:</label>
                                        <input type='text' name='first_name' value='" . htmlspecialchars($row['first_name'], ENT_QUOTES, 'UTF-8') . "' style='width:120px;'>

                                        <label>Last Name:</label>
                                        <input type='text' name='last_name' value='" . htmlspecialchars($row['last_name'], ENT_QUOTES, 'UTF-8') . "' style='width:120px;'>

                                        <label>Email:</label>
                                        <input type='email' name='email' value='" . htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8') . "' style='width:180px;'>

                                        <label>Phone:</label>
                                        <input type='text' name='phone' value='" . htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8') . "' style='width:120px;'>

                                        <label>Mobile:</label>
                                        <input type='text' name='mobile' value='" . htmlspecialchars($row['mobile'] ?? '', ENT_QUOTES, 'UTF-8') . "' style='width:120px;'>

                                        <label>Extern Key:</label>
                                        <input type='text' name='extern_key' value='" . htmlspecialchars($row['extern_key'] ?? '', ENT_QUOTES, 'UTF-8') . "' style='width:120px;'>

                                        <button type='submit'>Save</button>
                                        <button type='button' onclick='toggleEdit(\"{$gid}\")'>Cancel</button>
                                    </form>
                                </td>
                              </tr>";
                    }
                }
            } else {
                $colspan = $isAdmin ? 8 : 7;
                echo "<tr><td colspan='{$colspan}' style='text-align:center;color:#888;'>No guardians found</td></tr>";
            }
            ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav aria-label="Guardian pagination" style="margin-top:20px;">
        <ul class="pagination justify-content-center">
            <?php
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

<script src="https://code.jquery.com/jquery-3.2.1.slim.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"></script>

<script>
function toggleEdit(id) {
    const row = document.getElementById("edit-" + id);
    if (!row) return;
    document.querySelectorAll(".edit-row.is-open").forEach(r => {
        if (r !== row) r.classList.remove("is-open");
    });
    row.classList.toggle("is-open");
}
</script>
</body>
</html>
