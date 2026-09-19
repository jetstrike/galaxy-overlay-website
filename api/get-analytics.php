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

    $min_mmr = isset($_GET['min_mmr']) ? intval($_GET['min_mmr']) : 0;
    $max_mmr = isset($_GET['max_mmr']) ? intval($_GET['max_mmr']) : 99999;
    $query_type = isset($_GET['query_type']) ? $_GET['query_type'] : 'captains';

    $response = [];
    $response['query_type'] = $query_type;
    $response['min_mmr'] = $min_mmr;
    $response['max_mmr'] = $max_mmr;
    
    $stmt = $conn->prepare("SELECT total_matches, data_json FROM analytics_cache WHERE query_type = ? AND min_mmr = ? AND max_mmr = ?");
    $stmt->bind_param("sii", $query_type, $min_mmr, $max_mmr);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $response['total_matches'] = intval($row['total_matches']);
        $response['data'] = json_decode($row['data_json'], true);
    } else {
        $response['total_matches'] = 0;
        $response['data'] = [];
    }
    
    $stmt->close();
    
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>