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
require "navigator.php"
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
                <tr>
                    <td>1001</td>
                    <td>John Doe</td>
                    <td>500.00 €</td>
                    <td>800.00 €</td>
                    <td>1300.00 €</td>
                    <td>10/01/2025</td>
                </tr>
                <tr>
                    <td>1002</td>
                    <td>James Smith</td>
                    <td>600.00 €</td>
                    <td>700.00 €</td>
                    <td>1300.00 €</td>
                    <td>06/04/2025</td>
                </tr>
                <tr>
                    <td>1003</td>
                    <td>Michael Brown</td>
                    <td>200.00 €</td>
                    <td>1100.00 €</td>
                    <td>1300.00 €</td>
                    <td>22/06/2025</td>
                </tr>
                <tr>
                    <td>1004</td>
                    <td>Emily Johnson</td>
                    <td>1300.00 €</td>
                    <td>0.00 €</td>
                    <td>1300.00 €</td>
                    <td>19/11/2025</td>
                </tr>
                <tr>
                    <td>1005</td>
                    <td>David Wilson</td>
                    <td>1300.00 €</td>
                    <td>0.00 €</td>
                    <td>1300.00 €</td>
                    <td>30/04/2025</td>
                </tr>
                <tr>
                    <td>1006</td>
                    <td>Christofer White</td>
                    <td>900.00 €</td>
                    <td>500.00 €</td>
                    <td>1300.00 €</td>
                    <td>15/09/2025</td>
                </tr>
                <tr>
                    <td>1007</td>
                    <td>Yuta Lee</td>
                    <td>400.00 €</td>
                    <td>900.00 €</td>
                    <td>1300.00 €</td>
                    <td>19/03/2025</td>
                </tr>
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


