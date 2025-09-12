<?php
$servername = "endlle19_diplomarbeit_probe_server";
$username = "endlle19 ";
$password = "1INSY$data";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Verbindung fehlgeschlagen: " . $conn->connect_error);
}
?>
