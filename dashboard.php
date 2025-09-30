<?php
require 'db_connect.php';
require 'navigator.html';
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
      font-family:'Montserrat',sans-serif; font-weight:600;
      color:var(--red-dark); text-align:center; padding:10px 0 2px;
      font-size:16px;
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
      margin:0 0 10px; font-size:16px; color:var(--red-dark);
      font-family:'Montserrat',sans-serif; font-weight:600;
    }
.panel hr{ border:none; border-top:1px solid var(--gray-light); margin:6px 0 12px; }
.panel p{ margin:8px 0; font-size: 13px; }

    /* Nachrichten-Liste */
    ul.msgs {
      margin: 0;
      padding: 0;
      list-style: none;
    }

    ul.msgs li {
      display: flex;
      align-items: center;
      margin: 8px 0;
      font-size: 13px;
      padding: 4px 0;
    }

    ul.msgs li .icon {
      display: inline-flex;
      justify-content: center;
      align-items: center;
      width: 22px;
      height: 22px;
      border-radius: 50%;
      margin-right: 10px;
      font-size: 16px;
      color: #fff;
      flex-shrink: 0;
    }

    /* Farben */
    ul.msgs li.warn .icon {
      background: orange;
    }

    ul.msgs li.success .icon {
      background:  #66BB6A;
    }

    ul.msgs li.info .icon {
      background: #555;
    }

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
                <li class="warn">
                  <span class="icon material-icons-outlined">warning</span>
                  3 payments waiting for administrator confirmation
                </li>
                <li class="success">
                  <span class="icon material-icons-outlined">check_circle</span>
                  76 transactions successfully calculated this week
                </li>
                <li class="info">
                  <span class="icon material-icons-outlined">info</span>
                  Last import on 12.08.2025
                </li>
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