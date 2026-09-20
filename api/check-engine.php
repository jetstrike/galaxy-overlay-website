<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$res = $conn->query("SHOW TABLE STATUS WHERE Name = 'analytics_cache'");
echo json_encode($res->fetch_assoc());
?>
