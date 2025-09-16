<?php
require 'db_connect.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
</head>
<body>
  <div class="sidebar">
    <h2>Esmerina Hoxha</h2>
    <a href="#">Add Payment</a>
    <a href="#">Add Transaction</a>
    <a href="#">Student State</a>
    <a href="#">Latencies</a>
    <a href="#">Import File</a>
    <a href="#">Unconfirmed</a>
    <a href="#">Connections</a>
    <a href="#">Help & Tutorial</a>
  </div>

  <div class="content">
    <h1>Hi Esmerina!</h1>
    <div class="cards">
      <div class="card"><h3>Number of Students</h3><p><?php echo $students; ?></p></div>
      <div class="card"><h3>Opened Transactions</h3><p>€ <?php echo $transactions; ?></p></div>
      <div class="card"><h3>Left to Pay</h3><p>€ <?php echo $left; ?></p></div>
      <div class="card"><h3>Reminders generated</h3><p><?php echo $reminders; ?></p></div>
    </div>

    <div class="widgets">
      <div class="widget">
        <h3>Last Transactions</h3>
        <?php
          $last = $conn->query("SELECT * FROM INVOICE_TAB ORDER BY created_at DESC LIMIT 5");
          if ($last) {
            echo "<ul>";
            while($row = $last->fetch_assoc()) {
              echo "<li>".$row['invoice_no']." - € ".$row['amount']."</li>";
            }
            echo "</ul>";
          } else {
            echo "<p>No transactions found.</p>";
          }
        ?>
      </div>
      <div class="widget">
        <h3>Students</h3>
        <table>
          <tr><th>Name</th><th>Class</th><th>Contact</th><th>Payment</th></tr>
          <?php
            $stu = $conn->query("SELECT s.name, s.class, l.email, i.amount FROM STUDENT_TAB s LEFT JOIN LEGAL_GUARDIAN_STUDENT_TAB ls ON s.id = ls.student_id LEFT JOIN LEGAL_GUARDIAN_TAB l ON ls.guardian_id = l.id LEFT JOIN INVOICE_TAB i ON s.id = i.student_id LIMIT 5");
            if ($stu) {
              while($row = $stu->fetch_assoc()) {
                echo "<tr>
                        <td>".$row['name']."</td>
                        <td>".$row['class']."</td>
                        <td>".$row['email']."</td>
                        <td>€ ".$row['amount']."</td>
                      </tr>";
              }
            } else {
              echo "<tr><td colspan='4'>No students found.</td></tr>";
            }
          ?>
        </table>
      </div>
    </div>
  </div>
</body>
</html>