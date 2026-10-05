<?php
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
    
    $brackets = [
        [0, 99999],
        [0, 2500],
        [2500, 3000],
        [3000, 3500],
        [3500, 4000],
        [4000, 99999]
    ];
    
    $b_idx = isset($_GET['bracket']) ? intval($_GET['bracket']) : -1;
    
    if ($b_idx === -1) {
        // Trigger sync
        $sync_url = "https://galaxy-overlay.com/api/sync-analytics-db.php?limit=2500";
        $ctx = stream_context_create(['http' => ['timeout' => 20]]);
        $ch = curl_init($sync_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_TIMEOUT, 60); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); $sync_result = curl_exec($ch); curl_close($ch);
        echo "Sync complete.\n";
        
        // Output JS to redirect through all brackets
        echo "<html><body><script>";
        echo "let brackets = [0,1,2,3,4,5];";
        echo "async function run() {";
        echo "  for(let i=0; i<brackets.length; i++) {";
        echo "    document.body.innerHTML += 'Running bracket '+i+'...<br>';";
        echo "    let res = await fetch('?bracket='+i);";
        echo "    let text = await res.text();";
        echo "    document.body.innerHTML += text + '<br>';";
        echo "  }";
        echo "  document.body.innerHTML += 'ALL DONE!';";
        echo "}";
        echo "run();";
        echo "</script></body></html>";
        exit;
    }
    
    if ($b_idx >= 0 && $b_idx < count($brackets)) {
        $b = $brackets[$b_idx];
        $min_mmr = $b[0];
        $max_mmr = $b[1];
        $mmr_cond = "mmr >= $min_mmr AND mmr < $max_mmr";
        
        $res = $conn->query("SELECT COUNT(*) as total FROM analytics_matches WHERE $mmr_cond");
        $total_matches = intval($res->fetch_assoc()['total']);
        
        $queries = ['captains', 'cards'];
        
        foreach ($queries as $qt) {
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
        }
        echo "Bracket $b_idx done.";
    }
} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    echo "Error: " . $e->getMessage() . "\n";
}
if (isset($conn)) $conn->close();
?>
