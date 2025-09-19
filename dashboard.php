<?php
require 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <title>Home</title>

  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Roboto:wght@400;500&family=Space+Grotesk:wght@400;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

  <style>
    :root{
      --red-dark:#B31E32;   
      --red-main:#D4463B;  
      --red-light:#FAE4D5;
      --off-white:#FFF8EB;  
      --gray-light:#E3E5E0;
    }

  *{box-sizing:border-box}
    body{
      margin:0;
      font-family:'Roboto',sans-serif;
      color:#222;
      background:var(--off-white);
    }

   
    .header{
      position: fixed;
      top: 0; left: 0; right: 0;
      z-index: 1300;
      height: 60px;
      display:flex; align-items:center; justify-content:space-between;
      padding:45px 50px;
      background:var(--red-main); color:#fff;
      box-shadow:0 2px 6px rgba(0,0,0,.1);
    }
  
    .nav-left {
      display: flex;
      align-items: center;
      gap: 20px; 
    }

    .menu-icon,
    .nav-icon {
      font-size: 26px;
      cursor: pointer;
      color: white;
      transition: 0.2s;
    }

    .menu-icon:hover,
    .nav-icon:hover {
      color: var(--red-dark); 
    }

    .logo img{
      height:40px;
    }

   .sidebar {
      position: fixed;
      top: 60px;  
      left: 0;
      bottom: 0;
      width: 0;
      padding-top: 30px;
      background: #fff;
      overflow-x: hidden;
      transition: width 0.3s ease;
      z-index: 1200;
      border-right: 1px solid var(--gray-light);
      box-shadow: 2px 0 8px rgba(0,0,0,.15);
    }

    .sidebar.open {
      width: 260px; 
    }

    #content {
      transition: margin-left 0.3s ease;
      margin-left: 0;
      padding-top: 80px; 
    }

    #content.shifted {
      margin-left: 260px; 
    }

    #content {
      transition: margin-left 0.3s ease;
      margin-left: 0;
    }

    #content.shifted {
      margin-left: 260px; 
    }

    .sidebar-inner {
      display: flex;
      flex-direction: column;
      height: 100%;
      padding-top: 0;
    }

    .sidebar header{
      font-family:'Montserrat',sans-serif;
      font-weight:600; color:var(--red-dark);
      font-size:18px;
      padding: 15px 20px 12px; 
    }

    .close-btn{
      float:right; 
      cursor:pointer
    }

    nav a,
    .logout-link{
      padding:14px 20px;
      display:flex; align-items:center; gap:12px;
      text-decoration:none;
      font-size:15px; color:#333;
      border-left:3px solid transparent;
      transition:background .2s, color .2s, border-color .2s;
    }

    nav a .material-icons-outlined,
    .logout-link .material-icons-outlined{
      font-size:20px; 
      color:#666;
    }

    nav a:hover,
    .logout-link:hover{
      background:var(--red-light);
      color:var(--red-dark);
      border-left-color:var(--red-dark);
    }

    nav a:hover .material-icons-outlined,
    .logout-link:hover .material-icons-outlined{
      color:var(--red-dark);
    }

    .logout-wrap {
      border-top: 1px solid var(--gray-light);
      margin-top: auto;  
    }

    .logout-link{
      color:var(--red-main); 
      font-weight:500;
    }

    .logout-link .material-icons-outlined{
      color:var(--red-main);
    }

    .logout-link:hover{
      color:var(--red-dark);
    }

    .logout-link:hover .material-icons-outlined{
      color:var(--red-dark);
    }

    .main{
      margin:30px; padding:20px;
    }

    .main h1{
      font-family:'Space Grotesk',sans-serif;
      font-size:28px; font-weight:700; color:var(--red-dark);
      margin:0 0 20px;
    }

    footer{
      position:fixed; bottom:0; left:0; right:0;
      background:var(--gray-light); color:#333;
      text-align:center; padding:12px 10px; font-size:14px;
      border-top:1px solid #ccc;
      z-index:900;
    }

    .overlay{
      position:fixed; inset:0;
      background:rgba(0,0,0,.25);
      opacity:0; pointer-events:none; transition:opacity .28s ease;
      z-index:1150;
    }
    .overlay.show{
      opacity:1; 
      pointer-events:auto;
    }

  </style>
</head>
<body>
  <div class="header">
  <div class="nav-left">
    <span class="menu-icon material-icons-outlined" onclick="toggleSidebar()">menu</span>
    <span class="nav-icon material-icons-outlined">notifications</span>
    <span class="nav-icon material-icons-outlined">priority_high</span>
  </div>

  <div class="logo">
    <img src="logo1.png" alt="Logo">
  </div>
</div>
  <aside id="sidebar" class="sidebar" aria-label="Seitennavigation">
    <div class="sidebar-inner">
      <header>
        MENU
        <span class="close-btn" onclick="closeSidebar()">&times;</span>
      </header>

      <nav>
        <a href="#"><span class="material-icons-outlined">payments</span> Add Payment</a>
        <a href="#"><span class="material-icons-outlined">swap_horiz</span> Add Transaction</a>
        <a href="#"><span class="material-icons-outlined">school</span> Student State</a>
        <a href="#"><span class="material-icons-outlined">schedule</span> Latencies</a>
        <a href="#"><span class="material-icons-outlined">upload_file</span> Import File</a>
        <a href="#"><span class="material-icons-outlined">check_circle</span> Unconfirmed</a>
        <a href="#"><span class="material-icons-outlined">link</span> Connections</a>
        <a href="#"><span class="material-icons-outlined">help_outline</span> Help & Tutorial</a>
      </nav>

      <div class="logout-wrap">
        <a class="logout-link" href="#">
          <span class="material-icons-outlined">logout</span> Logout
        </a>
      </div>
    </div>
  </aside>

  <div id="overlay" class="overlay" onclick="closeSidebar()"></div>

<div id="content">
  <main class="main">
    <h1>DASHBOARD</h1>
    <p>Welcome back, Esmerina!</p>
  </main>

  <footer>
    © School's Transaction Matching System | Powered By HTL Shkodra
  </footer>
</div>

<script>
  const sidebar = document.getElementById("sidebar");
  const content = document.getElementById("content");

  function toggleSidebar() {
    if (sidebar.classList.contains("open")) {
      sidebar.classList.remove("open");
      content.classList.remove("shifted");
    } else {
      sidebar.classList.add("open");
      content.classList.add("shifted");
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    const closeBtn = document.querySelector(".close-btn");
    if (closeBtn) {
      closeBtn.addEventListener("click", toggleSidebar);
    }
  });
</script>
</body>
</html>