<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);

function file_log($msg) {
    file_put_contents('analytics-build.log', date('Y-m-d H:i:s') . ' - ' . $msg . \"\n\", FILE_APPEND);
}

file_log('Started build-analytics.php');

$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = get_db_connection();
    file_log('Connected to db');

    $brackets = [
        [0, 99999],
        [0, 2500],
        [2500, 3000],
        [3000, 3500],
        [3500, 4000],
        [4000, 99999]
    ];
    $queries = ['captains', 'cards'];

    foreach ($brackets as $b) {
        $min_mmr = $b[0];
        $max_mmr = $b[1];
        $mmr_cond = "mmr >= $min_mmr AND mmr < $max_mmr";

        $res = $conn->query("SELECT COUNT(*) as total FROM analytics_matches WHERE $mmr_cond");
        $total_matches = intval($res->fetch_assoc()['total']);
        file_log("Total for $min_mmr-$max_mmr: $total_matches");

        foreach ($queries as $qt) {
            file_log("Building $qt for $min_mmr-$max_mmr...");
            $data = [];
            if ($total_matches > 0) {
                if ($qt === 'captains') {
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
                            SELECT run_id, MAX(turn_number) as final_turn 
                            FROM analytics_match_turns 
                            GROUP BY run_id
                        ) t ON m.run_id = t.run_id
                        WHERE $mmr_cond AND m.captain_cid > 0
                        GROUP BY m.captain_cid, c.name
                        ORDER BY total_picks DESC
                    ";
                    $res = $conn->query($q);
                    while ($row = $res->fetch_assoc()) { $data[] = $row; }
                } else if ($qt === 'cards') {
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
                            SELECT run_id, card_cid, MIN(turn_number) as first_appearance, COUNT(DISTINCT turn_number) as turns_on_board
                            FROM analytics_match_turns
                            GROUP BY run_id, card_cid
                        ) rc
                        JOIN analytics_matches m ON rc.run_id = m.run_id
                        LEFT JOIN analytics_cards c ON rc.card_cid = c.cid
                        WHERE $mmr_cond AND c.type != 'captain'
                        GROUP BY rc.card_cid, c.name, c.rarity, c.is_collectible
                        ORDER BY games_played DESC
                    ";
                    $res = $conn->query($q);
                    while ($row = $res->fetch_assoc()) { $data[] = $row; }
                }
            }
            $data_json = json_encode($data);
            file_log("Finished query $qt for $min_mmr-$max_mmr. Rows: " . count($data));
            
            $stmt = $conn->prepare("
                INSERT INTO analytics_cache (query_type, min_mmr, max_mmr, total_matches, data_json) 
                VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                total_matches = VALUES(total_matches), 
                data_json = VALUES(data_json),
                last_updated = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param("siiis", $qt, $min_mmr, $max_mmr, $total_matches, $data_json);
            $stmt->execute();
            $stmt->close();
            file_log("Inserted into cache $qt for $min_mmr-$max_mmr.");
        }
    }
    file_log('Finished all brackets');
} catch (Throwable $e) {
    file_log('Error: ' . $e->getMessage());
}
if (isset($conn)) $conn->close();
?>
