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
