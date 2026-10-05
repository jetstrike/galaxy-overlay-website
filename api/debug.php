<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$r = $conn->query("SELECT run_id FROM analytics_matches LIMIT 5");
while($row = $r->fetch_assoc()) {
    echo $row['run_id'] . "\n";
}
?>
