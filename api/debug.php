<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$res = $conn->query("DESCRIBE analytics_matches");
$out = [];
while($r = $res->fetch_assoc()) $out[] = $r;
echo json_encode($out, JSON_PRETTY_PRINT);
?>
