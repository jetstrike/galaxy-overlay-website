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
    
    if (!isset($_GET['captain_name'])) {
        http_response_code(400);
        die(json_encode(['error' => 'captain_name parameter is required']));
    }
    
    $captain_name = $_GET['captain_name'];

    // 1. Get captain_cid from analytics_cards
    $captain_cid = null;
    $stmt = $conn->prepare("SELECT cid FROM analytics_cards WHERE name = ? LIMIT 1");
    $stmt->bind_param("s", $captain_name);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $captain_cid = intval($row['cid']);
    }
    $stmt->close();

    // Fallback to captain_map.json
    if (!$captain_cid) {
        $mapFile = __DIR__ . '/captain_map.json';
        if (file_exists($mapFile)) {
            $capMap = json_decode(file_get_contents($mapFile), true);
            if (isset($capMap[$captain_name])) {
                $captain_cid = intval($capMap[$captain_name]);
            }
        }
    }

    if (!$captain_cid) {
        http_response_code(404);
        die(json_encode(['error' => 'Captain not found']));
    }

    // Optional season filter
    $season_sql = "";
    $params = [$captain_cid, $min_mmr, $max_mmr];
    $types = "iii";
    if ($season !== 'all' && $season !== '') {
        $season_sql = " AND DATE_FORMAT(m.date, '%Y-%m') = ? ";
        $params[] = $season;
        $types .= "s";
    }

    // 2. Query Card Performance for this Captain
    $q = "
        SELECT
            rc.card_cid,
            c.name as card_name,
            c.rarity,
            c.is_collectible,
            COUNT(DISTINCT m.run_id) as games_played,
            COUNT(DISTINCT CASE WHEN m.placement <= 3 THEN m.run_id ELSE NULL END) / COUNT(DISTINCT m.run_id) * 100 as win_rate_top3
        FROM analytics_matches m
        JOIN analytics_match_turns rc ON rc.run_id = m.run_id
        LEFT JOIN analytics_cards c ON rc.card_cid = c.cid
        WHERE m.captain_cid = ? AND m.mmr >= ? AND m.mmr < ? $season_sql AND (c.type IS NULL OR c.type != 'captain')
        GROUP BY rc.card_cid, c.name, c.rarity, c.is_collectible
        ORDER BY games_played DESC
    ";
    
    $stmt = $conn->prepare($q);
    $stmt->bind_param($types, ...$params);
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
    $stmt->close();
    
    // Get total matches for this captain in this MMR bracket
    $q2 = "SELECT COUNT(*) as total FROM analytics_matches m WHERE m.captain_cid = ? AND m.mmr >= ? AND m.mmr < ? $season_sql";
    $stmt2 = $conn->prepare($q2);
    $stmt2->bind_param($types, ...$params);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $total_matches = intval($res2->fetch_assoc()['total']);
    $stmt2->close();
    
    echo json_encode([
        'captain_name' => $captain_name,
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
