<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
try {
    $conn = new mysqli($host, $user, $pass, $db);
    $res = $conn->query("SELECT type, COUNT(*) as count FROM analytics_cards GROUP BY type");
    while ($row = $res->fetch_assoc()) {
        print_r($row);
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
