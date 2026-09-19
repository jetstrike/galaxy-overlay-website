<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$servername = "localhost";
$username = "u834540789_Tracker";
$password = "Slippery1!1!";
$dbname = "u834540789_Galaxy";

try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = new mysqli($servername, $username, $password, $dbname);
} catch (Exception $e) {
    die(json_encode(["error" => "Database connection failed"]));
}

$sql = "UPDATE overlay_matches om 
        JOIN matches m ON om.run_id = m.run_id 
        SET om.captain = COALESCE(m.captain, m.captain_display), 
            om.date = m.date 
        WHERE om.date IS NULL OR om.captain IS NULL OR om.captain = '";

$conn->query($sql);
echo json_encode(["success" => true, "affected_rows" => $conn->affected_rows]);
$conn->close();
?>
