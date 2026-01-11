<?php
const CURRENCY = 'Lek';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userRole = $_SESSION['role'] ?? 'Reader';
$isAdmin = ($userRole === 'Admin');

$currentPage = basename($_SERVER['PHP_SELF']);

if ($currentPage === 'login.php') {
    return;
}

$shouldEnforceAuth = !defined('NAV_STANDALONE') || !NAV_STANDALONE;
if ($shouldEnforceAuth && !defined('NAV_SKIP_AUTH') && !isset($_SESSION['user_id'])) {
    header('Location: login/login.php');
    exit;
}

require "db_connect.php"; // needed for unread counter

// Unread notifications count
$unreadCount = 0;
$res = $conn->query("SELECT COUNT(*) AS c FROM NOTIFICATION WHERE is_read = 0");
if ($row = $res->fetch_assoc()) {
    $unreadCount = (int)$row['c'];
}

// --- School year info (current school year) ---
$schoolYearLabel  = '–';
$schoolYearAmount = null;
$schoolYearId     = null;

$month = (int)date('n');
$year  = (int)date('Y');


// If before September → belong to previous school year
if ($month < 9) {
    $schoolYearStart = $year - 1;
} else {
    $schoolYearStart = $year;
}

// assumes values like "2024/2025" or "2024-2025"
$schoolYearPattern = $schoolYearStart . '%';

$syRes = $conn->query("
    SELECT id, schoolyear, total_amount
    FROM SCHOOLYEAR_TAB
    WHERE schoolyear LIKE '{$schoolYearPattern}'
    LIMIT 1
");

if ($syRes && $syRes->num_rows === 1) {
    $syRow = $syRes->fetch_assoc();
    $schoolYearId     = (int)$syRow['id'];
    $schoolYearLabel  = $syRow['schoolyear'];
    $schoolYearAmount = (float)$syRow['total_amount'];
}

?>
<?php if (defined('NAV_STANDALONE') && NAV_STANDALONE): ?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
<?php endif; ?>
  <style>
  :root{
    --red-dark:#B31E32;
    --red-main:#D4463B;
    --red-light:#FAE4D5;
    --off-white:#FFF8EB;
    --gray-light:#E3E5E0;
    --ink:#000;
  }

  /* HEADER */
  .header{
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 1300;
    height: 70px;
    display:flex; align-items:center; justify-content:space-between;
    padding:0 30px;
    background:var(--red-main); color:#fff;
    box-shadow:0 2px 6px rgba(0,0,0,.1);
  }
  .nav-left { display:flex; align-items:center; gap:20px; }
  .menu-icon, .nav-icon {
    font-size:26px;
    cursor:pointer;
    color:white;
    transition:.2s;
    user-select:none;
  }
  .menu-icon:hover, .nav-icon:hover { color:var(--red-dark); }
  .logo img{ height:36px; }

  /* BADGE */
  .nav-icon-wrapper {
    position: relative;
    display: inline-block;
  }
  .badge {
    position: absolute;
    top: -6px;
    right: -6px;
    background: var(--red-dark);
    color: white;
    font-size: 11px;
    font-weight: 700;
    border-radius: 50%;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border: 2px solid var(--red-main);
    display:flex; align-items:center; justify-content:center;
  }

  /* SIDEBAR */
  .sidebar {
    position: fixed;
    top: 70px;
    left: 0;
    bottom: 0;
    width: 0;
    background: var(--off-white);
    overflow-x: hidden;
    transition: width 0.3s ease;
    z-index: 1200;
    border-right: 1px solid var(--gray-light);
    box-shadow: 2px 0 8px rgba(0,0,0,.15);
  }
  .sidebar.open { width: 260px; }

  .sidebar-inner { display: flex; flex-direction: column; height: 100%; }

  .sidebar header{
    font-family:'Montserrat',sans-serif;
    font-weight:600; color:var(--red-dark);
    font-size:18px;
    padding: 10px 20px;
    display:flex; align-items:center; justify-content:space-between;
  }
  .close-btn{ font-size:22px; cursor:pointer; user-select:none; }

  .sidebar nav a, .sidebar .logout-link{
    padding:14px 20px;
    display:flex; align-items:center; gap:12px;
    text-decoration:none;
    font-size:15px; color:#333;
    border-left:3px solid transparent;
    transition:background .2s, color .2s, border-color .2s;
  }
  .sidebar nav a .material-icons-outlined,
  .sidebar .logout-link .material-icons-outlined{ font-size:20px; color:#666; }
  .sidebar nav a:hover, .sidebar .logout-link:hover{
    background:var(--red-light);
    color:var(--red-dark);
    border-left-color:var(--red-dark);
  }
  .logout-wrap { border-top: 1px solid var(--gray-light); margin-top: auto; }
  .sidebar .logout-link{ color:var(--red-main); font-weight:500; }

  /* OVERLAY */
  .overlay {
    position: fixed;
    top:0; left:0; right:0; bottom:0;
    background: rgba(0,0,0,0.3);
    display:none;
    z-index:1100;
  }
  .overlay.show { display:block; }

  /* Content shift when sidebar open */
  #content { transition: margin-left 0.3s ease; }
  #content.shifted { margin-left: 260px; }

    /* SCHOOL YEAR POPUP */
  .schoolyear-modal {
    position: fixed;
    top: 95px;          
    right: 30px;
    background: #fff;
    border: 1px solid var(--gray-light);
    border-radius: 8px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.18);
    padding: 16px 18px 14px;
    min-width: 260px;
    z-index: 1400;
    display: none;
    font-family: 'Roboto', sans-serif;
  }
  .schoolyear-modal.show {
    display: block;
  }
  .schoolyear-modal-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom: 8px;
  }
  .schoolyear-modal-title {
    font-family:'Montserrat', sans-serif;
    font-size:16px;
    font-weight:600;
    color:var(--red-dark);
  }
  .schoolyear-modal-close {
    cursor:pointer;
    font-size:20px;
    line-height:1;
    color:#666;
  }
  .schoolyear-modal p {
    margin:4px 0;
    font-size:14px;
  }
  .schoolyear-modal strong {
    font-weight:600;
  }

    .schoolyear-edit-toggle {
    margin-top: 8px;
    font-size: 12px;
    border: none;
    background: transparent;
    color: var(--red-main);
    cursor: pointer;
    padding: 0;
    text-decoration: underline;
  }

  .schoolyear-edit-form {
    margin-top: 8px;
    display: none;
  }

  .schoolyear-edit-form.show {
    display: block;
  }

  .schoolyear-edit-form label {
    display:block;
    font-size: 12px;
    margin-bottom: 4px;
  }

  .schoolyear-edit-form input[type="number"],
  .schoolyear-edit-form input[type="text"] {
    width: 100%;
    padding: 4px 6px;
    font-size: 13px;
    box-sizing: border-box;
  }

  .schoolyear-edit-form-actions {
    margin-top: 6px;
    display:flex;
    justify-content:flex-end;
    gap:6px;
  }

  .schoolyear-edit-save,
  .schoolyear-edit-cancel {
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 4px;
    border: 1px solid var(--gray-light);
    cursor: pointer;
  }

  .schoolyear-edit-save {
    background: var(--red-main);
    color: #fff;
    border-color: var(--red-main);
  }

  .schoolyear-edit-cancel {
    background: #fff;
    color: #333;
  }

  </style>
<?php if (defined('NAV_STANDALONE') && NAV_STANDALONE): ?>
</head>
<body>
<?php endif; ?>

<!-- HEADER -->
<div class="header">
  <div class="nav-left">
    <span class="menu-icon material-icons-outlined" onclick="toggleSidebar()">menu</span>

    <!-- Notifications with badge -->
    <a href="notifications.php" class="nav-icon-wrapper" style="text-decoration:none;">
      <span class="nav-icon material-icons-outlined">notifications</span>
      <?php if ($unreadCount > 0): ?>
        <span class="badge"><?= $unreadCount ?></span>
      <?php endif; ?>
    </a>

    <a href="unconfirmed.php"><span class="nav-icon material-icons-outlined">priority_high</span></a>
  <!-- School Year info -->
    <span id="schoolYearToggle" class="nav-icon-wrapper" style="cursor:pointer;" title="Schuljahr">
      <span class="nav-icon material-icons-outlined">event</span>
    </span>

    <?php if ($currentPage !== 'dashboard.php'): ?>
      <?php if (in_array($currentPage, ['unconfirmed.php','student_state.php,', 'Transactions.php'], true)): ?>
<span id="filterToggle" class="nav-icon-wrapper">
    <span class="nav-icon material-icons-outlined">filter_list</span>
</span>      <?php endif; ?>
      <a href="dashboard.php" class="nav-icon material-icons-outlined" style="text-decoration:none;color:white;">home</a>
    <?php endif; ?>
  </div>
  <div class="logo"><img src="logo1.png" alt="Logo"></div>
</div>

<!-- SIDEBAR -->
<aside id="sidebar" class="sidebar" aria-label="Seitennavigation">
  <div class="sidebar-inner">
    <header>MENU <span class="close-btn" onclick="closeSidebar()">&times;</span></header>
    <nav>
    <?php if ($isAdmin): ?> 
      <a href="add_transactions.php"><span class="material-icons-outlined">swap_horiz</span> Add Transaction</a>
      <a href="Transactions.php"><span class="material-icons-outlined">receipt_long</span> Transactions</a>
      <a href="add_students.php"><span class="material-icons-outlined">group_add</span> Add Students</a>
      <a href="import_files.php"><span class="material-icons-outlined">upload_file</span> Import File</a>
      <a href="unconfirmed.php"><span class="material-icons-outlined">priority_high</span> Unconfirmed</a>
      <?php endif; ?>

      <a href="Transactions.php"><span class="material-icons-outlined">receipt_long</span> Transactions</a>
      <a href="student_state.php"><span class="material-icons-outlined">school</span> Student State</a>
      <a href="latencies.php"><span class="material-icons-outlined">schedule</span> Latencies</a>
      <a href="notifications.php"><span class="material-icons-outlined">notifications</span> Notifications</a>
      <a href="#"><span class="material-icons-outlined">help_outline</span> Help & Tutorial</a>
    </nav>
    <div class="logout-wrap">
      <a class="logout-link" href="http://buchhaltung.htl-projekt.com/login/login.php">
        <span class="material-icons-outlined">logout</span> Logout
      </a>
    </div>
  </div>
</aside>

<!-- SCHOOL YEAR POPUP -->
<div id="schoolYearModal" class="schoolyear-modal" aria-hidden="true">
  <div class="schoolyear-modal-header">
    <div class="schoolyear-modal-title">School Year</div>
    <span class="schoolyear-modal-close" onclick="closeSchoolYearModal()">&times;</span>
  </div>

  <p>
    <strong>Current School Year:</strong>
    <?= htmlspecialchars($schoolYearLabel, ENT_QUOTES, 'UTF-8') ?>
  </p>

    <?php if ($schoolYearAmount !== null): ?>
    <p>
      <strong>Total Amount:</strong>
      <?= number_format($schoolYearAmount, 2, ',', '.') ?> €
    </p>
  <?php else: ?>
    <p><em>No school year was found.</em></p>
  <?php endif; ?>

  <?php if ($isAdmin && $schoolYearId !== null): ?>
    <button type="button"
            class="schoolyear-edit-toggle"
            id="schoolYearEditToggle">
      Edit amount
    </button>

    <form id="schoolYearEditForm"
          class="schoolyear-edit-form"
          method="post"
          action="update_schoolyear.php">
      <input type="hidden" name="schoolyear_id" value="<?= (int)$schoolYearId ?>">

      <label for="schoolyear_amount_input">New total amount (€)</label>
      <input
        id="schoolyear_amount_input"
        name="total_amount"
        type="number"
        step="0.01"
        value="<?= htmlspecialchars(number_format($schoolYearAmount ?? 0, 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
        required
      >

      <div class="schoolyear-edit-form-actions">
        <button type="button"
                class="schoolyear-edit-cancel"
                onclick="closeSchoolYearEditForm()">
          Cancel
        </button>
        <button type="submit"
                class="schoolyear-edit-save">
          Save
        </button>
      </div>
    </form>
  <?php endif; ?>
</div>

<!-- FILTER PANEL -->
<?php
// Only include filters on specific pages that require them
$filtersPages = ['unconfirmed.php', 'student_state.php', 'Transactions.php'];
if (in_array($currentPage, $filtersPages, true)) {
    define('APP_HAS_OVERLAY', true);
    include 'filters.php';
}
?>

<div id="overlay" class="overlay" onclick="closeSidebar(); closeFilter();"></div>

<script>
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("overlay");
  const filterPanel = document.getElementById("filterPanel");
  const filterOverlay = document.getElementById("filterOverlay");
  const filterToggle = document.getElementById("filterToggle");

    const schoolYearToggle = document.getElementById("schoolYearToggle");
  const schoolYearModal  = document.getElementById("schoolYearModal");


  // SIDEBAR
  function openSidebar(){
    sidebar.classList.add("open");
    overlay.classList.add("show");
    const content = document.getElementById("content");
    if (content) content.classList.add("shifted");
  }
  function closeSidebar(){
    sidebar.classList.remove("open");
    const content = document.getElementById("content");
    if (content) content.classList.remove("shifted");

    // Only hide overlay if filter panel is also closed
    if (!filterPanel || !filterPanel.classList.contains('open')) {
      overlay.classList.remove("show");
    }
  }
  function toggleSidebar(){
    sidebar.classList.contains("open") ? closeSidebar() : openSidebar();
  }

  // FILTER Panel
  function closeFilter(){
    if (filterPanel) filterPanel.classList.remove('open');
    if (filterOverlay) filterOverlay.classList.remove('show');

    // Only hide overlay if sidebar is also closed
    if (!sidebar.classList.contains('open')) {
      overlay.classList.remove('show');
    }
  }

  // Bind filter toggle here so it works on all pages that have it
  if (filterToggle && filterPanel) {
    filterToggle.addEventListener('click', () => {
      const willOpen = !filterPanel.classList.contains('open');

      if (willOpen) {
        filterPanel.classList.add('open');
        if (filterOverlay) filterOverlay.classList.add('show');
        overlay.classList.add('show');
      } else {
        filterPanel.classList.remove('open');
        if (filterOverlay) filterOverlay.classList.remove('show');
        if (!sidebar.classList.contains('open')) {
          overlay.classList.remove('show');
        }
      }
    });
  }

    // SCHOOL YEAR POPUP
  function openSchoolYearModal() {
    if (!schoolYearModal) return;
    schoolYearModal.classList.add('show');
    schoolYearModal.setAttribute('aria-hidden', 'false');
  }

  function closeSchoolYearModal() {
    if (!schoolYearModal) return;
    schoolYearModal.classList.remove('show');
    schoolYearModal.setAttribute('aria-hidden', 'true');
  }

  if (schoolYearToggle && schoolYearModal) {
    schoolYearToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      if (schoolYearModal.classList.contains('show')) {
        closeSchoolYearModal();
      } else {
        openSchoolYearModal();
      }
    });
  }

  // Close when clicking outside or pressing ESC
  document.addEventListener('click', (e) => {
    if (!schoolYearModal || !schoolYearModal.classList.contains('show')) return;
    if (!schoolYearModal.contains(e.target) && e.target !== schoolYearToggle) {
      closeSchoolYearModal();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && schoolYearModal && schoolYearModal.classList.contains('show')) {
      closeSchoolYearModal();
    }
  });

    function openSchoolYearEditForm() {
    if (!schoolYearEditForm) return;
    schoolYearEditForm.classList.add('show');
  }

  function closeSchoolYearEditForm() {
    if (!schoolYearEditForm) return;
    schoolYearEditForm.classList.remove('show');
  }

  if (schoolYearEditToggle && schoolYearEditForm) {
    schoolYearEditToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = schoolYearEditForm.classList.contains('show');
      if (isOpen) {
        closeSchoolYearEditForm();
      } else {
        openSchoolYearEditForm();
      }
    });
  }

  // when closing the popup, also close the edit form
  function closeSchoolYearModal() {
    if (!schoolYearModal) return;
    schoolYearModal.classList.remove('show');
    schoolYearModal.setAttribute('aria-hidden', 'true');
    closeSchoolYearEditForm();
  }

</script>

<?php if (defined('NAV_STANDALONE') && NAV_STANDALONE): ?>
</body>
</html>
<?php endif; ?>
