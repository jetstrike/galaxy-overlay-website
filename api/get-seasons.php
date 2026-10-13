<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = get_db_connection();
    
    // Pull seasons directly from analytics_matches to ensure all active months are present
    $res = $conn->query("SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as s FROM analytics_matches WHERE date IS NOT NULL ORDER BY s DESC");
    $seasons = ['all'];
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['s'])) {
            $seasons[] = $row['s'];
        }
    }
    
    // Also include any cached seasons if different
    $cacheRes = $conn->query("SELECT DISTINCT season FROM analytics_cache WHERE season != 'all'");
    if ($cacheRes) {
        while ($crow = $cacheRes->fetch_assoc()) {
            if (!in_array($crow['season'], $seasons)) {
                $seasons[] = $crow['season'];
            }
        }
    }
    
    echo json_encode($seasons);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}
if (isset($conn)) $conn->close();
?>