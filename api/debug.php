<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$res = $conn->query("SELECT COUNT(*) as c FROM overlay_matches WHERE run_id NOT IN (SELECT run_id FROM analytics_matches)");
$c = $res->fetch_assoc()['c'];
echo "Unsynced overlay matches: " . $c;
?>
