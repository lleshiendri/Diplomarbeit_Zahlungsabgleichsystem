<?php
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

    <?php if ($currentPage === 'unconfirmed.php'): ?>

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

    <?php else: ?>

      <!-- TRANSACTIONS FILTERS (UNCHANGED) -->
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

    <button type="submit" class="filter-button">Apply</button>
  </form>
</div>

<div class="overlay filter-overlay" id="filterOverlay"></div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const panel = document.getElementById("filterPanel");
  const overlay = document.getElementById("filterOverlay");

  const togglePanel = (e) => {
    if (e) e.preventDefault();
    panel.classList.toggle("open");
    overlay.classList.toggle("show");
  };

  /* UNIVERSAL CLICK TRIGGER */
  document.addEventListener("click", (e) => {
    const t = e.target;
    if (
      t.closest("#filterToggle") ||
      t.closest("[data-filter-toggle]") ||
      t.closest(".js-filter-toggle") ||
      (t.tagName === "I" && t.textContent.trim() === "tune")
    ) {
      togglePanel(e);
    }
  });

  /* click outside closes panel */
  overlay.addEventListener("click", () => {
    panel.classList.remove("open");
    overlay.classList.remove("show");
  });
});
</script>
