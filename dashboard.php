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

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <style>
    :root{
      --red-dark:#B31E32;     
      --red-main:#D4463B;     
      --red-light:#FAE4D5;   
      --off-white:#FFF8EB;   
      --gray-light:#E3E5E0; 
      --ink:#000;
    }

    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family:'Roboto',sans-serif;
      color:#222;
      background:#fff;
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
    .menu-icon, .nav-icon { font-size:26px; cursor:pointer; color:white; transition:.2s; user-select:none; }
    .menu-icon:hover, .nav-icon:hover { color:var(--red-dark); }
    .logo img{ height:36px; }

    /* SIDEBAR */
    .sidebar {
      position: fixed;
      top: 60px;  
      left: 0;
      bottom: 0;
      width: 0;
      padding-top: 20px;
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
    .filter-panel {
      position: fixed;
      top: 60px;
      left: 0; right: 0;
      background: #f9f9f9;
      border-bottom: 1px solid var(--gray-light);
      padding: 16px 24px;
      display: none;
      transition: margin-left 0.3s ease;
      z-index: 1250;
    }
    .filter-panel.open { display: block; }
    .filter-panel.shifted { margin-left: 260px; }

    .filter-form { display:flex; flex-wrap:wrap; gap:24px; align-items:center; }
    .filter-group { display:flex; flex-direction:column; min-width:160px; }
    .filter-input,.filter-select{ padding:6px 10px; border:1px solid #ccc; border-radius:6px; min-height:34px; }
    .date-group{ display:flex; gap:8px; }
    .range-container{ display:flex; flex-direction:column; min-width:240px; }
    .range-header{ display:flex; justify-content:space-between; align-items:center; }
    .filter-button{ background:var(--red-dark); color:white; border:none; padding:6px 14px; border-radius:8px; cursor:pointer; height:38px; }
    .range-values{ font-size:14px; margin:4px 0; color:#444; }
    .range-container input[type="range"]{ -webkit-appearance:none; width:100%; height:6px; background:var(--gray-light); border-radius:4px; cursor:pointer; }
    .range-container input[type="range"]::-webkit-slider-thumb{ -webkit-appearance:none; width:14px; height:14px; border-radius:50%; background:var(--red-main); border:none; }
    .range-container input[type="range"]::-moz-range-thumb{ width:14px; height:14px; border-radius:50%; background:var(--red-main); border:none; }

    /* OVERLAY */
    .overlay{
      position:fixed; inset:0;
      background:rgba(0,0,0,.25);
      opacity:0; pointer-events:none; transition:opacity .28s ease;
      z-index:1150;
    }
    .overlay.show{ opacity:1; pointer-events:auto; }

    /* CONTENT WRAPPER */
    #content {
      transition: margin-left 0.3s ease;
      margin-left: 0;
      padding: 100px 30px 60px; 
    }
    #content.shifted { margin-left: 260px; }

    .main h1{
      font-family:'Space Grotesk',sans-serif;
      font-size:28px; font-weight:700; color:var(--red-dark);
      letter-spacing:.5px;
      margin:0 0 18px;
    }

    /* DASHBOARD GRID */
    .dashboard-grid{
      display:grid;
      grid-template-columns: 1.6fr 1.6fr 0.9fr;   
      grid-template-rows: auto auto;              
      gap:16px;
      align-items:stretch;
    }
    .chart-cell{    grid-column:1 / span 2; grid-row:1; }
    .summary-cell{  grid-column:1;           grid-row:2; }
    .messages-cell{ grid-column:2;           grid-row:2; }
    .widgets-cell{  grid-column:3;           grid-row:1 / span 2; }

    /* BOXES */
    .box{
      background:#fff;
      border:1px solid var(--gray-light);
      border-radius:8px;
      box-shadow:0 1px 3px rgba(0,0,0,.06);
      display:flex; flex-direction:column;
    }

    /* Chart */
    .chart-box{ padding:0; }
    .chart-title{
      font-family:'Space Grotesk',sans-serif; font-weight:700;
      color:var(--red-dark); text-align:center; padding:10px 0 2px;
      font-size:18px;
    }
    .chart-title::after{
      content:""; display:block; height:1px; background:var(--gray-light);
      width:58%; margin:6px auto 4px;
    }
    .chart-inner{ flex:1; padding:0 8px 10px; }
    #paymentsChart{ width:100% !important; height:210px !important; }

/* Panels */
.panel {
  padding:14px 16px;
  height:100%;               
  display:flex;
  flex-direction:column;
}
.panel h3{
  margin:0 0 10px; font-size:18px; color:var(--red-dark);
  font-family:'Montserrat',sans-serif; font-weight:600;
}
.panel hr{ border:none; border-top:1px solid var(--gray-light); margin:6px 0 12px; }
.panel p{ margin:8px 0; font-size: 13px; }

/* Messages list */
ul.msgs{ margin:0 0 0 18px; padding:0; }
ul.msgs li{ margin:8px 0; font-size: 13px;}

.widgets-column{
  height:100%;
  display:flex;
  flex-direction:column;
  gap: 20px;
  padding-left:8px;
}
.widget{
  height:110px;              
  width:100%;
  max-width:260px;
  margin:0 auto;
  padding:14px 12px;
  text-align:center;
  border-left:4px solid var(--red-main);
  display:flex; flex-direction:column; justify-content:center;
}
.widget .label{ font-family:'Montserrat',sans-serif; font-size:14px; color:#555; margin-bottom:6px; }
.widget .val{ font-family:'Space Grotesk',sans-serif; font-size:22px; font-weight:700; color:var(--red-dark); }

    /* FOOTER */
    footer{
      position:fixed; bottom:0; left:0; right:0;
      background:var(--gray-light); color:#333;
      text-align:center; padding:10px; font-size:14px;
      border-top:1px solid #ccc;
      z-index:900;
    }
  </style>
</head>
<body>
  <!-- HEADER -->
  <div class="header">
    <div class="nav-left">
      <span class="menu-icon material-icons-outlined" onclick="toggleSidebar()">menu</span>
      <span class="nav-icon material-icons-outlined">notifications</span>
      <span class="nav-icon material-icons-outlined">priority_high</span>
      <span class="nav-icon material-icons-outlined" id="filterToggle">filter_list</span>
    </div>
    <div class="logo"><img src="logo1.png" alt="Logo"></div>
  </div>

  <!-- FILTER PANEL -->
  <div id="filterPanel" class="filter-panel">
    <form class="filter-form">
      <!-- Student -->
      <div class="filter-group">
        <label>Student</label>
        <input type="text" placeholder="Student name" class="filter-input">
      </div>

      <!-- Class -->
      <div class="filter-group">
        <label>Class</label>
        <select class="filter-select">
          <option value="">All classes</option>
          <option value="4AHIF">5A</option>
          <option value="3BHIF">5B</option>
        </select>
      </div>

      <!-- Status -->
      <div class="filter-group">
        <label>Status</label>
        <select class="filter-select">
          <option value="">All statuses</option>
          <option value="paid">Paid</option>
          <option value="open">Open</option>
          <option value="overdue">Overdue</option>
          <option value="partial">Partial payment</option>
        </select>
      </div>

      <!-- Date Range -->
      <div class="filter-group">
        <label>Date Range</label>
        <div class="date-group">
          <input type="date" class="filter-input">
          <input type="date" class="filter-input">
        </div>
      </div>

      <!-- Amount Range -->
      <div class="range-container">
        <div class="range-header">
          <label class="range-label">Amount Range (€)</label>
          <button type="submit" class="filter-button">Apply</button>
        </div>
        <div class="range-values">
          <span id="rangeMin">0</span> € – <span id="rangeMax">1000</span> €
        </div>
        <input type="range" id="amountRange" min="0" max="1000" value="500" step="10">
      </div>
    </form>
  </div>

  <!-- SIDEBAR -->
  <aside id="sidebar" class="sidebar" aria-label="Seitennavigation">
    <div class="sidebar-inner">
      <header>MENU <span class="close-btn" onclick="closeSidebar()">&times;</span></header>
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
        <a class="logout-link" href="#"><span class="material-icons-outlined">logout</span> Logout</a>
      </div>
    </div>
  </aside>
  <div id="overlay" class="overlay" onclick="closeSidebar()"></div>

  <!-- CONTENT -->
  <div id="content">
    <main class="main">
      <h1>DASHBOARD</h1>

      <!-- GRID -->
      <section class="dashboard-grid">
        <!-- CHART -->
        <div class="chart-cell">
          <div class="box chart-box">
            <div class="chart-title">Payments Left</div>
            <div class="chart-inner">
              <canvas id="paymentsChart"></canvas>
            </div>
          </div>
        </div>

        <!-- SUMMARY -->
        <div class="summary-cell">
          <div class="box panel">
            <h3>Transaction Summary</h3>
            <hr>
            <p>Average payment delay: 4 days</p>
          </div>
        </div>

        <!-- MESSAGES -->
        <div class="messages-cell">
          <div class="box panel">
            <h3>Messages</h3>
            <hr>
            <ul class="msgs">
              <li>3 payments waiting for administrator confirmation</li>
            </ul>
          </div>
        </div>

        <!-- WIDGETS -->
        <aside class="widgets-cell">
          <div class="widgets-column">
            <div class="box widget"><div class="label">Number of Students</div><div class="val">453</div></div>
            <div class="box widget"><div class="label">Critical Cases</div><div class="val">6</div></div>
            <div class="box widget"><div class="label">Total Transactions</div><div class="val">3907</div></div>
            <div class="box widget"><div class="label">Left to pay</div><div class="val">47</div></div>
          </div>
        </aside>
      </section>
    </main>

    <footer>© School's Transaction Matching System | Powered By HTL Shkodra</footer>
  </div>

  <script>
    const sidebar   = document.getElementById("sidebar");
    const content   = document.getElementById("content");
    const overlay   = document.getElementById("overlay");
    const filterToggle = document.getElementById("filterToggle");
    const filterPanel  = document.getElementById("filterPanel");

    function openSidebar(){
      sidebar.classList.add("open");
      content.classList.add("shifted");
      filterPanel.classList.add("shifted");
      overlay.classList.add("show");
    }
    function closeSidebar(){
      sidebar.classList.remove("open");
      content.classList.remove("shifted");
      filterPanel.classList.remove("shifted");
      overlay.classList.remove("show");
    }
    function toggleSidebar(){ sidebar.classList.contains("open") ? closeSidebar() : openSidebar(); }

    // Filter panel toggle 
    if (filterToggle) {
      filterToggle.addEventListener("click", () => {
        filterPanel.classList.toggle("open");
      });
    }

    // Range labels
    const amountRange = document.getElementById("amountRange");
    const rangeMinLabel = document.getElementById("rangeMin");
    const rangeMaxLabel = document.getElementById("rangeMax");
    if (amountRange) {
      amountRange.addEventListener("input", () => {
        const val = parseInt(amountRange.value, 10);
        rangeMinLabel.textContent = 0;
        rangeMaxLabel.textContent = val;
      });
    }

    const ctx = document.getElementById("paymentsChart").getContext("2d");
    new Chart(ctx,{
      type:"bar",
      data:{
        labels:["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
        datasets:[{
          data:[12,7,11,28,22,40,3,5,33,90,65,28],
          backgroundColor:"#D4463B",         
          borderRadius:2,
          barPercentage:0.75,                 
          categoryPercentage:0.8,
          maxBarThickness:38
        }]
      },
      options:{
        maintainAspectRatio:false,
        plugins:{
          legend:{ display:false },
          tooltip:{ enabled:true }
        },
        layout:{ padding:0 },
        scales:{
          y:{
            beginAtZero:true, max:100, ticks:{ stepSize:20, font:{ size:11 } },
            grid:{ color:"rgba(0,0,0,.08)" }
          },
          x:{
            ticks:{ font:{ size:11 } },
            grid:{ display:false }
          }
        }
      }
    });
  </script>
</body>
</html>