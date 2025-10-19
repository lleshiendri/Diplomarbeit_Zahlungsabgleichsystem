<?php
require_once 'auth_check.php';
require 'db_connect.php';
require 'navigator.php';

$students_res = $conn->query("SELECT COUNT(*) AS c FROM STUDENT_TAB");
$students = $students_res->fetch_assoc()['c'] ?? 0;

$critical_res = $conn->query("SELECT COUNT(*) AS c FROM STUDENT_TAB WHERE left_to_pay > 1000");
$critical = $critical_res->fetch_assoc()['c'] ?? 0;

$transactions_res = $conn->query("SELECT COUNT(*) AS c FROM INVOICE_TAB");
$total_transactions = $transactions_res->fetch_assoc()['c'] ?? 0;

$left_res = $conn->query("SELECT SUM(left_to_pay) AS s FROM STUDENT_TAB");
$left_to_pay = $left_res->fetch_assoc()['s'] ?? 0;

$unconfirmed_res = $conn->query("SELECT COUNT(*) AS c FROM INVOICE_TAB WHERE processing_date IS NULL");
$unconfirmed = $unconfirmed_res->fetch_assoc()['c'] ?? 0;

$processed_week_res = $conn->query("
    SELECT COUNT(*) AS c 
    FROM INVOICE_TAB 
    WHERE processing_date IS NOT NULL 
    AND YEARWEEK(processing_date, 1) = YEARWEEK(CURDATE(), 1)
");
$processed_week = $processed_week_res->fetch_assoc()['c'] ?? 0;

$last_import_res = $conn->query("SELECT MAX(processing_date) AS last FROM INVOICE_TAB");
$last_import = $last_import_res->fetch_assoc()['last'] ?? null;
$last_import_display = $last_import ? date("d.m.Y", strtotime($last_import)) : "Keine Daten";

$delay_res = $conn->query("
    SELECT AVG(DATEDIFF(processing_date, CURDATE())) AS avg_delay 
    FROM INVOICE_TAB 
    WHERE processing_date IS NOT NULL
");
$avg_delay = round($delay_res->fetch_assoc()['avg_delay'] ?? 0, 1);

$chart_data = [];
for ($m = 1; $m <= 12; $m++) {
    $res = $conn->query("
        SELECT IFNULL(SUM(amount_total), 0) AS total
        FROM INVOICE_TAB
        WHERE MONTH(processing_date) = $m
    ");
    $chart_data[] = (float) $res->fetch_assoc()['total'];
}
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
    }
    *{ box-sizing:border-box; }
    body{
      margin:0;
      font-family:'Roboto',sans-serif;
      color:#222;
      background:#fff;
    }
  #content {transition: margin-left 0.3s ease;margin-left: 0;padding: 100px 30px 60px;}
    #content.shifted { margin-left: 260px; }
    .main h1{
      font-family:'Space Grotesk',sans-serif;
      font-size:28px; font-weight:700; color:var(--red-dark);
      letter-spacing:.5px;
      margin:0 0 18px;
    }

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

    .summary-cell p {
      font-size: 13px;
    }
    .box{
      background:#fff;
      border:1px solid var(--gray-light);
      border-radius:8px;
      box-shadow:0 1px 3px rgba(0,0,0,.06);
      display:flex; flex-direction:column;
    }

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

    .panel h3::after {
      content: "";
      display: block;
      height: 1px;
      background: var(--gray-light);
      width: 58%;
      margin: 6px 0 4px;
    }

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
    ul.msgs li.warn .icon { background: orange; }
    ul.msgs li.success .icon { background:  #66BB6A; }
    ul.msgs li.info .icon { background: #555; }

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
  <div id="content">
    <main class="main">
      <h1>DASHBOARD</h1>

      <section class="dashboard-grid">
        <div class="chart-cell">
          <div class="box chart-box">
            <div class="chart-title">Payments Left</div>
            <div class="chart-inner">
              <canvas id="paymentsChart"></canvas>
            </div>
          </div>
        </div>

        <div class="summary-cell">
          <div class="box panel">
            <h3>Transaction Summary</h3>
            <hr>
            <p>Average payment delay: <?= $avg_delay ?> days</p>
          </div>
        </div>

        <div class="messages-cell">
          <div class="box panel">
            <h3>Messages</h3>
            <hr>
              <ul class="msgs">
                <li class="warn">
                  <span class="icon material-icons-outlined">warning</span>
                  <?= $unconfirmed ?> payments waiting for administrator confirmation
                </li>
                <li class="success">
                  <span class="icon material-icons-outlined">check_circle</span>
                  <?= $processed_week ?> transactions successfully calculated this week
                </li>
                <li class="info">
                  <span class="icon material-icons-outlined">info</span>
                  Last import on <?= $last_import_display ?>
                </li>
              </ul>
          </div>
        </div>

        <aside class="widgets-cell">
          <div class="widgets-column">
            <div class="box widget"><div class="label">Number of Students</div><div class="val"><?= $students ?></div></div>
            <div class="box widget"><div class="label">Critical Cases</div><div class="val"><?= $critical ?></div></div>
            <div class="box widget"><div class="label">Total Transactions</div><div class="val"><?= $total_transactions ?></div></div>
            <div class="box widget"><div class="label">Left to pay</div><div class="val"><?= number_format($left_to_pay, 2, ',', '.') ?> €</div></div>
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
    function openSidebar(){ sidebar.classList.add("open"); content.classList.add("shifted"); overlay.classList.add("show"); }
    function closeSidebar(){ sidebar.classList.remove("open"); content.classList.remove("shifted"); overlay.classList.remove("show"); }
    function toggleSidebar(){ sidebar.classList.contains("open") ? closeSidebar() : openSidebar(); }

    const ctx = document.getElementById("paymentsChart").getContext("2d");
    new Chart(ctx,{
      type:"bar",
      data:{
        labels:["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"],
        datasets:[{
          data: <?= json_encode($chart_data) ?>,
          backgroundColor:"#D4463B",         
          borderRadius:2,
          barPercentage:0.75,                 
          categoryPercentage:0.8,
          maxBarThickness:38
        }]
      },
      options:{
        maintainAspectRatio:false,
        plugins:{ legend:{ display:false }, tooltip:{ enabled:true }},
        layout:{ padding:0 },
        scales:{
          y:{ beginAtZero:true, ticks:{ stepSize:20, font:{ size:11 } }, grid:{ color:"rgba(0,0,0,.08)" }},
          x:{ ticks:{ font:{ size:11 } }, grid:{ display:false }}
        }
      }
    });
  </script>
</body>
</html>