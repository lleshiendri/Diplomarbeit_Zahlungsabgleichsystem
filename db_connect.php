<?php
$servername = "buchhaltung.htl-projekt.com";
$username = "buchhaltungsql1";
$password = "0R3+tMyv";
$dbname = "buchhaltungsql1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Verbindung fehlgeschlagen: " . $conn->connect_error);
}

//Alle Schueler zaehlen
$sql = "SELECT COUNT(*) AS total_students FROM STUDENT_TAB";
$result_students = $conn->query($sql);
$students = ($result_students && $row = $result_students->fetch_assoc()) ? $row['total_students'] : 0;

//Transaktionen summieren
$sql = "SELECT SUM(amount) AS total_transactions FROM INVOICE_TAB";
$result_transactions = $conn->query($sql);
$transactions = ($result_transactions && $row = $result_transactions->fetch_assoc()) ? $row['total_transactions'] : 0;

//Offene Zahlungen
$sql = "SELECT SUM(amount - paid_amount) AS left_to_pay FROM INVOICE_TAB";
$result_left = $conn->query($sql);
$left = ($result_left && $row = $result_left->fetch_assoc()) ? $row['left_to_pay'] : 0;

//Erinnerung
$sql = "SELECT COUNT(*) AS reminders FROM EMAIL_LOGS_TAB";
$result_reminders = $conn->query($sql);
$reminders = ($result_reminders && $row = $result_reminders->fetch_assoc()) ? $row['reminders'] : 0;
?>