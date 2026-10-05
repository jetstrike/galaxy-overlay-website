<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$c1 = $conn->query("SELECT COUNT(*) as c FROM playfab_matches")->fetch_assoc()['c'];
$c2 = $conn->query("SELECT COUNT(*) as c FROM overlay_matches")->fetch_assoc()['c'];
$c3 = $conn->query("SELECT COUNT(*) as c FROM analytics_matches")->fetch_assoc()['c'];
echo json_encode(['playfab' => $c1, 'overlay' => $c2, 'analytics' => $c3]);
?>
