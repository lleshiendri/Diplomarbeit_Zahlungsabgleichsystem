<?php
$servername = "buchhaltung.htl-projekt.com";
$username = "buchhaltungsql1";
$password = "0R3+tMyv";
$dbname = "buchhaltungsql1";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Verbindung fehlgeschlagen: " . $conn->connect_error);
}
?>