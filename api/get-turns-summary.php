<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

try {
    $conn = get_db_connection();

    // Fetch all turn records
    $query = "
        SELECT 
            t.captain_cid,
            t.turn_number,
            t.total_games,
            t.median_board_stats,
            t.avg_board_stats,
            t.p25_board_stats,
            t.p75_board_stats,
            t.p90_board_stats,
            t.max_board_stats,
            t.avg_slot_buffs,
            c.name
        FROM analytics_captain_turns t
        LEFT JOIN analytics_cards c ON t.captain_cid = c.cid
        ORDER BY t.captain_cid ASC, t.turn_number ASC
    ";
    
    $res = $conn->query($query);
    if (!$res) {
        throw new Exception("Query failed: " . $conn->error);
    }

    $captains_map = [];
    $all_turns = [];

    // Load cards.json for card_key and fallback resolution
    $cards_file = __DIR__ . '/cards.json';
    $cards_data = file_exists($cards_file) ? json_decode(file_get_contents($cards_file), true) : [];

    while ($row = $res->fetch_assoc()) {
        $cid = (int)$row['captain_cid'];
        $tNum = (int)$row['turn_number'];
        $all_turns[$tNum] = true;

        if (!isset($captains_map[$cid])) {
            $name = $row['name'];
            $card_key = '';

            if (isset($cards_data[$cid])) {
                if (empty($name)) $name = $cards_data[$cid]['name'] ?? ("Captain #" . $cid);
                $card_key = $cards_data[$cid]['card_key'] ?? '';
            }
            if (empty($card_key)) {
                $card_key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
            }

            $slug = $card_key;
            $captains_map[$cid] = [
                'cid' => $cid,
                'name' => $name,
                'card_key' => $card_key,
                'slug' => $slug,
                'total_matches' => 0,
                'turns' => []
            ];
        }

        $games = (int)$row['total_games'];
        if ($games > $captains_map[$cid]['total_matches']) {
            $captains_map[$cid]['total_matches'] = $games;
        }

        $captains_map[$cid]['turns'][$tNum] = [
            'games' => $games,
            'median' => (float)$row['median_board_stats'],
            'avg' => (float)$row['avg_board_stats'],
            'buffs' => (float)$row['avg_slot_buffs'],
            'p90' => (float)$row['p90_board_stats'],
            'max' => (float)$row['max_board_stats']
        ];
    }

    // Query true global total matches and per-captain pick counts from analytics_cache
    $global_total_matches = 0;
    $cache_stmt = $conn->prepare("SELECT total_matches, data_json FROM analytics_cache WHERE query_type = 'captains' AND season = 'all' AND min_mmr = 0 AND max_mmr = 99999 LIMIT 1");
    if ($cache_stmt) {
        $cache_stmt->execute();
        $cache_res = $cache_stmt->get_result()->fetch_assoc();
        if ($cache_res && !empty($cache_res['total_matches'])) {
            $global_total_matches = (int)$cache_res['total_matches'];
            if (!empty($cache_res['data_json'])) {
                $cache_caps = json_decode($cache_res['data_json'], true);
                if (is_array($cache_caps)) {
                    foreach ($cache_caps as $cc) {
                        $c_cid = (int)($cc['captain_cid'] ?? 0);
                        $c_picks = (int)($cc['total_picks'] ?? 0);
                        if ($c_cid > 0 && isset($captains_map[$c_cid])) {
                            $captains_map[$c_cid]['total_matches'] = $c_picks;
                        }
                    }
                }
            }
        }
        $cache_stmt->close();
    }

    // Fallback: If cache total was not found, query analytics_matches or playfab_matches
    if ($global_total_matches === 0) {
        $cnt_res = $conn->query("SELECT COUNT(*) as total FROM analytics_matches");
        if ($cnt_res && $row = $cnt_res->fetch_assoc()) {
            $global_total_matches = (int)$row['total'];
        }
        if ($global_total_matches === 0) {
            $cnt_res = $conn->query("SELECT COUNT(*) as total FROM playfab_matches");
            if ($cnt_res && $row = $cnt_res->fetch_assoc()) {
                $global_total_matches = (int)$row['total'];
            }
        }
    }

    $sorted_turns = array_keys($all_turns);
    sort($sorted_turns, SORT_NUMERIC);

    $captains_list = array_values($captains_map);

    echo json_encode([
        'success' => true,
        'global_total_matches' => $global_total_matches,
        'turns' => $sorted_turns,
        'total_captains' => count($captains_list),
        'captains' => $captains_list
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

if (isset($conn)) $conn->close();
?>
