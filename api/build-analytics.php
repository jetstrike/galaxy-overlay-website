<?php
// build-analytics.php - Intended to be run via Cron Job
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = new mysqli($host, $user, $pass, $db);
    echo "Connected to database.\n";

    $brackets = [
        [0, 99999],
        [0, 2500],
        [2500, 3000],
        [3000, 3500],
        [3500, 4000],
        [4000, 99999]
    ];

    $queries = ['captains'];

    $conn->begin_transaction();

    foreach ($brackets as $b) {
        $min_mmr = $b[0];
        $max_mmr = $b[1];
        $mmr_cond = "mmr >= $min_mmr AND mmr < $max_mmr";

        // Get total matches for this bracket
        $res = $conn->query("SELECT COUNT(*) as total FROM analytics_matches WHERE $mmr_cond");
        $total_matches = intval($res->fetch_assoc()['total']);

        foreach ($queries as $qt) {
            echo "Building $qt for $min_mmr - $max_mmr (Total: $total_matches)... ";
            
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
                    if ($res) {
                        while ($row = $res->fetch_assoc()) {
                            $data[] = $row;
                        }
                    }
                }
            }

            $data_json = json_encode($data);
            
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

            echo "Done.\n";
        }
    }

    $conn->commit();
    echo "All caches built successfully!\n";

} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    echo "Error: " . $e->getMessage() . "\n";
}

if (isset($conn)) $conn->close();
?>