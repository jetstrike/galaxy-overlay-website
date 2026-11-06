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

    $sorted_turns = array_keys($all_turns);
    sort($sorted_turns, SORT_NUMERIC);

    $captains_list = array_values($captains_map);

    echo json_encode([
        'success' => true,
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
