<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);

$res = $conn->query("SELECT COUNT(*) as c FROM analytics_matches");
echo "Analytics matches: " . $res->fetch_assoc()['c'] . "\n";

$res = $conn->query("SELECT COUNT(*) as c FROM matches");
echo "Tracker matches: " . $res->fetch_assoc()['c'] . "\n";

$res = $conn->query("SELECT COUNT(*) as c FROM playfab_runs");
echo "PlayFab runs: " . $res->fetch_assoc()['c'] . "\n";
?>
