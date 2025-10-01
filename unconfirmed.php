<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Unconfirmed</title>

  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Roboto:wght@400;500&family=Space+Grotesk:wght@700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">

  <style>
    :root{
      --red-dark:#B31E32;
      --red-main:#D4463B;
      --red-light:#FAE4D5;
      --off-white:#FFF8EB;
      --gray-light:#E3E5E0;
      --radius:10px;
      --shadow:0 1px 2px rgba(0,0,0,.06),0 2px 10px rgba(0,0,0,.04);
      --sidebar-w:320px;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:'Roboto',sans-serif;
      color:black;
      background:#fff;
    }

    #content{padding:96px 24px 40px;max-width:1200px;margin:0 auto;}

    .page h1{
      font-family:'Space Grotesk',sans-serif;
      font-size:28px; font-weight:700; color:var(--red-dark);
      letter-spacing:.5px;
      margin:0 0 8px;
    }

    .subtitle{
      display:inline-block;
      margin:0 0 23px;
      padding:6px 10px;
      font-size:14px;
      color:#444;
      background:#fff;
      border-radius:8px;
      box-shadow:var(--shadow);
    }

    .layout{
      display:grid;
      grid-template-columns: 1fr var(--sidebar-w);
      gap:20px;
      align-items:start;
    }

    .card{
      border:1px solid var(--gray-light);
      border-radius:var(--radius);
      background:#fff;
      box-shadow:var(--shadow);
      padding:20px;
    }

    /* Table */
    table{width:100%;border-collapse:collapse;font-size:14px;}
    thead th{
      font-family:'Montserrat',sans-serif; 
      text-align:left;
      padding:12px 14px;
      background:var(--red-light);
      color:#333;
      font-weight:600;
    }
    tbody td{
      font-family:'Roboto',sans-serif;     
      padding:12px 14px;
      border-top:1px solid var(--gray-light);
    }
    .amount{text-align:right;font-variant-numeric:tabular-nums;}

    /* Hover Effekt */
    tbody tr:hover {
      background-color:#f5f5f5;
      transition:background-color 0.2s ease-in-out;
      cursor:pointer;
    }

    .stack{display:flex;flex-direction:column;gap:16px;}

    .stats{
      display:grid; grid-template-columns:1fr 1fr; gap:12px;
    }
    .stat{
      border:1px solid var(--gray-light);
      border-radius:var(--radius);
      background:#fff;
      box-shadow:var(--shadow);
      padding:10px 12px;
      text-align:center;
    }
    .stat .num{
      font-family:'Space Grotesk',sans-serif;
      font-weight:700; font-size:28px; line-height:1;
      margin-bottom:6px; color:#000;
    }
    .stat .label{
      font-size:12px; color:#333;
      border-top:1px solid var(--gray-light);
      padding-top:6px;
      font-family:'Roboto',sans-serif;
    }

    .side-title{
      font-family:'Montserrat',sans-serif; 
      font-weight:600;
      color:#333;
      font-size:15px;
      margin:0 0 8px;
    }
    .side-input{
      font-family:'Roboto',sans-serif;
      width:100%;
      border:1px solid var(--gray-light);
      border-radius:8px;
      padding:10px 12px;
      font-size:14px;
      margin-bottom:14px;
      color:grey; 
      background:#fff;
    }
    .reason-text{
      font-family:'Roboto',sans-serif;
      font-size:13px;
      line-height:1.6;
      margin-top:6px;
      color:#333;
    }

    /* Buttons */
    .btn{
      font-family:'Roboto',sans-serif;
      appearance:none;
      border:none;
      cursor:pointer;
      border-radius:6px;
      padding:8px 14px;
      font-weight:500;            
      font-size:13px;
      display:inline-flex;
      align-items:center;
      gap:6px;
    }
    .btn-primary{background:var(--red-main);color:#fff;}
    .btn-ghost{background:#fff;color:#333;border:1px solid var(--gray-light);}
    .btn:hover{opacity:.9;}
  </style>
</head>
<body>

  <?php require 'navigator.html'; ?>
  <?php require 'filters.html'; ?>

  <main id="content">
    <div class="page">
      <h1>UNCONFIRMED</h1>
      <div class="subtitle">Review and confirm transactions that need manual verification.</div>

      <div class="layout">
        <section class="card">
          <table>
            <thead>
              <tr>
                <th>Reference Number</th>
                <th>Ordering Name</th>
                <th>Description</th>
                <th>Transaction Date</th>
                <th class="amount">Amount Paid</th>
              </tr>
            </thead>
            <tbody>
              <tr><td>1001</td><td>John Doe</td><td>Tuition Fee</td><td>10/01/2025</td><td class="amount">800.00 €</td></tr>
              <tr><td>1002</td><td>James Smith</td><td>Tuition Fee</td><td>22/06/2025</td><td class="amount">500.00 €</td></tr>
              <tr><td>1003</td><td>Michael Brown</td><td>Tuition Fee</td><td>30/04/2025</td><td class="amount">700.00 €</td></tr>
              <tr><td>1004</td><td>Emily Johnson</td><td>Tuition Fee</td><td>19/11/2025</td><td class="amount">1100.00 €</td></tr>
            </tbody>
          </table>
        </section>

        <aside class="stack">
          <div class="stats">
            <div class="stat">
              <div class="num">12</div>
              <div class="label">Pending</div>
            </div>
            <div class="stat">
              <div class="num">28</div>
              <div class="label">Confirmed</div>
            </div>
          </div>

          <div class="card">
            <div class="side-title">Suggested Student</div>
            <input class="side-input" type="text" value="David Wilson">
          </div>

          <div class="card">
            <div class="side-title">Suggested Legal Guardian</div>
            <input class="side-input" type="text" value="John Wilson">
          </div>

          <div class="card">
            <div class="side-title">Connection Reason</div>
            <p class="reason-text">Student and the suggested legal guardian have the same Last Name.</p>

            <div style="margin-top:12px;display:flex;gap:10px;">
              <button class="btn btn-primary"><span class="material-icons-outlined">check_circle</span> Confirm</button>
              <button class="btn btn-ghost"><span class="material-icons-outlined">edit</span> Edit</button>
            </div>
          </div>
        </aside>
      </div>
    </div>
  </main>

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
  </script>
</body>
</html>