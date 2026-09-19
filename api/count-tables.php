<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

$conn = new mysqli($host, $user, $pass, $db);
$res = $conn->query('SHOW TABLES');
while ($row = $res->fetch_array()) {
    $table = $row[0];
    $count = $conn->query("SELECT COUNT(*) as c FROM $table")->fetch_assoc()['c'];
    echo $table . ': ' . $count . "\n";
}
$conn->close();
?>