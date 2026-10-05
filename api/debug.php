<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$c = $conn->query("SELECT COUNT(*) as c, COUNT(DISTINCT run_id) as d FROM analytics_matches")->fetch_assoc();
echo json_encode($c);
?>
