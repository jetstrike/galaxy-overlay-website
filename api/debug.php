<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$c3 = $conn->query("SELECT COUNT(*) as c FROM analytics_matches")->fetch_assoc()['c'];
echo json_encode(['analytics' => $c3]);
?>
