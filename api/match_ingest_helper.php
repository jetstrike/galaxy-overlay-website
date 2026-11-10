<?php
/**
 * Shared Match Analytics Ingestion Helper
 * Normalizes captain_cid, extracts final_turn and deduplicated per-match card metrics
 * (first_turn, turns_on_board), and performs batched bulk inserts into analytics tables.
 */

function load_captain_name_map($conn = null) {
    static $cached_map = null;
    if ($cached_map !== null) {
        return $cached_map;
    }

    $captain_name_map = [];
    $mapFile = __DIR__ . '/captain_map.json';
    if (file_exists($mapFile)) {
        $loadedMap = json_decode(@file_get_contents($mapFile), true);
        if (is_array($loadedMap)) {
            foreach ($loadedMap as $k => $v) {
                $captain_name_map[$k] = intval($v);
                $norm = strtolower(str_replace([' ', "'", ','], ['_', '', ''], $k));
                $captain_name_map[$norm] = intval($v);
            }
        }
    }

    if ($conn instanceof mysqli) {
        try {
            $capCardsRes = $conn->query("SELECT cid, name FROM analytics_cards WHERE type = 'captain'");
            if ($capCardsRes) {
                while ($cRow = $capCardsRes->fetch_assoc()) {
                    $cid = intval($cRow['cid']);
                    $captain_name_map[$cRow['name']] = $cid;
                    $norm = strtolower(str_replace([' ', "'", ','], ['_', '', ''], $cRow['name']));
                    $captain_name_map[$norm] = $cid;
                }
            }
        } catch (Throwable $e) {
            // Ignore fallback error
        }
    }

    $cached_map = $captain_name_map;
    return $captain_name_map;
}

function resolve_captain_cid($captain_cid, $captain_name, $captain_name_map) {
    $cid = intval($captain_cid);
    if ($cid > 0) {
        return $cid;
    }
    if (!empty($captain_name)) {
        $trimmed = trim($captain_name);
        if (isset($captain_name_map[$trimmed])) {
            return $captain_name_map[$trimmed];
        }
        $norm = strtolower(str_replace([' ', "'", ','], ['_', '', ''], $trimmed));
        if (isset($captain_name_map[$norm])) {
            return $captain_name_map[$norm];
        }
    }
    return 0;
}

/**
 * Extracts final_turn and deduplicated per-match card metrics from a decoded or JSON turns payload.
 * Returns: ['final_turn' => int, 'deck_rows' => [[run_id, card_cid, first_turn, turns_on_board], ...]]
 */
function extract_match_card_metrics($player_run_id, $turns_input) {
    $turns = is_string($turns_input) ? json_decode($turns_input, true) : $turns_input;
    $card_turns = [];
    $final_turn = 0;

    if (is_array($turns) && !empty($turns)) {
        $hasZero = isset($turns[0]) || isset($turns['0']);
        foreach ($turns as $turnNumStr => $tData) {
            if (!is_array($tData)) continue;
            $tNum = intval($turnNumStr);
            $turnNum = $hasZero ? ($tNum + 1) : $tNum;
            if ($turnNum < 1) $turnNum = 1;
            if ($turnNum > $final_turn) {
                $final_turn = $turnNum;
            }
            if (isset($tData['crew']) && is_array($tData['crew'])) {
                foreach ($tData['crew'] as $slot => $unit) {
                    if (is_array($unit) && isset($unit['cid'])) {
                        $cid = intval($unit['cid']);
                        if ($cid > 0) {
                            $card_turns[$cid][$turnNum] = true;
                        }
                    }
                }
            }
        }
        if ($final_turn === 0) {
            $final_turn = count($turns);
        }
    }

    $deck_rows = [];
    foreach ($card_turns as $cid => $turnMap) {
        $turnNums = array_keys($turnMap);
        $first_turn = min(255, max(1, intval(min($turnNums))));
        $turns_on_board = min(255, max(1, count($turnNums)));
        $deck_rows[] = [$player_run_id, intval($cid), $first_turn, $turns_on_board];
    }

    return [
        'final_turn' => $final_turn,
        'deck_rows' => $deck_rows
    ];
}

/**
 * Compacts a raw PlayFab/Overlay turns payload (~50% smaller JSON) while preserving 100% of fields
 * used by turns.html (build-turns-cache.php, get-captain-turns.php), get-player-matches.php, and app turn viewers:
 * - attrs.total_stats
 * - crew[1..5]: cid, tier (if > 1), and pre-summed buffs [{attack, health}] (if non-zero)
 * - crewslots[1..5]: pre-summed buffs [{attack, health}] (if non-zero)
 * - hoard: [{cid, tier}] (if non-empty)
 * Strips repeated per-turn throne cosmetic frames/skins and redundant bebop_* subfields.
 */
function sum_turn_buff_array($buff_obj) {
    $atk = 0;
    $hp = 0;
    if (empty($buff_obj) || !is_array($buff_obj)) return [0, 0];
    $buff_list = isset($buff_obj[0]) ? $buff_obj : array_values($buff_obj);
    foreach ($buff_list as $b) {
        if (is_array($b)) {
            $atk += (int)($b['attack'] ?? 0);
            $hp += (int)($b['health'] ?? 0);
        }
    }
    return [$atk, $hp];
}

function compact_turns_payload($turns_input) {
    if ($turns_input === null || $turns_input === '') return null;
    $turns = is_string($turns_input) ? json_decode($turns_input, true) : $turns_input;
    if (!is_array($turns) || empty($turns)) return null;

    $is_list = isset($turns[0]) || isset($turns['0']);
    $compacted = [];

    foreach ($turns as $k => $t_obj) {
        if (!is_array($t_obj)) continue;
        $t_num = $is_list ? ((int)$k + 1) : (int)$k;
        if ($t_num < 1) $t_num = 1;

        $out_turn = [];

        // 1. attrs (preserve total_stats for power curve)
        if (isset($t_obj['attrs']) && is_array($t_obj['attrs'])) {
            $ts = isset($t_obj['attrs']['total_stats']) ? (int)$t_obj['attrs']['total_stats'] : 0;
            if ($ts > 0) {
                $out_turn['attrs'] = ['total_stats' => $ts];
            }
        }

        // 2. crew (normalize 1..5 keys, preserve cid, tier > 1, and summed attack/health buffs)
        $crew = $t_obj['crew'] ?? [];
        $crew_map = [];
        if (is_array($crew)) {
            if (isset($crew[0]) || isset($crew['0'])) {
                foreach ($crew as $idx => $v) {
                    $crew_map[(string)((int)$idx + 1)] = $v;
                }
            } else {
                $crew_map = $crew;
            }
        }

        $out_crew = [];
        for ($s_idx = 1; $s_idx <= 5; $s_idx++) {
            $s_key = (string)$s_idx;
            $c_info = $crew_map[$s_key] ?? ($crew_map[$s_idx] ?? null);
            if (is_array($c_info) && !empty($c_info['cid'])) {
                $cid = (int)$c_info['cid'];
                if ($cid > 0) {
                    $u_obj = ['cid' => $cid];
                    $tier = isset($c_info['tier']) ? (int)$c_info['tier'] : 1;
                    if ($tier > 1) {
                        $u_obj['tier'] = $tier;
                    }
                    list($u_atk, $u_hp) = sum_turn_buff_array($c_info['buffs'] ?? null);
                    if ($u_atk !== 0 || $u_hp !== 0) {
                        $u_obj['buffs'] = [['attack' => $u_atk, 'health' => $u_hp]];
                    }
                    $out_crew[$s_key] = $u_obj;
                }
            }
        }
        if (!empty($out_crew)) {
            $out_turn['crew'] = $out_crew;
        }

        // 3. crewslots (preserve non-zero slot buffs for turns.html)
        $crewslots = $t_obj['crewslots'] ?? [];
        $slot_map = [];
        if (is_array($crewslots)) {
            if (isset($crewslots[0]) || isset($crewslots['0'])) {
                foreach ($crewslots as $idx => $v) {
                    $slot_map[(string)((int)$idx + 1)] = $v;
                }
            } else {
                $slot_map = $crewslots;
            }
        }

        $out_slots = [];
        for ($s_idx = 1; $s_idx <= 5; $s_idx++) {
            $s_key = (string)$s_idx;
            $s_info = $slot_map[$s_key] ?? ($slot_map[$s_idx] ?? null);
            if (is_array($s_info)) {
                list($s_atk, $s_hp) = sum_turn_buff_array($s_info['buffs'] ?? null);
                if ($s_atk !== 0 || $s_hp !== 0) {
                    $out_slots[$s_key] = ['buffs' => [['attack' => $s_atk, 'health' => $s_hp]]];
                }
            }
        }
        if (!empty($out_slots)) {
            $out_turn['crewslots'] = $out_slots;
        }

        // 4. hoard (preserve treasures)
        if (!empty($t_obj['hoard']) && is_array($t_obj['hoard'])) {
            $out_hoard = [];
            foreach ($t_obj['hoard'] as $h) {
                if (is_array($h) && !empty($h['cid'])) {
                    $h_obj = ['cid' => (int)$h['cid']];
                    if (isset($h['tier']) && (int)$h['tier'] > 1) {
                        $h_obj['tier'] = (int)$h['tier'];
                    }
                    $out_hoard[] = $h_obj;
                }
            }
            if (!empty($out_hoard)) {
                $out_turn['hoard'] = $out_hoard;
            }
        }

        $compacted[(string)$t_num] = $out_turn;
    }

    return !empty($compacted) ? json_encode($compacted, JSON_UNESCAPED_SLASHES) : null;
}

/**
 * Batched INSERT IGNORE helper
 */
function bulk_insert_ignore($conn, $table, $columns, $rows, $chunk_size = 1000) {
    if (empty($rows)) return 0;
    $colList = implode(', ', array_map(function($c) { return "`$c`"; }, $columns));
    $chunks = array_chunk($rows, $chunk_size);
    $affected = 0;
    foreach ($chunks as $chunk) {
        $valClauses = [];
        foreach ($chunk as $row) {
            $escaped = array_map(function($v) use ($conn) {
                if ($v === null) return 'NULL';
                if (is_int($v) || is_float($v)) return $v;
                return "'" . $conn->real_escape_string((string)$v) . "'";
            }, $row);
            $valClauses[] = '(' . implode(', ', $escaped) . ')';
        }
        $sql = "INSERT IGNORE INTO `$table` ($colList) VALUES " . implode(', ', $valClauses);
        if ($conn->query($sql)) {
            $affected += max(0, $conn->affected_rows);
        }
    }
    return $affected;
}

/**
 * Batched upsert into analytics_match_decks (supports both legacy 2-col and new 4-col schema gracefully)
 */
function bulk_upsert_match_decks($conn, $deck_rows, $has_turn_cols = true, $chunk_size = 1500) {
    if (empty($deck_rows)) return;
    $chunks = array_chunk($deck_rows, $chunk_size);
    foreach ($chunks as $chunk) {
        $valClauses = [];
        foreach ($chunk as $row) {
            $run_escaped = "'" . $conn->real_escape_string((string)$row[0]) . "'";
            $cid = intval($row[1]);
            if ($has_turn_cols) {
                $ft = intval($row[2] ?? 1);
                $tob = intval($row[3] ?? 1);
                $valClauses[] = "($run_escaped, $cid, $ft, $tob)";
            } else {
                $valClauses[] = "($run_escaped, $cid)";
            }
        }
        if ($has_turn_cols) {
            $sql = "INSERT INTO `analytics_match_decks` (`run_id`, `card_cid`, `first_turn`, `turns_on_board`) VALUES "
                 . implode(', ', $valClauses)
                 . " ON DUPLICATE KEY UPDATE `first_turn` = VALUES(`first_turn`), `turns_on_board` = VALUES(`turns_on_board`)";
        } else {
            $sql = "INSERT IGNORE INTO `analytics_match_decks` (`run_id`, `card_cid`) VALUES " . implode(', ', $valClauses);
        }
        $conn->query($sql);
    }
}
