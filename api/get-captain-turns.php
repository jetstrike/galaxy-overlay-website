<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

if (!isset($_GET['captain']) && !isset($_GET['cid'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Missing captain identifier']));
}

$capParam = isset($_GET['cid']) ? trim($_GET['cid']) : trim($_GET['captain']);

try {
    $conn = get_db_connection();

    // Load cards.json
    $cards_file = __DIR__ . '/cards.json';
    $cards_data = file_exists($cards_file) ? json_decode(file_get_contents($cards_file), true) : [];

    $cid_to_card = [];
    $target_cid = null;
    $target_name = null;
    $target_card_key = null;

    foreach ($cards_data as $k => $c) {
        if (!is_array($c)) continue;
        $c_cid = isset($c['cid']) ? (int)$c['cid'] : (is_numeric($k) ? (int)$k : null);
        if ($c_cid !== null) {
            $cid_to_card[$c_cid] = $c;
        }

        $is_cap = (isset($c['card_type']) && $c['card_type'] === 'captain') || (isset($c['type']) && strtolower($c['type']) === 'captain');
        if ($is_cap && $c_cid !== null) {
            $c_name = $c['name'] ?? '';
            $c_key = $c['card_key'] ?? strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $c_name));
            $c_slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $c_name));

            if ((string)$c_cid === $capParam || 
                strtolower($c_name) === strtolower($capParam) || 
                strtolower($c_key) === strtolower($capParam) || 
                strtolower($c_slug) === strtolower($capParam)) {
                $target_cid = $c_cid;
                $target_name = $c_name;
                $target_card_key = $c_key;
            }
        }
    }

    if ($target_cid === null) {
        // Fallback: search in analytics_cards table
        $stmt = $conn->prepare("SELECT cid, name FROM analytics_cards WHERE type = 'captain' AND (cid = ? OR name = ?)");
        $intParam = is_numeric($capParam) ? (int)$capParam : 0;
        $stmt->bind_param("is", $intParam, $capParam);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $target_cid = (int)$row['cid'];
            $target_name = $row['name'];
            $target_card_key = isset($cards_data[$target_cid]['card_key']) ? $cards_data[$target_cid]['card_key'] : strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $target_name));
        }
        $stmt->close();
    }

    if ($target_cid === null) {
        http_response_code(404);
        die(json_encode(['success' => false, 'error' => 'Captain not found']));
    }

    $target_slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $target_name));

    // 1. Fetch multi-turn macro stats and slot aggregates from analytics_captain_turns
    $summary = [];
    $available_turns = [];
    $total_matches_analyzed = 0;

    $stmt = $conn->prepare("
        SELECT turn_number, total_games, median_board_stats, avg_board_stats, 
               p25_board_stats, p75_board_stats, p90_board_stats, max_board_stats, 
               avg_slot_buffs, slot_details_json 
        FROM analytics_captain_turns 
        WHERE captain_cid = ? 
        ORDER BY turn_number ASC
    ");
    $stmt->bind_param("i", $target_cid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $tNum = (int)$row['turn_number'];
        $available_turns[] = $tNum;
        $tGames = (int)$row['total_games'];
        if ($tGames > $total_matches_analyzed) {
            $total_matches_analyzed = $tGames;
        }

        $slots = [];
        if (!empty($row['slot_details_json'])) {
            $slots = json_decode($row['slot_details_json'], true) ?: [];
        }

        $summary[$tNum] = [
            'total_games' => $tGames,
            'board_stats' => [
                'median_total_stats' => (float)$row['median_board_stats'],
                'avg_total_stats' => (float)$row['avg_board_stats'],
                'p25_total_stats' => (float)$row['p25_board_stats'],
                'p75_total_stats' => (float)$row['p75_board_stats'],
                'p90_total_stats' => (float)$row['p90_board_stats'],
                'max_total_stats' => (float)$row['max_board_stats'],
                'avg_board_slot_buffs_total' => (float)$row['avg_slot_buffs']
            ],
            'slots' => $slots
        ];
    }
    $stmt->close();

    // Fetch true total matches for this captain from analytics_cache or fallback
    $c_stmt = $conn->prepare("SELECT data_json FROM analytics_cache WHERE query_type = 'captains' AND season = 'all' AND min_mmr = 0 AND max_mmr = 99999 LIMIT 1");
    if ($c_stmt) {
        $c_stmt->execute();
        $c_row = $c_stmt->get_result()->fetch_assoc();
        if ($c_row && !empty($c_row['data_json'])) {
            $c_list = json_decode($c_row['data_json'], true);
            if (is_array($c_list)) {
                foreach ($c_list as $c_item) {
                    if ((int)($c_item['captain_cid'] ?? 0) === $target_cid) {
                        $real_picks = (int)($c_item['total_picks'] ?? 0);
                        if ($real_picks > $total_matches_analyzed) {
                            $total_matches_analyzed = $real_picks;
                        }
                        break;
                    }
                }
            }
        }
        $c_stmt->close();
    }

    if ($total_matches_analyzed <= 1500) {
        $cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM playfab_matches WHERE captain_cid = ?");
        if ($cnt_stmt) {
            $cnt_stmt->bind_param("i", $target_cid);
            $cnt_stmt->execute();
            $cnt_row = $cnt_stmt->get_result()->fetch_row();
            if ($cnt_row && (int)$cnt_row[0] > $total_matches_analyzed) {
                $total_matches_analyzed = (int)$cnt_row[0];
            }
            $cnt_stmt->close();
        }
    }

    // Harmonize turn total_games with $total_matches_analyzed so Turn 1 matches header badge exactly
    $max_summary_games = 0;
    foreach ($summary as $tNum => $tData) {
        if ($tData['total_games'] > $max_summary_games) {
            $max_summary_games = $tData['total_games'];
        }
    }
    if ($total_matches_analyzed > $max_summary_games && $max_summary_games > 0) {
        $scale_factor = $total_matches_analyzed / $max_summary_games;
        foreach ($summary as $tNum => &$tData) {
            $tData['total_games'] = (int)round($tData['total_games'] * $scale_factor);
        }
        unset($tData);
    }

    // 2. Helper functions for parsing match turns
    function parse_buffs_php($buff_obj) {
        $atk = 0;
        $hp = 0;
        if (empty($buff_obj)) return [$atk, $hp];
        $buff_list = is_array($buff_obj) ? (isset($buff_obj[0]) ? $buff_obj : array_values($buff_obj)) : [];
        foreach ($buff_list as $b) {
            if (is_array($b)) {
                $atk += (int)($b['attack'] ?? 0);
                $hp += (int)($b['health'] ?? 0);
            }
        }
        return [$atk, $hp];
    }

    // 3. Fetch recent sample matches from playfab_matches
    $games = [];
    $units_map = [];

    $stmt = $conn->prepare("
        SELECT run_id, player_name, placement, mmr, date, turns 
        FROM playfab_matches USE INDEX (idx_playfab_captain)
        WHERE captain_cid = ? AND turns IS NOT NULL 
        ORDER BY date DESC 
        LIMIT 60
    ");
    $stmt->bind_param("i", $target_cid);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $turns_raw = json_decode($row['turns'], true);
        if (!is_array($turns_raw)) continue;

        $match_turns = [];
        $is_list = isset($turns_raw[0]);

        foreach ($turns_raw as $k => $t_obj) {
            if (!is_array($t_obj)) continue;
            $tNum = $is_list ? ((int)$k + 1) : (int)$k;

            $crew = $t_obj['crew'] ?? [];
            $crewslots = $t_obj['crewslots'] ?? [];

            $crew_map = [];
            if (is_array($crew)) {
                if (isset($crew[0])) {
                    foreach ($crew as $i => $v) $crew_map[(string)($i + 1)] = $v;
                } else {
                    $crew_map = $crew;
                }
            }

            $slot_map = [];
            if (is_array($crewslots)) {
                if (isset($crewslots[0])) {
                    foreach ($crewslots as $i => $v) $slot_map[(string)($i + 1)] = $v;
                } else {
                    $slot_map = $crewslots;
                }
            }

            $slots_arr = [];
            $turn_total_stats = 0;
            $turn_slot_buffs_total = 0;

            for ($s_idx = 1; $s_idx <= 5; $s_idx++) {
                $s_key = (string)$s_idx;
                $c_info = $crew_map[$s_key] ?? [];
                $s_info = $slot_map[$s_key] ?? [];

                $card_cid = isset($c_info['cid']) ? (int)$c_info['cid'] : null;
                $card_def = $card_cid !== null && isset($cid_to_card[$card_cid]) ? $cid_to_card[$card_cid] : null;
                $tier = isset($c_info['tier']) ? max(1, (int)$c_info['tier']) : 1;

                if ($card_def) {
                    $u_name = $card_def['name'] ?? ('CID ' . $card_cid);
                    $u_key = $card_def['card_key'] ?? '';
                    $base_atk = ((int)($card_def['attack'] ?? 0)) * $tier;
                    $base_hp = ((int)($card_def['health'] ?? 0)) * $tier;
                    $units_map[$card_cid] = ['name' => $u_name, 'card_key' => $u_key];
                } elseif ($card_cid !== null && $card_cid > 0) {
                    $u_name = 'Unknown #' . $card_cid;
                    $base_atk = 1 * $tier;
                    $base_hp = 1 * $tier;
                    $units_map[$card_cid] = ['name' => $u_name, 'card_key' => ''];
                } else {
                    $u_name = 'Empty Slot';
                    $base_atk = 0;
                    $base_hp = 0;
                }

                list($u_atk, $u_hp) = parse_buffs_php($c_info['buffs'] ?? null);
                list($s_atk, $s_hp) = parse_buffs_php($s_info['buffs'] ?? null);

                $final_atk = $base_atk + $u_atk + $s_atk;
                $final_hp = $base_hp + $u_hp + $s_hp;
                $total_slot_stats = $final_atk + $final_hp;
                $slot_buff_total = $s_atk + $s_hp;

                $turn_total_stats += $total_slot_stats;
                $turn_slot_buffs_total += $slot_buff_total;

                // Compact slot tuple: [cid, tier, slot_atk, slot_hp, final_atk, final_hp, total_slot_stats]
                $slots_arr[] = [
                    $card_cid !== null ? $card_cid : 0,
                    $tier,
                    $s_atk,
                    $s_hp,
                    $final_atk,
                    $final_hp,
                    $total_slot_stats
                ];
            }

            $match_turns[(string)$tNum] = [
                'total_stats' => $turn_total_stats,
                'slot_buffs' => $turn_slot_buffs_total,
                'slots' => $slots_arr
            ];
        }

        $games[] = [
            'run_id' => $row['run_id'],
            'player' => $row['player_name'] ?: 'Anonymous Player',
            'placement' => $row['placement'] !== null ? (int)$row['placement'] : null,
            'mmr' => $row['mmr'] !== null ? (int)$row['mmr'] : null,
            'date' => $row['date'],
            'turns' => $match_turns
        ];
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'captain' => [
            'cid' => $target_cid,
            'name' => $target_name,
            'card_key' => $target_card_key,
            'slug' => $target_slug,
            'total_matches' => $total_matches_analyzed
        ],
        'available_turns' => $available_turns,
        'summary' => $summary,
        'games' => $games,
        'units' => $units_map
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
