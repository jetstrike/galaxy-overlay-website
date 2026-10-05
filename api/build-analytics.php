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
        [0, 99999], [0, 2500], [2500, 3000], [3000, 3500], [3500, 4000], [4000, 99999]
    ];
    
    $b_idx = isset($_GET['bracket']) ? intval($_GET['bracket']) : -1;
    $season = isset($_GET['season']) ? preg_replace('/[^0-9a-zA-Z-]/', '', $_GET['season']) : '';
    
    if ($b_idx === -1) {
        // Master process: Trigger sync
        $sync_url = "https://galaxy-overlay.com/api/sync-analytics-db.php?limit=2500";
        $ctx = stream_context_create(['http' => ['timeout' => 20]]);
        $ch = curl_init($sync_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_TIMEOUT, 60); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
        echo "Sync complete.\n";
        
        // Find all seasons (months) that have data
        $seasons = ['all'];
        $res = $conn->query("SELECT DATE_FORMAT(date, '%Y-%m') as m FROM analytics_matches GROUP BY m");
        while($r = $res->fetch_assoc()) {
            if ($r['m']) $seasons[] = $r['m'];
        }
        
        // Use PHP async curl to trigger all season/bracket combinations
        $mh = curl_multi_init();
        $handles = [];
        
        foreach ($seasons as $s) {
            for ($i = 0; $i < count($brackets); $i++) {
                $url = "https://galaxy-overlay.com/api/build-analytics.php?bracket=$i&season=$s";
                $ch = curl_init($url);
                // Set timeout to 1 second so it doesn't wait for completion (fire and forget)
                curl_setopt($ch, CURLOPT_TIMEOUT, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_multi_add_handle($mh, $ch);
                $handles[] = $ch;
                echo "Triggered $s - Bracket $i\n";
            }
        }
        
        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        
        foreach($handles as $ch) { curl_multi_remove_handle($mh, $ch); }
        curl_multi_close($mh);
        
        echo "All cache jobs triggered in background!";
        exit;
    }
    
    if ($b_idx >= 0 && $b_idx < count($brackets) && $season !== '') {
        ignore_user_abort(true);
        set_time_limit(0);
        
        $b = $brackets[$b_idx];
        $min_mmr = $b[0];
        $max_mmr = $b[1];
        $mmr_cond = "mmr >= $min_mmr AND mmr < $max_mmr";
        
        $season_cond = "1=1";
        if ($season !== 'all') {
            $season_cond = "DATE_FORMAT(date, '%Y-%m') = '$season'";
        }
        
        $res = $conn->query("SELECT COUNT(*) as total FROM analytics_matches WHERE $mmr_cond AND $season_cond");
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
                        WHERE $mmr_cond AND $season_cond AND m.captain_cid > 0
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
                        WHERE $mmr_cond AND $season_cond AND c.type != 'captain'
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
                INSERT INTO analytics_cache (query_type, season, min_mmr, max_mmr, total_matches, data_json) 
                VALUES (?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                total_matches = VALUES(total_matches), 
                data_json = VALUES(data_json),
                last_updated = CURRENT_TIMESTAMP
            ");
            
            $stmt->bind_param("ssiiis", $qt, $season, $min_mmr, $max_mmr, $total_matches, $data_json);
            $stmt->execute();
            $stmt->close();
        }
        echo "Bracket $b_idx done for $season.";
    }
} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    echo "Error: " . $e->getMessage() . "\n";
}
if (isset($conn)) $conn->close();
?>
