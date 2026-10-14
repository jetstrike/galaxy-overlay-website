<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

$brackets = [
    [0, 99999], [0, 2500], [2500, 3000], [3000, 3500], [3500, 4000], [4000, 99999]
];

function compute_and_cache_bracket($conn, $b_idx, $season, $brackets) {
    if ($b_idx < 0 || $b_idx >= count($brackets) || empty($season)) return;
    
    $b = $brackets[$b_idx];
    $min_mmr = $b[0];
    $max_mmr = $b[1];
    $mmr_cond = "m.mmr >= $min_mmr AND m.mmr < $max_mmr";
    
    $season_cond = "1=1";
    if ($season !== 'all') {
        $season_safe = $conn->real_escape_string($season);
        $season_cond = "DATE_FORMAT(m.date, '%Y-%m') = '$season_safe'";
    }
    
    $res = $conn->query("SELECT COUNT(*) as total FROM analytics_matches m WHERE $mmr_cond AND $season_cond");
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
        
        echo "Finished $qt for $season Bracket $b_idx ($min_mmr - $max_mmr): $total_matches matches\n";
    }
}

try {
    ignore_user_abort(true);
    set_time_limit(300);

    $conn = get_db_connection();
    
    // Ensure unique key exists on analytics_cache
    try {
        $conn->query("ALTER TABLE analytics_cache ADD UNIQUE KEY idx_cache_unique (query_type, season, min_mmr, max_mmr)");
    } catch (Throwable $e) {}

    $b_idx = isset($_GET['bracket']) ? intval($_GET['bracket']) : -1;
    $season = isset($_GET['season']) ? preg_replace('/[^0-9a-zA-Z-]/', '', $_GET['season']) : '';

    if ($b_idx === -1) {
        // 1. Trigger sync to ingest any new matches
        $sync_url = "https://galaxy-overlay.com/api/sync-analytics-db.php?limit=1000";
        $ch = curl_init($sync_url); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        $syncRes = curl_exec($ch); 
        curl_close($ch);
        echo "Sync result: " . $syncRes . "\n";

        // 2. Build active seasons directly (Current month + all-time)
        $current_season = date('Y-m');
        $active_seasons = [$current_season, 'all'];

        if (isset($_GET['all_seasons']) && $_GET['all_seasons'] == '1') {
            $res = $conn->query("SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as m FROM analytics_matches WHERE date IS NOT NULL");
            while ($r = $res->fetch_assoc()) {
                if (!empty($r['m']) && !in_array($r['m'], $active_seasons)) {
                    $active_seasons[] = $r['m'];
                }
            }
        }

        echo "Building cache for seasons: " . implode(', ', $active_seasons) . "\n";
        foreach ($active_seasons as $s) {
            for ($i = 0; $i < count($brackets); $i++) {
                compute_and_cache_bracket($conn, $i, $s, $brackets);
            }
        }
        echo "All cache jobs completed successfully!\n";
    } else {
        // Individual bracket execution
        compute_and_cache_bracket($conn, $b_idx, $season, $brackets);
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

if (isset($conn)) $conn->close();
?>
