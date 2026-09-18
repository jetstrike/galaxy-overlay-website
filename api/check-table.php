<?php
$servername = "localhost";
$username = "u834540789_Tracker";
$password = "Slippery1!1!";
$dbname = "u834540789_Galaxy";

try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    $result = $conn->query("SELECT COUNT(*) as count FROM overlay_matches");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "Rows in overlay_matches: " . $row['count'] . "\n";
    } else {
        echo "Query failed.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
