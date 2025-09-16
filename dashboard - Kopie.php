<?php
require 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Lora:wght@400;600&family=Open+Sans:wght@400&display=swap" rel="stylesheet">
  <style>
    body {
      margin: 0;
      font-family: 'Roboto', sans-serif;
      background-color: white;
      color: black;
    }

    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 15px 20px;
      background-color: #fae4d5;
      color: white;
    }

    .menu-icon {
      font-size: 26px;
      cursor: pointer;
      color: white;
    }

    .logo img {
      height: 100px; 
    }

    .sidebar {
      height: 100%;
      width: 0;
      position: fixed;
      top: 0;
      left: 0;
      background-color: #b31e32;
      overflow-x: hidden;
      transition: 0.3s;
      padding-top: 60px;
    }

    .sidebar h2 {
      font-family: 'Montserrat', sans-serif;
      color: white;
      text-align: center;
      margin-bottom: 20px;
    }

    .sidebar a {
      padding: 12px 20px;
      text-decoration: none;
      font-size: 16px;
      font-family: 'Poppins', sans-serif;
      color: #fae4d5;
      display: block;
      transition: 0.2s;
    }

    .main {
      margin: 20px;
      padding: 20px;
    }

    footer {
      background-color: #fae4d5;
      color: white;
      text-align: center;
      padding: 15px 10px;
      font-family: 'Poppins', sans-serif;
      position: fixed;
      bottom: 0;
      width: 100%;
    }

  </style>
</head>
<body>
  <div class="header">
    <span class="menu-icon" onclick="openSidebar()">&#9776;</span>
    <div class="logo">
      <img src="logo.png" alt="Logo">
    </div>
  </div>

  <div id="mySidebar" class="sidebar">
    <h2>
        <span style="float:right; cursor:pointer;" onclick="closeSidebar()">&times;</span>
    </h2>
    <a href="#">Add Payment</a>
    <a href="#">Add Transaction</a>
    <a href="#">Student State</a>
    <a href="#">Latencies</a>
    <a href="#">Import File</a>
    <a href="#">Unconfirmed</a>
    <a href="#">Connections</a>
    <a href="#">Help & Tutorial</a>
</div>

  <div class="main">
    <h1 style="font-family:'Montserrat',sans-serif;">Welcome, Esmerina!</h1>
  </div>

  <footer>
    © 2025 School Dashboard | Powered By HTL Shkodra 
  </footer>

<script>
  function openSidebar() {
  let sidebar = document.getElementById("mySidebar");
  sidebar.style.width = "250px";
}

  function closeSidebar() {
  document.getElementById("mySidebar").style.width = "0";
}
</script>
</body>
</html>