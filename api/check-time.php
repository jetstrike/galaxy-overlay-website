<?php
require_once __DIR__ . '/db.php';
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = get_db_connection();
$res = $conn->query("SELECT last_updated FROM analytics_cache LIMIT 1");
echo $res->fetch_assoc()['last_updated'];
?>
