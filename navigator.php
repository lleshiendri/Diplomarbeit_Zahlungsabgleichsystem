<?php
// Aktuelle Seite erkennen (z. B. "dashboard.php" oder "unconfirmed.php")
$currentPage = basename($_SERVER['PHP_SELF']);
require 'filters.html';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
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

  nav a, .logout-link{
    padding:14px 20px;
    display:flex; align-items:center; gap:12px;
    text-decoration:none;
    font-size:15px; color:#333;
    border-left:3px solid transparent;
    transition:background .2s, color .2s, border-color .2s;
  }
  nav a .material-icons-outlined,
  .logout-link .material-icons-outlined{ font-size:20px; color:#666; }
  nav a:hover, .logout-link:hover{
    background:var(--red-light);
    color:var(--red-dark);
    border-left-color:var(--red-dark);
  }
  .logout-wrap { border-top: 1px solid var(--gray-light); margin-top: auto; }
  .logout-link{ color:var(--red-main); font-weight:500; }

  /* FILTER PANEL */
  #filterPanel {
    position: fixed;
    top: 70px;
    right: -320px;
    width: 320px;
    height: calc(100% - 70px);
    background: #fff;
    border-left: 1px solid var(--gray-light);
    box-shadow: -2px 0 8px rgba(0,0,0,.1);
    transition: right 0.3s ease;
    z-index: 1250;
  }
  #filterPanel.open { right: 0; }

  /* OVERLAY */
  .overlay {
    position: fixed;
    top:0; left:0; right:0; bottom:0;
    background: rgba(0,0,0,0.3);
    display:none;
    z-index:1100;
  }
  .overlay.show { display:block; }
  </style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <div class="nav-left">
    <span class="menu-icon material-icons-outlined" onclick="toggleSidebar()">menu</span>
    <span class="nav-icon material-icons-outlined">notifications</span>
    <span class="nav-icon material-icons-outlined">priority_high</span>

    <?php if ($currentPage !== 'dashboard.php'): ?>
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
      <a href="#"><span class="material-icons-outlined">school</span> Student State</a>
      <a href="#"><span class="material-icons-outlined">schedule</span> Latencies</a>
      <a href="import_files.php"><span class="material-icons-outlined">upload_file</span> Import File</a>
      <a href="unconfirmed.php"><span class="material-icons-outlined">check_circle</span> Unconfirmed</a>
      <a href="#"><span class="material-icons-outlined">link</span> Connections</a>
      <a href="#"><span class="material-icons-outlined">help_outline</span> Help & Tutorial</a>
    </nav>
    <div class="logout-wrap">
      <a class="logout-link" href="http://buchhaltung.htl-projekt.com/login/login.php">
        <span class="material-icons-outlined">logout</span> Logout
      </a>
    </div>
  </div>
</aside>

<?php if ($currentPage != 'dashboard.php'): ?>
  <div id="filterPanel">
    <?php include 'filters.html';?>
  </div>
<?php endif; ?>

<div id="overlay" class="overlay" onclick="closeSidebar(); closeFilter();"></div>

<script>
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("overlay");
  const filterPanel = document.getElementById("filterPanel");
  const filterToggle = document.getElementById("filterToggle");

  // SIDEBAR
  function openSidebar(){
    sidebar.classList.add("open");
    overlay.classList.add("show");
  }
  function closeSidebar(){
    sidebar.classList.remove("open");
    overlay.classList.remove("show");
  }
  function toggleSidebar(){
    sidebar.classList.contains("open") ? closeSidebar() : openSidebar();
  }

  // FILTER Panel
  function closeFilter(){
    if (filterPanel) filterPanel.classList.remove('open');
  }

  if (filterToggle && filterPanel) {
    filterToggle.addEventListener('click', () => {
      filterPanel.classList.toggle('open');
      overlay.classList.toggle('show', filterPanel.classList.contains('open') || sidebar.classList.contains('open'));
    });
  }
</script>

</body>
</html>