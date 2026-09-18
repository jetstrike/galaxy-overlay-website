<?php
$servername = "localhost";
$username = "u834540789_Tracker";
$password = "Slippery1!1!";
$dbname = "u834540789_Galaxy";

try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = new mysqli($servername, $username, $password, $dbname);
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        echo $row[0] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
