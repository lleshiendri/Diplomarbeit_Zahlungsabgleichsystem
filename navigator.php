<?php
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Skip rendering navigator entirely on the login page
if ($currentPage === 'login.php') {
	return;
}

$shouldEnforceAuth = !defined('NAV_STANDALONE') || !NAV_STANDALONE;
if ($shouldEnforceAuth && !defined('NAV_SKIP_AUTH') && !isset($_SESSION['user_id'])) {
	header('Location: login/login.php');
	exit;
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

  /* Generic content shift when sidebar is open (used by non-dashboard pages) */
  #content { transition: margin-left 0.3s ease; }
  #content.shifted { margin-left: 260px; }
  </style>
<?php if (defined('NAV_STANDALONE') && NAV_STANDALONE): ?>
</head>
<body>
<?php endif; ?>

<!-- HEADER -->
<div class="header">
  <div class="nav-left">
    <span class="menu-icon material-icons-outlined" onclick="toggleSidebar()">menu</span>
    <a href="notifications.php"><span class="nav-icon material-icons-outlined">notifications</span></a>
    <a href="unconfirmed.php"><span class="nav-icon material-icons-outlined">priority_high</span></a>

    <?php if (in_array($currentPage, ['unconfirmed.php','student_state.php'], true)): ?>
      <span class="nav-icon material-icons-outlined" id="filterToggle">filter_list</span>
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
      <a href="add_transactions.php"><span class="material-icons-outlined">swap_horiz</span> Add Transaction</a>
      <a href="add_students.php"><span class="material-icons-outlined">group_add</span> Add Students</a>
      <a href="student_state.php"><span class="material-icons-outlined">school</span> Student State</a>
      <a href="latencies.php"><span class="material-icons-outlined">schedule</span> Latencies</a>
      <a href="import_files.php"><span class="material-icons-outlined">upload_file</span> Import File</a>
      <a href="unconfirmed.php"><span class="material-icons-outlined">priority_high</span> Unconfirmed</a>
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

<!-- FILTER PANEL -->
<?php
// Only include filters on specific pages that require them
$filtersPages = ['unconfirmed.php', 'student_state.php'];
if (in_array($currentPage, $filtersPages, true)) {
	define('APP_HAS_OVERLAY', true);
	include 'filters.php';
}
?>

<div id="overlay" class="overlay" onclick="closeSidebar(); closeFilter();"></div>


<script>
  const navSidebar = document.getElementById("sidebar");
  const navOverlay = document.getElementById("overlay");
  const navFilterPanel = document.getElementById("filterPanel");
  const navFilterOverlay = document.getElementById("filterOverlay");
  const navFilterToggle = document.getElementById("filterToggle");

  // SIDEBAR
  function openSidebar(){
    if (navSidebar) navSidebar.classList.add("open");
    if (navOverlay) navOverlay.classList.add("show");
    const content = document.getElementById("content");
    if (content) content.classList.add("shifted");
  }
  function closeSidebar(){
    if (navSidebar) navSidebar.classList.remove("open");
    if (navOverlay) navOverlay.classList.remove("show");
    const content = document.getElementById("content");
    if (content) content.classList.remove("shifted");
  }
  function toggleSidebar(){
    if (!navSidebar) return;
    navSidebar.classList.contains("open") ? closeSidebar() : openSidebar();
  }

  // FILTER Panel
  function closeFilter(){
    if (navFilterPanel) navFilterPanel.classList.remove('open');
    if (navFilterOverlay) navFilterOverlay.classList.remove('show');
  }

  // Do not bind filterToggle here; filters.php handles it to avoid double toggling
</script>
<?php if (defined('NAV_STANDALONE') && NAV_STANDALONE): ?>
</body>
</html>
<?php endif; 
?> 