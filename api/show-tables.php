<?php
require_once __DIR__ . '/db.php';
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = get_db_connection();
$res = $conn->query("SHOW TABLES");
$tables = [];
while($row = $res->fetch_row()) $tables[] = $row[0];
echo json_encode($tables);
?>
