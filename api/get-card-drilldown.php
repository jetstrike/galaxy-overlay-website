<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = get_db_connection();

    $min_mmr = isset($_GET['min_mmr']) ? intval($_GET['min_mmr']) : 0;
    $max_mmr = isset($_GET['max_mmr']) ? intval($_GET['max_mmr']) : 99999;
    $season = isset($_GET['season']) ? preg_replace('/[^0-9a-zA-Z-]/', '', $_GET['season']) : 'all';
    
    if (!isset($_GET['card_name'])) {
        http_response_code(400);
        die(json_encode(['error' => 'card_name parameter is required']));
    }
    
    $card_name = $_GET['card_name'];

    // 1. Get card_cid from analytics_cards
    $stmt = $conn->prepare("SELECT cid FROM analytics_cards WHERE name = ? LIMIT 1");
    $stmt->bind_param("s", $card_name);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $card_cid = intval($row['cid']);
    } else {
        http_response_code(404);
        die(json_encode(['error' => 'Card not found']));
    }
    $stmt->close();

    // Optional season filter
    $season_sql = "";
    $params = [$card_cid, $min_mmr, $max_mmr];
    $types = "iii";
    if ($season !== 'all' && $season !== '') {
        $season_sql = " AND DATE_FORMAT(m.date, '%Y-%m') = ? ";
        $params[] = $season;
        $types .= "s";
    }

    // 2. Query Captain Performance for this Card
    $q = "
        SELECT 
            m.captain_cid,
            c.name as captain_name,
            COUNT(m.run_id) as games_played,
            SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(m.run_id) * 100 as win_rate_top3,
            AVG(m.placement) as avg_placement
        FROM (
            SELECT DISTINCT run_id
            FROM analytics_match_turns
            WHERE card_cid = ?
        ) rc
        JOIN analytics_matches m ON rc.run_id = m.run_id
        LEFT JOIN analytics_cards c ON m.captain_cid = c.cid
        WHERE m.mmr >= ? AND m.mmr < ? AND m.captain_cid > 0 $season_sql
        GROUP BY m.captain_cid, c.name
        ORDER BY games_played DESC
    ";
    
    $stmt = $conn->prepare($q);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Cast numeric values correctly for frontend
        $row['captain_cid'] = intval($row['captain_cid']);
        $row['games_played'] = intval($row['games_played']);
        $row['win_rate_top3'] = floatval($row['win_rate_top3']);
        $row['avg_placement'] = floatval($row['avg_placement']);
        $data[] = $row;
    }
    $stmt->close();
    
    // Get total matches for this card in this MMR bracket
    $q2 = "
        SELECT COUNT(rc.run_id) as total 
        FROM (
            SELECT DISTINCT run_id 
            FROM analytics_match_turns 
            WHERE card_cid = ?
        ) rc 
        JOIN analytics_matches m ON rc.run_id = m.run_id 
        WHERE m.mmr >= ? AND m.mmr < ? $season_sql
    ";
    $stmt2 = $conn->prepare($q2);
    $stmt2->bind_param($types, ...$params);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $total_matches = intval($res2->fetch_assoc()['total']);
    $stmt2->close();
    
    echo json_encode([
        'card_name' => $card_name,
        'min_mmr' => $min_mmr,
        'max_mmr' => $max_mmr,
        'season' => $season,
        'total_matches' => $total_matches,
        'data' => $data
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>
