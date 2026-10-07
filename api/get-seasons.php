<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $res = $conn->query("SELECT DISTINCT season FROM analytics_cache ORDER BY season DESC");
    $seasons = [];
    while ($row = $res->fetch_assoc()) {
        $seasons[] = $row['season'];
    }
    echo json_encode($seasons);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}
if (isset($conn)) $conn->close();
?>
