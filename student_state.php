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

    // #region agent log
    $logPath = __DIR__ . '/.cursor/debug.log';
    $logEntry = [
        'id'           => 'log_' . uniqid(),
        'timestamp'    => round(microtime(true) * 1000),
        'location'     => 'student_state.php:delete_enter',
        'message'      => 'Delete request received',
        'runId'        => 'pre-fix',
        'hypothesisId' => 'H1',
        'data'         => [
            'raw_get'   => $_GET,
            'delete_id' => $delete_id,
        ],
    ];
    @file_put_contents($logPath, json_encode($logEntry) . PHP_EOL, FILE_APPEND);
    // #endregion

    // Lookup target row by extern_key (for debugging)
    $rowInfo = null;
    if ($stmtCheck = $conn->prepare("SELECT id, extern_key FROM STUDENT_TAB WHERE extern_key = ? LIMIT 1")) {
        $stmtCheck->bind_param("s", $delete_id);
        $stmtCheck->execute();
        $resCheck = $stmtCheck->get_result();
        $rowInfo = $resCheck ? $resCheck->fetch_assoc() : null;
        $stmtCheck->close();
    }

    $stmt = $conn->prepare("DELETE FROM STUDENT_TAB WHERE extern_key = ?");
    if ($stmt) {
        $stmt->bind_param("s", $delete_id);
        $ok       = $stmt->execute();
        $affected = $stmt->affected_rows;
        $error    = $stmt->error;
        $errno    = $stmt->errno;
        $stmt->close();

        // #region agent log
        $logEntry = [
            'id'           => 'log_' . uniqid(),
            'timestamp'    => round(microtime(true) * 1000),
            'location'     => 'student_state.php:delete_execute',
            'message'      => 'Delete executed',
            'runId'        => 'pre-fix',
            'hypothesisId' => 'H2',
            'data'         => [
                'delete_id'  => $delete_id,
                'rowInfo'    => $rowInfo,
                'ok'         => $ok,
                'affected'   => $affected,
                'errno'      => $errno,
                'error'      => $error,
                'conn_error' => $conn->error,
            ],
        ];
        @file_put_contents($logPath, json_encode($logEntry) . PHP_EOL, FILE_APPEND);
        // #endregion

        // Temporary strict error handling during debugging
        if (!$ok) {
            die("Delete failed: " . htmlspecialchars($error ?: $conn->error));
        }

        $alert = "<div class='alert alert-success'>✅ Student successfully deleted.</div>";
    } else {
        $alert = "<div class='alert alert-error'>❌ Delete failed.</div>";
    }
}

// ============================================================
// EMAIL SEND ALERT (from send_student_pdf_email.php)
// ============================================================
if (isset($_GET['email_ok'])) {
    $emailOk = (int)($_GET['email_ok'] ?? 0) === 1;
    if ($emailOk) {
        $sentCount = isset($_GET['email_sent']) ? (int)$_GET['email_sent'] : 0;
        $text = "✅ Email sent successfully.";
        if ($sentCount > 0) {
            $text .= " Recipients: " . $sentCount;
        }
        $msg = "<div class='alert alert-success'>{$text}</div>";
    } else {
        $errorMsg = isset($_GET['email_error']) ? htmlspecialchars($_GET['email_error'], ENT_QUOTES, 'UTF-8') : 'Email sending failed.';
        $msg = "<div class='alert alert-error'>❌ Email failed: {$errorMsg}</div>";
    }
    if ($alert !== "") {
        $alert .= "<br>" . $msg;
    } else {
        $alert = $msg;
    }
}

// ============================================================
// PDF GENERATION ALERT (from pdf_creator ui=1 flow)
// ============================================================
if (isset($_GET['pdf_ok'])) {
    $pdfOk = (int)$_GET['pdf_ok'] === 1;
    if ($pdfOk) {
        $msg = "<div class='alert alert-success'>PDF successfully generated.</div>";
    } else {
        $errorMsg = isset($_GET['pdf_error']) ? htmlspecialchars($_GET['pdf_error'], ENT_QUOTES, 'UTF-8') : 'PDF generation failed.';
        $msg = "<div class='alert alert-error'>PDF error: {$errorMsg}</div>";
    }
    if ($alert !== "") {
        $alert .= "<br>" . $msg;
    } else {
        $alert = $msg;
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
    $additional_payments_status = isset($_POST['additional_payments_status']) ? (float)$_POST['additional_payments_status'] : 0;

    // 1) Versuch: über extern_key updaten
    $stmt = $conn->prepare("
        UPDATE STUDENT_TAB 
        SET name = ?, long_name = ?, left_to_pay = ?, additional_payments_status = ?
        WHERE extern_key = ?
    ");

    if ($stmt) {
        $stmt->bind_param("ssdds", $name, $long_name, $left_to_pay, $additional_payments_status, $extern_key);
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
            SET name = ?, long_name = ?, left_to_pay = ?, additional_payments_status = ?
            WHERE id = ?
        ");

        if ($stmt2) {
            $stmt2->bind_param("ssddi", $name, $long_name, $left_to_pay, $additional_payments_status, $student_id);
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

$returnQuery = http_build_query(array_merge($paginationBase, ['page' => $page]));
$returnParam = $returnQuery !== '' ? rawurlencode($returnQuery) : '';

/* ============================================================
   HAUPT-SELECT (GEFILTERT!)
   ============================================================ */
$selectSql = "
    SELECT
        s.id AS student_id,
        s.extern_key AS extern_key,
        s.long_name AS student_name,
        s.name,
        s.amount_paid,
        s.left_to_pay,
        s.additional_payments_status,
        MAX(i.processing_date) AS last_transaction_date 
    FROM STUDENT_TAB s
    LEFT JOIN INVOICE_TAB i ON i.student_id = s.id 
    {$joinSql}
    {$whereSql}
    GROUP BY s.id, s.extern_key, s.long_name, s.name, s.left_to_pay, s.amount_paid, s.additional_payments_status
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

</head>
<body>
<?php require __DIR__ . '/navigator.php'; ?>

<div id="overlay"></div>

<div class="content">
    <h1 class="page-title">STUDENT STATE</h1>
    <?= $alert ?>
    <div id="reference-email-result" class="alert" style="display:none;"></div>

    <?php require __DIR__ . '/filters.php'; ?>

    <div class="table-wrapper">
        <table class="student-table">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Amount Paid</th>
                    <th>Left to Pay</th>
                    <th>Last Transaction</th>
                    <th>Additional Payment Status</th>
                    <?php if ($isAdmin): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {

                    // 📌 Hier sind deine Mock-Werte – kannst du später durch echte DB-Werte ersetzen
                    $amountPaid  = $row['amount_paid'];
                    $leftToPay   = $row['left_to_pay'];
                    $lastDate = $row['last_transaction_date'] ? date('d/m/Y', strtotime($row['last_transaction_date'])) : '-';

                    $studentId   = $row['student_id'];
                    $externKey   = $row['extern_key'];
                    $studentName = $row['student_name'];

                    echo '<tr id="row-'.$studentId.'">';
                    echo '<td>' . htmlspecialchars($studentId) . '</td>';
                    echo '<td>' . htmlspecialchars($studentName) . '</td>';
                    echo '<td>' . number_format($amountPaid, 2, ',', '.') . ' Lek' . '</td>';
                    echo '<td>' . number_format($row['left_to_pay'], 2, ',', '.') . ' Lek' . '</td>';
                    echo '<td>' . htmlspecialchars($lastDate) . '</td>';
                    echo '<td>';
                    echo isset($row['additional_payments_status'])
                        ? number_format((float)$row['additional_payments_status'], 2, ',', '.') . " Lek"
                        : "";
                    echo '</td>';
                    if ($isAdmin) {
                    // Actions
                    echo '<td>
                            <div class="actions-cell">
                                <span class="material-icons-outlined action-icon"
                                      onclick="toggleEdit(\''.$studentId.'\')"
                                      title="Edit student">
                                    edit
                                </span>

                                <a class="action-icon delete"
                                   href="?'.htmlspecialchars(http_build_query(array_merge($paginationBase, ['delete_id' => $externKey]))).'"
                                   onclick="return confirm(\'Are you sure you want to delete this student?\');"
                                   title="Delete student">
                                    <span class="material-icons-outlined">delete</span>
                                </a>

                                <a class="action-icon"
                                   href="pdf_creator.php?student_id='.htmlspecialchars($studentId).'&ui=1"
                                   title="Open PDF report">
                                    <span class="material-icons-outlined">picture_as_pdf</span>
                                </a>

                                <!-- Send full PDF report via email -->
                                <a class="action-chip action-chip-email"
                                   href="send_student_pdf_email.php?student_id='.htmlspecialchars($studentId).'&return='.htmlspecialchars($returnParam, ENT_QUOTES, 'UTF-8').'"
                                   title="Send PDF report via email">
                                    <span class="material-icons-outlined">attach_email</span>
                                </a>

                                <!-- Send only reference ID via email -->
                                <span class="action-chip action-chip-id js-send-ref-email"
                                      data-student-id="'.(int)$studentId.'"
                                      title="Send Reference ID via email"
                                      role="button">
                                    <span class="material-icons-outlined">contact_mail</span>
                                </span>
                            </div>
                          </td>';
                    echo '</tr>';

                    // Inline-Edit-Zeile
                    echo '<tr class="edit-row" id="edit-'.$studentId.'">
                            <td colspan="8">
                                <form method="POST">
                                    <input type="hidden" name="extern_key" value="'.htmlspecialchars($externKey).'">
                                    <input type="hidden" name="student_id" value="'.htmlspecialchars($studentId).'">
                                    <input type="hidden" name="update_student" value="1">

                                    <label>Name:</label>
                                    <input type="text" name="name" value="'.htmlspecialchars($row['name']).'" required style="width:120px;">

                                    <label>Long Name:</label>
                                    <input type="text" name="long_name" value="'.htmlspecialchars($studentName).'" style="width:150px;">

                                    <label>Left to Pay (<?= CURRENCY ?>):</label>
                                    <input type="number" step="0.01" name="left_to_pay" value="'.htmlspecialchars($row['left_to_pay']).'" style="width:100px;">

                                    <label>Additional Payments Status (<?= CURRENCY ?>):</label>
                                    <input type="number" step="0.01" name="additional_payments_status" value="'.htmlspecialchars($row['additional_payments_status'] ?? '0', ENT_QUOTES, 'UTF-8').'" style="width:150px;" required>

                                    <button type="submit">Save</button>
                                    <button type="button" onclick="toggleEdit(\''.$studentId.'\')">Cancel</button>
                                </form>
                            </td>
                          </tr>';
                    }
                }
            } else {
                echo '<tr><td colspan="8">No students found</td></tr>';
            }
            ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav aria-label="Student pagination">
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

  // Optional: close all other open edit rows (clean UX)
  document.querySelectorAll(".edit-row.is-open").forEach(r => {
    if (r !== row) r.classList.remove("is-open");
  });

  row.classList.toggle("is-open");
}

function sendReferenceEmail(studentId) {
  var sid = parseInt(studentId, 10);
  if (!sid || sid <= 0) {
    alert("Invalid student.");
    return;
  }

  var resultEl = document.getElementById("reference-email-result");
  if (!resultEl) return;

  var buttons = document.querySelectorAll(".js-send-ref-email[data-student-id=\"" + sid + "\"]");
  buttons.forEach(function(btn) {
    btn.style.pointerEvents = "none";
    btn.style.opacity = "0.5";
  });

  resultEl.style.display = "none";
  resultEl.className = "alert";

  var formData = new FormData();
  formData.append("student_id", sid);

  fetch("send_email_reference.php", {
    method: "POST",
    body: formData
  })
  .then(function(res) { return res.json(); })
  .then(function(data) {
    var sent = data.sent_to || [];
    var errs = data.errors || [];
    var success = data.success === true;

    if (success && sent.length > 0) {
      resultEl.className = "alert alert-success";
      resultEl.innerHTML = "Reference ID email sent to: " + sent.join(", ");
    } else if (sent.length > 0 && errs.length > 0) {
      resultEl.className = "alert alert-error";
      resultEl.innerHTML = "Partially sent to: " + sent.join(", ") + ". Failures: " + errs.join("; ");
    } else if (errs.length > 0) {
      resultEl.className = "alert alert-error";
      resultEl.innerHTML = errs.join("<br>");
    } else {
      resultEl.className = "alert alert-error";
      resultEl.innerHTML = "No valid email address found for this student or their legal guardians.";
    }
    resultEl.style.display = "block";
  })
  .catch(function() {
    resultEl.className = "alert alert-error";
    resultEl.innerHTML = "Request failed. Please try again.";
    resultEl.style.display = "block";
  })
  .finally(function() {
    buttons.forEach(function(btn) {
      btn.style.pointerEvents = "";
      btn.style.opacity = "";
    });
  });
}

document.addEventListener("DOMContentLoaded", function() {
  document.querySelectorAll(".js-send-ref-email").forEach(function(btn) {
    btn.addEventListener("click", function() {
      var sid = this.getAttribute("data-student-id");
      sendReferenceEmail(sid);
    });
  });
});
</script>

<script>
// Auto-open generated PDF in a new tab when coming back from pdf_creator ui=1
(function() {
    const url = new URL(window.location.href);
    const pdfOk = url.searchParams.get('pdf_ok');
    const pdfFile = url.searchParams.get('pdf_file');
    if (pdfOk === '1' && pdfFile) {
        window.open(pdfFile, '_blank');
        // Clean pdf_* params from URL so reload doesn't reopen
        url.searchParams.delete('pdf_ok');
        url.searchParams.delete('pdf_file');
        url.searchParams.delete('pdf_error');
        url.searchParams.delete('pdf_student_id');
        window.history.replaceState({}, document.title, url.pathname + (url.search ? '?' + url.searchParams.toString() : ''));
    }
})();
</script>

</body>
</html>
