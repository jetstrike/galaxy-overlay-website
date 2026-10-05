<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$res = $conn->query("SELECT run_id, date, mmr_start, mmr, placement, captain_cid, rank, rules_version FROM overlay_matches WHERE captain_cid=4028 LIMIT 5");
$overlay = [];
while($r = $res->fetch_assoc()) $overlay[] = $r;

$res = $conn->query("SELECT run_id FROM analytics_matches WHERE captain_cid=4028 LIMIT 5");
$analytics = [];
while($r = $res->fetch_assoc()) $analytics[] = $r;

echo json_encode(['overlay' => $overlay, 'analytics' => $analytics], JSON_PRETTY_PRINT);
?>
