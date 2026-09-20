<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$res = $conn->query("SELECT turns FROM playfab_matches ORDER BY RAND() LIMIT 1000");
$runs = [];
while($row = $res->fetch_assoc()) $runs[] = $row['turns'];
echo json_encode($runs);
?>
