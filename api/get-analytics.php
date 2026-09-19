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

    $mmr_cond = "mmr >= $min_mmr AND mmr < $max_mmr";

    $response = [];
    $response['query_type'] = $query_type;
    $response['min_mmr'] = $min_mmr;
    $response['max_mmr'] = $max_mmr;

    // 1. Total records analyzed
    $res = $conn->query("SELECT COUNT(*) as total FROM analytics_matches WHERE $mmr_cond");
    $row = $res->fetch_assoc();
    $response['total_matches'] = intval($row['total']);

    if ($response['total_matches'] == 0) {
        $response['data'] = [];
        echo json_encode($response);
        exit;
    }

    if ($query_type === 'captains') {
        $query = "
            SELECT 
                m.captain_cid,
                c.name as captain_name,
                COUNT(m.run_id) as total_picks,
                (COUNT(m.run_id) / {$response['total_matches']}) * 100 as pick_rate,
                AVG(m.placement) as avg_placement,
                AVG(t.final_turn) as avg_turns,
                SUM(CASE WHEN m.placement = 1 THEN 1 ELSE 0 END) / COUNT(m.run_id) * 100 as win_rate_1st,
                SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(m.run_id) * 100 as win_rate_top3
            FROM analytics_matches m
            LEFT JOIN analytics_cards c ON m.captain_cid = c.cid
            LEFT JOIN (
                SELECT run_id, MAX(turn_number) as final_turn 
                FROM analytics_match_turns 
                GROUP BY run_id
            ) t ON m.run_id = t.run_id
            WHERE $mmr_cond AND m.captain_cid > 0
            GROUP BY m.captain_cid, c.name
            ORDER BY total_picks DESC
        ";
        $res = $conn->query($query);
        $data = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
        }
        $response['data'] = $data;
    } else {
        $response['error'] = 'Invalid query_type';
        $response['data'] = [];
    }

    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

$conn->close();
?>