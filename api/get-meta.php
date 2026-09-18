<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

// 1. Setup Database Connection Details
$servername = "localhost"; // Usually localhost on Hostinger
$username = "u834540789_Tracker";
$password = "Slippery1!1!"; // Same as submit-match.php
$dbname = "u834540789_Galaxy";

// 2. Connect to MySQL
try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = new mysqli($servername, $username, $password, $dbname);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed", "details" => $e->getMessage()]));
}

// 3. Get Total Matches
$total_result = $conn->query("SELECT COUNT(*) as total_matches FROM matches");
$total_matches = $total_result->fetch_assoc()['total_matches'];

// 4. Get Captain Stats
$sql = "
SELECT 
    captain_name,
    COUNT(*) as games_played,
    AVG(placement) as avg_placement,
    SUM(CASE WHEN placement = 1 THEN 1 ELSE 0 END) as first_place,
    SUM(CASE WHEN placement <= 3 THEN 1 ELSE 0 END) as top_3
FROM matches
GROUP BY captain_name
ORDER BY games_played DESC
";
$stats_result = $conn->query($sql);

$captains = [];
if ($stats_result) {
    while ($row = $stats_result->fetch_assoc()) {
        $count = (int)$row['games_played'];
        $row['games_played'] = $count;
        $row['avg_placement'] = round((float)$row['avg_placement'], 2);
        
        // Calculate Rates
        $row['pick_rate'] = round(($count / max(1, $total_matches)) * 100, 2);
        $row['win_rate'] = round(((int)$row['first_place'] / max(1, $count)) * 100, 1);
        $row['top_3_rate'] = round(((int)$row['top_3'] / max(1, $count)) * 100, 1);
        
        $captains[] = $row;
    }
}

// 5. Output JSON
echo json_encode([
    "total_matches" => (int)$total_matches,
    "captains" => $captains,
    "updated_at" => date('c')
]);

$conn->close();
?>
