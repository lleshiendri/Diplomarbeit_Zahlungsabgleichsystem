<?php
if (defined('APP_FILTERS_RENDERED')) {
    return;
}
define('APP_FILTERS_RENDERED', true);

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . "/db_connect.php";
}

/* Welche Seite? */
$currentPage = basename($_SERVER['PHP_SELF']);

/* =========================
   TRANSACTIONS FILTER KEYS
   ========================= */
$g_student = trim($_GET['student'] ?? '');
$g_class   = trim($_GET['class']   ?? '');
$g_status  = trim($_GET['status']  ?? '');
$g_from    = trim($_GET['from']    ?? '');
$g_to      = trim($_GET['to']      ?? '');
$g_min     = trim($_GET['amount_min'] ?? '');
$g_max     = trim($_GET['amount_max'] ?? '');
$g_q       = trim($_GET['q'] ?? '');
$g_sort    = trim($_GET['sort'] ?? '');
$g_dir     = trim($_GET['dir'] ?? '');

/* =========================
   UNCONFIRMED FILTER KEYS
   ========================= */
$u_beneficiary     = trim($_GET['beneficiary'] ?? '');
$u_reference_number= trim($_GET['reference_number'] ?? '');
$u_text            = trim($_GET['q'] ?? ''); // Textsuche in reference + description
$u_from            = trim($_GET['from'] ?? '');
$u_to              = trim($_GET['to'] ?? '');
$u_min             = trim($_GET['amount_min'] ?? '');
$u_max             = trim($_GET['amount_max'] ?? '');

/* =========================
   MATCHING HISTORY FILTER KEYS
   ========================= */
$mh_student = trim($_GET['student'] ?? '');
$mh_ref     = trim($_GET['reference_number'] ?? '');
$mh_from    = trim($_GET['from'] ?? '');
$mh_to      = trim($_GET['to'] ?? '');
$mh_q       = trim($_GET['q'] ?? '');
$mh_sort    = trim($_GET['sort'] ?? '');
$mh_dir     = trim($_GET['dir'] ?? '');
$mh_conf_min = trim($_GET['confidence_min'] ?? '');
$mh_conf_max = trim($_GET['confidence_max'] ?? '');
$mh_matched_by = trim($_GET['matched_by'] ?? '');

/* =========================
   LEGAL GUARDIAN FILTER KEYS
   ========================= */
$lg_q          = trim($_GET['q'] ?? '');
$lg_first_name = trim($_GET['first_name'] ?? '');
$lg_last_name  = trim($_GET['last_name'] ?? '');
$lg_extern_key = trim($_GET['extern_key'] ?? '');
$lg_email      = trim($_GET['email'] ?? '');
$lg_sort       = trim($_GET['sort'] ?? '');
$lg_dir        = trim($_GET['dir'] ?? '');

$filter_used = isset($_GET['applied']);
?>

<style>
 :root{
  --red-dark:#B31E32;
  --red-main:#D4463B;
  --gray-light:#D9D9D9;
  --bg:#FFF8EB;
  --text:#333;
}

.filter-panel {
  position: fixed;
  top: 70px;
  right: 0;
  width: 340px;
  height: calc(100% - 70px);
  background: var(--bg);
  border-left: 1px solid var(--gray-light);
  padding: 28px;
  transform: translateX(100%);
  transition: 0.3s ease;
  z-index: 1500;
  box-shadow: -4px 0 10px rgba(0,0,0,.07);
}

.filter-panel.open { transform: translateX(0); }

/* OVERLAY */
.overlay.filter-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.25);
  opacity: 0;
  pointer-events: none;
  transition: opacity .3s;
  z-index: 1400;
}
.overlay.filter-overlay.show {
  opacity: 1;
  pointer-events: auto;
}

/* FORM */
.filter-form {
  display: flex;
  flex-direction: column;
  gap: 22px;
}

.filter-group label {
  font-family: 'Montserrat', sans-serif;
  font-weight: 600;
  font-size: 14px;
  color: var(--red-dark);
  margin-bottom: 6px;
}

.filter-input,
.filter-select {
  padding: 10px 12px;
  border-radius: 8px;
  border: 1.5px solid var(--gray-light);
  background: white;
  width: 100%;
  font-size: 14px;
  transition: .2s;
}

.filter-input:focus,
.filter-select:focus {
  border-color: var(--red-main);
  outline: none;
  box-shadow: 0 0 0 2px rgba(212,70,59,.18);
}

/* DATE */
.date-group {
  display: flex;
  gap: 8px;
}

/* AMOUNT NEW LAYOUT */
.amount-flex {
  display: flex;
  gap: 10px;
  align-items: center;
}

/* BUTTON */
.filter-button {
  background: var(--red-main);
  border: none;
  padding: 11px 16px;
  color: white;
  border-radius: 8px;
  cursor: pointer;
  font-family: 'Montserrat', sans-serif;
  font-weight: 600;
  font-size: 14px;
  transition: .2s;
  width: 100%;
}

.filter-button:hover { background: var(--red-dark); }
</style>

<!-- PANEL HTML -->
<div id="filterPanel" class="filter-panel">
  <form class="filter-form" method="GET">
    <input type="hidden" name="applied" value="1">

    <?php if ($currentPage === 'matching_history.php'): ?>

      <!-- MATCHING HISTORY FILTERS -->
      <div class="filter-group">
        <label>Search</label>
        <input type="text" name="q" class="filter-input"
               placeholder="Invoice ref, beneficiary, student, matched_by..."
               value="<?= htmlspecialchars($mh_q, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Sort By</label>
        <select name="sort" class="filter-select">
          <option value="created_at" <?= ($mh_sort === 'created_at' || $mh_sort === '') ? 'selected' : '' ?>>Created</option>
          <option value="processing_date" <?= $mh_sort === 'processing_date' ? 'selected' : '' ?>>Processing Date</option>
          <option value="confidence_score" <?= $mh_sort === 'confidence_score' ? 'selected' : '' ?>>Confidence</option>
          <option value="amount_total" <?= $mh_sort === 'amount_total' ? 'selected' : '' ?>>Amount</option>
          <option value="student_name" <?= $mh_sort === 'student_name' ? 'selected' : '' ?>>Student</option>
          <option value="matched_by" <?= $mh_sort === 'matched_by' ? 'selected' : '' ?>>Matched By</option>
          <option value="invoice_ref" <?= $mh_sort === 'invoice_ref' ? 'selected' : '' ?>>Invoice Ref</option>
          <option value="history_id" <?= $mh_sort === 'history_id' ? 'selected' : '' ?>>History ID</option>
        </select>
      </div>

      <div class="filter-group">
        <label>Order</label>
        <select name="dir" class="filter-select">
          <option value="desc" <?= ($mh_dir === 'desc' || $mh_dir === '') ? 'selected' : '' ?>>Descending</option>
          <option value="asc" <?= $mh_dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
        </select>
      </div>

      <div class="filter-group">
        <label>Student</label>
        <input type="text" name="student" class="filter-input"
               value="<?= htmlspecialchars($mh_student, ENT_QUOTES, 'UTF-8'); ?>">
      </div>
      <div class="filter-group">
        <label>Reference Number</label>
        <input type="text" name="reference_number" class="filter-input"
               value="<?= htmlspecialchars($mh_ref, ENT_QUOTES, 'UTF-8'); ?>">
      </div>
      <div class="filter-group">
        <label>Date Range (created_at)</label>
        <div class="date-group">
          <input type="date" name="from" class="filter-input" value="<?= htmlspecialchars($mh_from, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="date" name="to"   class="filter-input" value="<?= htmlspecialchars($mh_to, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
      </div>

      <div class="filter-group">
        <label>Confidence Range (%)</label>
        <div class="amount-flex">
          <input type="number" name="confidence_min" step="0.01" class="filter-input" placeholder="Min"
                 value="<?= htmlspecialchars($mh_conf_min, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="number" name="confidence_max" step="0.01" class="filter-input" placeholder="Max"
                 value="<?= htmlspecialchars($mh_conf_max, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
      </div>

      <div class="filter-group">
        <label>Matched By Method</label>
        <input type="text" name="matched_by" class="filter-input"
               placeholder="e.g. reference, manual, fallback"
               value="<?= htmlspecialchars($mh_matched_by, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

    <?php elseif ($currentPage === 'unconfirmed.php'): ?>

      <!-- UNCONFIRMED FILTERS -->
      <div class="filter-group">
        <label>Ordering Name</label>
        <input type="text" name="beneficiary" class="filter-input"
               value="<?= htmlspecialchars($u_beneficiary, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Reference Number</label>
        <input type="text" name="reference_number" class="filter-input"
               value="<?= htmlspecialchars($u_reference_number, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Search</label>
        <input type="text" name="q" class="filter-input"
               placeholder="Reference or Description…"
               value="<?= htmlspecialchars($u_text, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Date Range</label>
        <div class="date-group">
          <input type="date" name="from" class="filter-input" value="<?= htmlspecialchars($u_from, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="date" name="to"   class="filter-input" value="<?= htmlspecialchars($u_to, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
      </div>

      <div class="filter-group">
        <label>Amount Range (<?= CURRENCY ?>)</label>
        <div class="amount-flex">
          <input type="number" name="amount_min" class="filter-input" placeholder="Min"
                 value="<?= htmlspecialchars($u_min, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="number" name="amount_max" class="filter-input" placeholder="Max"
                 value="<?= htmlspecialchars($u_max, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
      </div>

    <?php elseif ($currentPage === 'legal_guardians.php'): ?>

      <!-- LEGAL GUARDIAN FILTERS -->
      <div class="filter-group">
        <label>Search</label>
        <input type="text" name="q" class="filter-input"
               placeholder="Name, extern key, email, ID..."
               value="<?= htmlspecialchars($lg_q, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Sort By</label>
        <select name="sort" class="filter-select">
          <option value="id" <?= ($lg_sort === 'id' || $lg_sort === '') ? 'selected' : '' ?>>ID</option>
          <option value="first_name" <?= $lg_sort === 'first_name' ? 'selected' : '' ?>>First Name</option>
          <option value="last_name" <?= $lg_sort === 'last_name' ? 'selected' : '' ?>>Last Name</option>
          <option value="extern_key" <?= $lg_sort === 'extern_key' ? 'selected' : '' ?>>Extern Key</option>
          <option value="email" <?= $lg_sort === 'email' ? 'selected' : '' ?>>Email</option>
        </select>
      </div>

      <div class="filter-group">
        <label>Order</label>
        <select name="dir" class="filter-select">
          <option value="asc" <?= ($lg_dir === 'asc' || $lg_dir === '') ? 'selected' : '' ?>>Ascending</option>
          <option value="desc" <?= $lg_dir === 'desc' ? 'selected' : '' ?>>Descending</option>
        </select>
      </div>

      <div class="filter-group">
        <label>First Name</label>
        <input type="text" name="first_name" class="filter-input"
               value="<?= htmlspecialchars($lg_first_name, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Last Name</label>
        <input type="text" name="last_name" class="filter-input"
               value="<?= htmlspecialchars($lg_last_name, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Extern Key</label>
        <input type="text" name="extern_key" class="filter-input"
               value="<?= htmlspecialchars($lg_extern_key, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Email</label>
        <input type="text" name="email" class="filter-input"
               value="<?= htmlspecialchars($lg_email, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

    <?php else: ?>

      <!-- TRANSACTIONS FILTERS (UNCHANGED) -->
      <div class="filter-group">
        <label>Search</label>
        <input type="text" name="q" class="filter-input"
               placeholder="Student, reference, description, ID..."
               value="<?= htmlspecialchars($g_q, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Sort By</label>
        <?php if ($currentPage === 'student_state.php'): ?>
          <select name="sort" class="filter-select">
            <option value="id" <?= ($g_sort === 'id' || $g_sort === '') ? 'selected' : '' ?>>Student ID</option>
            <option value="student_name" <?= $g_sort === 'student_name' ? 'selected' : '' ?>>Student Name</option>
            <option value="last_transaction_date" <?= $g_sort === 'last_transaction_date' ? 'selected' : '' ?>>Last Transaction</option>
            <option value="left_to_pay" <?= $g_sort === 'left_to_pay' ? 'selected' : '' ?>>Left to Pay</option>
            <option value="amount_paid" <?= $g_sort === 'amount_paid' ? 'selected' : '' ?>>Amount Paid</option>
            <option value="additional_payments_status" <?= $g_sort === 'additional_payments_status' ? 'selected' : '' ?>>Additional Status</option>
          </select>
        <?php else: ?>
          <select name="sort" class="filter-select">
            <option value="processing_date" <?= ($g_sort === 'processing_date' || $g_sort === '') ? 'selected' : '' ?>>Processing Date</option>
            <option value="amount_total" <?= $g_sort === 'amount_total' ? 'selected' : '' ?>>Amount</option>
            <option value="reference_number" <?= $g_sort === 'reference_number' ? 'selected' : '' ?>>Reference Nr</option>
            <option value="beneficiary" <?= $g_sort === 'beneficiary' ? 'selected' : '' ?>>Beneficiary</option>
            <option value="id" <?= $g_sort === 'id' ? 'selected' : '' ?>>ID</option>
          </select>
        <?php endif; ?>
      </div>

      <div class="filter-group">
        <label>Order</label>
        <select name="dir" class="filter-select">
          <option value="desc" <?= ($g_dir === 'desc' || $g_dir === '') ? 'selected' : '' ?>>Descending</option>
          <option value="asc" <?= $g_dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
        </select>
      </div>

      <div class="filter-group">
        <label>Student</label>
        <input type="text" name="student" class="filter-input"
               value="<?= htmlspecialchars($g_student, ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <div class="filter-group">
        <label>Class</label>
        <select name="class" class="filter-select">
          <option value="">All classes</option>
          <?php
          $classes = $conn->query("SELECT id,name FROM CLASS_TAB ORDER BY name");
          if ($classes) {
            while ($c = $classes->fetch_assoc()):
              $sel = ($g_class == $c['id']) ? "selected" : "";
              $id  = (int)$c['id'];
              $nm  = htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8');
              echo "<option value='{$id}' {$sel}>{$nm}</option>";
            endwhile;
          }
          ?>
        </select>
      </div>

      <div class="filter-group">
        <label>Status</label>
        <select name="status" class="filter-select">
          <option value="">All statuses</option>
          <option value="paid"    <?= $g_status=='paid'?'selected':'' ?>>Paid</option>
          <option value="open"    <?= $g_status=='open'?'selected':'' ?>>Open</option>
          <option value="overdue" <?= $g_status=='overdue'?'selected':'' ?>>Overdue</option>
          <option value="partial" <?= $g_status=='partial'?'selected':'' ?>>Partial payment</option>
        </select>
      </div>

      <div class="filter-group">
        <label>Date Range</label>
        <div class="date-group">
          <input type="date" name="from" class="filter-input" value="<?= htmlspecialchars($g_from, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="date" name="to"   class="filter-input" value="<?= htmlspecialchars($g_to, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
      </div>

      <div class="filter-group">
        <label>Amount Range (<?= CURRENCY ?>)</label>
        <div class="amount-flex">
          <input type="number" name="amount_min" class="filter-input" placeholder="Min"
                 value="<?= htmlspecialchars($g_min, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="number" name="amount_max" class="filter-input" placeholder="Max"
                 value="<?= htmlspecialchars($g_max, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
      </div>

    <?php endif; ?>

    <!-- Preserve sort/search state from page-level controls -->
    <input type="hidden" name="page" value="1">

    <button type="submit" class="filter-button">Apply</button>
  </form>
</div>

<div class="overlay filter-overlay" id="filterOverlay"></div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const panel = document.getElementById("filterPanel");
  const overlay = document.getElementById("filterOverlay");
  if (!panel || !overlay) return;

  const localClose = () => {
    panel.classList.remove("open");
    overlay.classList.remove("show");
    document.body.classList.remove("filter-open", "modal-open");
  };

  const closeFilterSafely = () => {
    if (typeof window.closeFilter === "function") {
      window.closeFilter();
      return;
    }
    localClose();
  };

  /* click outside closes panel */
  overlay.addEventListener("click", () => {
    closeFilterSafely();
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && panel.classList.contains("open")) {
      closeFilterSafely();
    }
  });
});
</script>
