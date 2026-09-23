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
    
    if (!isset($_GET['captain_name'])) {
        http_response_code(400);
        die(json_encode(['error' => 'captain_name parameter is required']));
    }
    
    $captain_name = $_GET['captain_name'];

    // 1. Get captain_cid from analytics_cards
    $stmt = $conn->prepare("SELECT cid FROM analytics_cards WHERE name = ? LIMIT 1");
    $stmt->bind_param("s", $captain_name);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $captain_cid = $row['cid'];
    } else {
        http_response_code(404);
        die(json_encode(['error' => 'Captain not found']));
    }
    $stmt->close();

    // 2. Query Card Performance for this Captain
    $q = "
        SELECT
            rc.card_cid,
            c.name as card_name,
            c.rarity,
            c.is_collectible,
            COUNT(rc.run_id) as games_played,
            SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(rc.run_id) * 100 as win_rate_top3
        FROM (
            SELECT run_id, card_cid
            FROM analytics_match_turns
            GROUP BY run_id, card_cid
        ) rc
        JOIN analytics_matches m ON rc.run_id = m.run_id
        LEFT JOIN analytics_cards c ON rc.card_cid = c.cid
        WHERE m.captain_cid = ? AND m.mmr >= ? AND m.mmr < ?
        GROUP BY rc.card_cid
        ORDER BY games_played DESC
    ";
    
    $stmt = $conn->prepare($q);
    $stmt->bind_param("iii", $captain_cid, $min_mmr, $max_mmr);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Cast numeric values correctly for frontend
        $row['card_cid'] = intval($row['card_cid']);
        $row['games_played'] = intval($row['games_played']);
        $row['win_rate_top3'] = floatval($row['win_rate_top3']);
        $row['is_collectible'] = $row['is_collectible'] !== null ? intval($row['is_collectible']) : 1;
        $data[] = $row;
    }
    
    // Get total matches for this captain in this MMR bracket
    $stmt2 = $conn->prepare("SELECT COUNT(*) as total FROM analytics_matches WHERE captain_cid = ? AND mmr >= ? AND mmr < ?");
    $stmt2->bind_param("iii", $captain_cid, $min_mmr, $max_mmr);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $total_matches = intval($res2->fetch_assoc()['total']);
    
    echo json_encode([
        'captain_name' => $captain_name,
        'min_mmr' => $min_mmr,
        'max_mmr' => $max_mmr,
        'total_matches' => $total_matches,
        'data' => $data
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>
