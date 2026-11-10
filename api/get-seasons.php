<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = get_db_connection();
    
    // Read distinct seasons from the tiny analytics_cache table (~30 rows) instead of scanning 550k+ rows in analytics_matches
    $seasons = ['all'];
    $cacheRes = $conn->query("SELECT DISTINCT season FROM analytics_cache WHERE season IS NOT NULL AND season != 'all' ORDER BY season DESC");
    if ($cacheRes) {
        while ($crow = $cacheRes->fetch_assoc()) {
            if (!empty($crow['season']) && !in_array($crow['season'], $seasons, true)) {
                $seasons[] = $crow['season'];
            }
        }
    }

    // Ensure current month is always included
    $currentMonth = gmdate('Y-m');
    if (!in_array($currentMonth, $seasons, true)) {
        array_splice($seasons, 1, 0, [$currentMonth]);
    }
    
    echo json_encode($seasons);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}
if (isset($conn)) $conn->close();
?>