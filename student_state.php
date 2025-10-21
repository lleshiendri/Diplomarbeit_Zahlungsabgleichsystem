<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student State</title>
    <link rel="stylesheet" href="student_state_style.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
</head>
<body>

<?php
require "navigator.php";
require "db_connect.php";
?>
<div class="content">
    <h1 class="page-title">Student State</h1>

    <div class="table-wrapper">
        <table class="student-table">
            <thead>
                <tr>
                    <th>Student ID</th>
                    <th>Student Name</th>
                    <th>Amount Paid</th>
                    <th>Left to Pay</th>
                    <th>Total Amount</th>
                    <th>Last Transaction</th>
                </tr>
            </thead>
            <tbody>
                 <?php
            // Fetch students from database
            $result = $conn->query("
                SELECT extern_key AS student_id, long_name AS student_name
                FROM STUDENT_TAB
                ORDER BY id ASC
            ");

            if ($result && $result->num_rows > 0) {

                while ($row = $result->fetch_assoc()) {
                    // Generate mock data
                    $totalAmount = 1300;
                    $amountPaid  = rand(0, $totalAmount);
                    $leftToPay   = $totalAmount - $amountPaid;

                    // Generate a random mock date between Jan–Nov 2025
                    $timestamp = mt_rand(strtotime('2025-01-01'), strtotime('2025-11-30'));
                    $mockDate  = date('d/m/Y', $timestamp);

                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($row['student_id']) . '</td>';
                    echo '<td>' . htmlspecialchars($row['student_name']) . '</td>';
                    echo '<td>' . number_format($amountPaid, 2, ',', '.') . ' €</td>';
                    echo '<td>' . number_format($leftToPay, 2, ',', '.') . ' €</td>';
                    echo '<td>' . number_format($totalAmount, 2, ',', '.') . ' €</td>';
                    echo '<td>' . htmlspecialchars($mockDate) . '</td>';
                    echo '</tr>';
                }
            } else {
                echo '<tr><td colspan="6" style="text-align:center; color:#888;">No students found</td></tr>';
            }
            ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const sidebar   = document.getElementById("sidebar");
    const content   = document.getElementById("content");
    const overlay   = document.getElementById("overlay");

    function openSidebar(){
         sidebar.classList.add("open"); 
         content.classList.add("shifted"); 
         overlay.classList.add("show"); 
        }
    function closeSidebar(){
         sidebar.classList.remove("open"); 
         content.classList.remove("shifted"); 
         overlay.classList.remove("show"); 
        }
    function toggleSidebar(){ 
         sidebar.classList.contains("open") ? closeSidebar() : openSidebar(); 
         }
</script>
</body>
</html>


