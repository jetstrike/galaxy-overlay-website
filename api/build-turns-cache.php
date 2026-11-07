<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 1);
ini_set('max_execution_time', 300);
error_reporting(E_ALL & ~E_DEPRECATED);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

function parse_buffs_calc($buff_obj) {
    $atk = 0; $hp = 0;
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

function calc_trimmed_mean_php($vals, $trim_pct = 0.05) {
    if (empty($vals)) return 0.0;
    $n = count($vals);
    if ($n < 5) return round(array_sum($vals) / $n, 1);
    sort($vals, SORT_NUMERIC);
    $k = (int)($n * $trim_pct);
    $trimmed = $k > 0 ? array_slice($vals, $k, $n - 2 * $k) : $vals;
    return !empty($trimmed) ? round(array_sum($trimmed) / count($trimmed), 1) : round(array_sum($vals) / $n, 1);
}

function calc_median_php($vals) {
    if (empty($vals)) return 0.0;
    sort($vals, SORT_NUMERIC);
    $count = count($vals);
    $mid = (int)($count / 2);
    if ($count % 2 === 0) {
        return round(($vals[$mid - 1] + $vals[$mid]) / 2.0, 1);
    }
    return round((float)$vals[$mid], 1);
}

try {
    $conn = get_db_connection();

    $cards_file = __DIR__ . '/cards.json';
    $cards_data = file_exists($cards_file) ? json_decode(file_get_contents($cards_file), true) : [];
    $cid_to_card = [];
    $captains_catalog = [];

    foreach ($cards_data as $k => $c) {
        if (!is_array($c)) continue;
        $c_cid = isset($c['cid']) ? (int)$c['cid'] : (is_numeric($k) ? (int)$k : null);
        if ($c_cid !== null) $cid_to_card[$c_cid] = $c;
        $is_cap = (isset($c['card_type']) && $c['card_type'] === 'captain') || (isset($c['type']) && strtolower($c['type']) === 'captain');
        if ($is_cap && $c_cid !== null) {
            $captains_catalog[$c_cid] = [
                'cid' => $c_cid,
                'name' => $c['name'] ?? "CID $c_cid"
            ];
        }
    }

    $target_cid = isset($_GET['cid']) ? (int)$_GET['cid'] : null;
    $batch_limit = isset($_GET['limit']) ? min(115, max(1, (int)$_GET['limit'])) : 10;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    if ($target_cid !== null) {
        $cap_list = [['cid' => $target_cid, 'name' => $captains_catalog[$target_cid]['name'] ?? "CID $target_cid"]];
    } else {
        $cap_list = array_values($captains_catalog);
        $cap_list = array_slice($cap_list, $offset, $batch_limit);
    }

    $processed_count = 0;
    $total_turns_saved = 0;

    foreach ($cap_list as $cap) {
        $cid = (int)$cap['cid'];

        $stmt = $conn->prepare("
            SELECT run_id, turns 
            FROM playfab_matches USE INDEX (idx_playfab_captain)
            WHERE captain_cid = ? AND turns IS NOT NULL 
            ORDER BY date DESC 
            LIMIT 1500
        ");
        $stmt->bind_param("i", $cid);
        $stmt->execute();
        $res = $stmt->get_result();

        $turn_data = [];
        while ($row = $res->fetch_assoc()) {
            $turns_raw = json_decode($row['turns'], true);
            if (!is_array($turns_raw)) continue;

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
                    } else $crew_map = $crew;
                }
                $slot_map = [];
                if (is_array($crewslots)) {
                    if (isset($crewslots[0])) {
                        foreach ($crewslots as $i => $v) $slot_map[(string)($i + 1)] = $v;
                    } else $slot_map = $crewslots;
                }

                $slots_list = [];
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
                        $unit_name = $card_def['name'] ?? ('CID ' . $card_cid);
                        $card_key = $card_def['card_key'] ?? '';
                        $base_atk = ((int)($card_def['attack'] ?? 0)) * $tier;
                        $base_hp = ((int)($card_def['health'] ?? 0)) * $tier;
                    } elseif ($card_cid !== null && $card_cid > 0) {
                        $unit_name = 'Unknown #' . $card_cid;
                        $card_key = '';
                        $base_atk = 1 * $tier;
                        $base_hp = 1 * $tier;
                    } else {
                        $unit_name = 'Empty Slot';
                        $card_key = '';
                        $base_atk = 0;
                        $base_hp = 0;
                    }

                    list($u_atk, $u_hp) = parse_buffs_calc($c_info['buffs'] ?? null);
                    list($s_atk, $s_hp) = parse_buffs_calc($s_info['buffs'] ?? null);

                    $final_atk = $base_atk + $u_atk + $s_atk;
                    $final_hp = $base_hp + $u_hp + $s_hp;
                    $total_slot_stats = $final_atk + $final_hp;
                    $slot_buff_total = $s_atk + $s_hp;

                    $slots_list[] = [
                        'cid' => $card_cid,
                        'name' => $unit_name,
                        'card_key' => $card_key,
                        'tier' => $tier,
                        'slot_atk' => $s_atk,
                        'slot_hp' => $s_hp,
                        'final_atk' => $final_atk,
                        'final_hp' => $final_hp,
                        'total_slot_stats' => $total_slot_stats,
                        'slot_buff_total' => $slot_buff_total
                    ];

                    $turn_total_stats += $total_slot_stats;
                    $turn_slot_buffs_total += $slot_buff_total;
                }

                if (!isset($turn_data[$tNum])) $turn_data[$tNum] = [];
                $turn_data[$tNum][] = [
                    'turn_total_stats' => $turn_total_stats,
                    'turn_slot_buffs_total' => $turn_slot_buffs_total,
                    'slots' => $slots_list
                ];
            }
        }
        $stmt->close();

        // Get true total matches for this captain in playfab_matches
        $cnt_q = $conn->query("SELECT COUNT(*) FROM playfab_matches WHERE captain_cid = $cid");
        $real_captain_total = ($cnt_q && $r = $cnt_q->fetch_row()) ? (int)$r[0] : 0;
        $max_sample_games = 0;
        foreach ($turn_data as $games) {
            if (count($games) > $max_sample_games) $max_sample_games = count($games);
        }

        $insert_values = [];
        foreach ($turn_data as $tNum => $games) {
            $n_games = count($games);
            if ($n_games < 3) continue;
            $scaled_total_games = ($real_captain_total > $max_sample_games && $max_sample_games > 0) 
                ? (int)round(($n_games / $max_sample_games) * $real_captain_total) 
                : $n_games;

            $totals = array_column($games, 'turn_total_stats');
            sort($totals, SORT_NUMERIC);
            $buffs = array_column($games, 'turn_slot_buffs_total');

            $med_stats = calc_median_php($totals);
            $avg_stats = calc_trimmed_mean_php($totals);
            $p25_stats = (float)$totals[(int)($n_games * 0.25)];
            $p75_stats = (float)$totals[(int)($n_games * 0.75)];
            $p90_stats = (float)$totals[(int)($n_games * 0.90)];
            $max_stats = (int)min(max($totals), 9223372036854775800);
            $avg_buffs = calc_trimmed_mean_php($buffs);

            $slot_stats = [];
            for ($s_idx = 0; $s_idx < 5; $s_idx++) {
                $slot_num = $s_idx + 1;
                $s_totals = [];
                $s_final_atks = [];
                $s_final_hps = [];
                $s_buff_atks = [];
                $s_buff_hps = [];
                $unit_counts = [];

                foreach ($games as $g) {
                    $s = $g['slots'][$s_idx];
                    $s_totals[] = $s['total_slot_stats'];
                    $s_final_atks[] = $s['final_atk'];
                    $s_final_hps[] = $s['final_hp'];
                    $s_buff_atks[] = $s['slot_atk'];
                    $s_buff_hps[] = $s['slot_hp'];

                    $uid = (int)($s['cid'] ?? 0);
                    if (!isset($unit_counts[$uid])) {
                        $unit_counts[$uid] = ['count' => 0, 'name' => $s['name'], 'card_key' => $s['card_key'], 'cid' => $uid];
                    }
                    $unit_counts[$uid]['count']++;
                }

                usort($unit_counts, function($a, $b) { return $b['count'] - $a['count']; });
                $top_units = array_slice($unit_counts, 0, 5);
                $top_formatted = [];
                foreach ($top_units as $u) {
                    if ($u['name'] === 'Empty Slot') continue;
                    $top_formatted[] = [
                        'cid' => $u['cid'],
                        'name' => $u['name'],
                        'card_key' => $u['card_key'],
                        'count' => $u['count'],
                        'pct' => round(($u['count'] / $n_games) * 100, 1)
                    ];
                }

                $buff_sums = [];
                foreach ($s_buff_atks as $i => $atkVal) $buff_sums[] = $atkVal + $s_buff_hps[$i];

                $slot_stats[$slot_num] = [
                    'avg_total_stats' => calc_trimmed_mean_php($s_totals),
                    'median_total_stats' => calc_median_php($s_totals),
                    'avg_final_atk' => calc_trimmed_mean_php($s_final_atks),
                    'avg_final_hp' => calc_trimmed_mean_php($s_final_hps),
                    'avg_slot_buff_atk' => calc_trimmed_mean_php($s_buff_atks),
                    'avg_slot_buff_hp' => calc_trimmed_mean_php($s_buff_hps),
                    'avg_slot_buff_total' => calc_trimmed_mean_php($buff_sums),
                    'top_units' => $top_formatted
                ];
            }

            $slot_json = $conn->real_escape_string(json_encode($slot_stats));
            $insert_values[] = "($cid, $tNum, $scaled_total_games, $med_stats, $avg_stats, $p25_stats, $p75_stats, $p90_stats, $max_stats, $avg_buffs, '$slot_json')";
        }

        if (!empty($insert_values)) {
            $sql = "INSERT INTO analytics_captain_turns 
                    (captain_cid, turn_number, total_games, median_board_stats, avg_board_stats, p25_board_stats, p75_board_stats, p90_board_stats, max_board_stats, avg_slot_buffs, slot_details_json)
                    VALUES " . implode(', ', $insert_values) . "
                    ON DUPLICATE KEY UPDATE
                    total_games = VALUES(total_games),
                    median_board_stats = VALUES(median_board_stats),
                    avg_board_stats = VALUES(avg_board_stats),
                    p25_board_stats = VALUES(p25_board_stats),
                    p75_board_stats = VALUES(p75_board_stats),
                    p90_board_stats = VALUES(p90_board_stats),
                    max_board_stats = VALUES(max_board_stats),
                    avg_slot_buffs = VALUES(avg_slot_buffs),
                    slot_details_json = VALUES(slot_details_json),
                    last_updated = CURRENT_TIMESTAMP";
            $conn->query($sql);
            $total_turns_saved += count($insert_values);
        }

        $processed_count++;
    }

    echo json_encode([
        'success' => true,
        'captains_processed' => $processed_count,
        'turn_records_saved' => $total_turns_saved,
        'next_offset' => $offset + $processed_count,
        'has_more' => ($offset + $processed_count) < count($captains_catalog)
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>
