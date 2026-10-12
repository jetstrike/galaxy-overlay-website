<?php
require_once __DIR__ . '/db.php';
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
try {
    $conn = get_db_connection();
    $res = $conn->query("DESCRIBE analytics_cards");
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
