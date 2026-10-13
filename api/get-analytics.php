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
    $query_type = isset($_GET['query_type']) ? $_GET['query_type'] : 'captains';
    $season = isset($_GET['season']) ? preg_replace('/[^0-9a-zA-Z-]/', '', $_GET['season']) : 'all';

    $response = [];
    $response['query_type'] = $query_type;
    $response['season'] = $season;
    $response['min_mmr'] = $min_mmr;
    $response['max_mmr'] = $max_mmr;
    
    // 1. Check if cached data exists
    $stmt = $conn->prepare("SELECT total_matches, data_json FROM analytics_cache WHERE query_type = ? AND season = ? AND min_mmr = ? AND max_mmr = ? LIMIT 1");
    $stmt->bind_param("ssii", $query_type, $season, $min_mmr, $max_mmr);
    $stmt->execute();
    $res = $stmt->get_result();
    $cached = $res->fetch_assoc();
    $stmt->close();
    
    if ($cached && intval($cached['total_matches']) > 0 && !empty($cached['data_json']) && $cached['data_json'] !== '[]') {
        $response['total_matches'] = intval($cached['total_matches']);
        $response['data'] = json_decode($cached['data_json'], true);
        echo json_encode($response);
        if (isset($conn)) $conn->close();
        exit();
    }

    // 2. Fallback: Compute on-demand from TiDB if not cached or cache was empty
    $mmr_cond = "m.mmr >= $min_mmr AND m.mmr < $max_mmr";
    $season_cond = "1=1";
    if ($season !== 'all' && $season !== '') {
        $season_safe = $conn->real_escape_string($season);
        $season_cond = "DATE_FORMAT(m.date, '%Y-%m') = '$season_safe'";
    }

    $countRes = $conn->query("SELECT COUNT(*) as total FROM analytics_matches m WHERE $mmr_cond AND $season_cond");
    $total_matches = intval($countRes->fetch_assoc()['total']);

    $data = [];
    if ($total_matches > 0) {
        if ($query_type === 'captains') {
            $q = "
                SELECT 
                    m.captain_cid,
                    c.name as captain_name,
                    COUNT(m.run_id) as total_picks,
                    (COUNT(m.run_id) / $total_matches) * 100 as pick_rate,
                    AVG(m.placement) as avg_placement,
                    AVG(t.final_turn) as avg_turns,
                    SUM(CASE WHEN m.placement = 1 THEN 1 ELSE 0 END) / COUNT(m.run_id) * 100 as win_rate_1st,
                    SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(m.run_id) * 100 as win_rate_top3
                FROM analytics_matches m
                LEFT JOIN analytics_cards c ON m.captain_cid = c.cid
                LEFT JOIN (
                    SELECT t.run_id, MAX(t.turn_number) as final_turn 
                    FROM analytics_match_turns t
                    JOIN analytics_matches m2 ON t.run_id = m2.run_id
                    WHERE m2.mmr >= $min_mmr AND m2.mmr < $max_mmr AND " . str_replace('m.date', 'm2.date', $season_cond) . "
                    GROUP BY t.run_id
                ) t ON m.run_id = t.run_id
                WHERE $mmr_cond AND $season_cond AND m.captain_cid > 0
                GROUP BY m.captain_cid, c.name
                ORDER BY total_picks DESC
            ";
            $statRes = $conn->query($q);
            if ($statRes) {
                while ($row = $statRes->fetch_assoc()) {
                    $row['captain_cid'] = intval($row['captain_cid']);
                    $row['total_picks'] = intval($row['total_picks']);
                    $row['pick_rate'] = floatval($row['pick_rate']);
                    $row['avg_placement'] = floatval($row['avg_placement']);
                    $row['avg_turns'] = floatval($row['avg_turns']);
                    $row['win_rate_1st'] = floatval($row['win_rate_1st']);
                    $row['win_rate_top3'] = floatval($row['win_rate_top3']);
                    $data[] = $row;
                }
            }
        } else if ($query_type === 'cards') {
            $q = "
                SELECT
                    rc.card_cid,
                    c.name as card_name,
                    c.rarity,
                    c.is_collectible,
                    COUNT(rc.run_id) as games_played,
                    AVG(rc.turns_on_board) as avg_turns_on_board,
                    AVG(rc.first_appearance) as avg_first_appearance,
                    SUM(CASE WHEN m.placement = 1 THEN 1 ELSE 0 END) / COUNT(rc.run_id) * 100 as win_rate_1st,
                    SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(rc.run_id) * 100 as win_rate_top3
                FROM (
                    SELECT t.run_id, t.card_cid, MIN(t.turn_number) as first_appearance, COUNT(DISTINCT t.turn_number) as turns_on_board
                    FROM analytics_match_turns t
                    JOIN analytics_matches m2 ON t.run_id = m2.run_id
                    WHERE m2.mmr >= $min_mmr AND m2.mmr < $max_mmr AND " . str_replace('m.date', 'm2.date', $season_cond) . "
                    GROUP BY t.run_id, t.card_cid
                ) rc
                JOIN analytics_matches m ON rc.run_id = m.run_id
                LEFT JOIN analytics_cards c ON rc.card_cid = c.cid
                WHERE $mmr_cond AND $season_cond AND (c.type IS NULL OR c.type != 'captain')
                GROUP BY rc.card_cid, c.name, c.rarity, c.is_collectible
                ORDER BY games_played DESC
            ";
            $statRes = $conn->query($q);
            if ($statRes) {
                while ($row = $statRes->fetch_assoc()) {
                    $row['card_cid'] = intval($row['card_cid']);
                    $row['games_played'] = intval($row['games_played']);
                    $row['avg_turns_on_board'] = floatval($row['avg_turns_on_board']);
                    $row['avg_first_appearance'] = floatval($row['avg_first_appearance']);
                    $row['win_rate_1st'] = floatval($row['win_rate_1st']);
                    $row['win_rate_top3'] = floatval($row['win_rate_top3']);
                    $row['is_collectible'] = $row['is_collectible'] !== null ? intval($row['is_collectible']) : 1;
                    $data[] = $row;
                }
            }
        }
    }

    $data_json = json_encode($data);

    // Save into cache for instant subsequent hits
    try {
        $saveStmt = $conn->prepare("
            INSERT INTO analytics_cache (query_type, season, min_mmr, max_mmr, total_matches, data_json) 
            VALUES (?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
            total_matches = VALUES(total_matches), 
            data_json = VALUES(data_json),
            last_updated = CURRENT_TIMESTAMP
        ");
        $saveStmt->bind_param("ssiiis", $query_type, $season, $min_mmr, $max_mmr, $total_matches, $data_json);
        $saveStmt->execute();
        $saveStmt->close();
    } catch (Throwable $ignore) {}

    $response['total_matches'] = $total_matches;
    $response['data'] = $data;
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>