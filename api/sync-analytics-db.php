<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

/**
 * High-performance bulk insert helper to prevent network roundtrip lag on TiDB Cloud
 */
function bulk_insert($conn, $table, $columns, $rows) {
    if (empty($rows)) return;
    $colList = implode(', ', array_map(function($c) { return "`$c`"; }, $columns));
    $chunks = array_chunk($rows, 200);
    foreach ($chunks as $chunk) {
        $valClauses = [];
        foreach ($chunk as $row) {
            $escaped = array_map(function($v) use ($conn) {
                if ($v === null) return 'NULL';
                if (is_int($v) || is_float($v)) return $v;
                return "'" . $conn->real_escape_string($v) . "'";
            }, $row);
            $valClauses[] = '(' . implode(', ', $escaped) . ')';
        }
        $sql = "INSERT IGNORE INTO `$table` ($colList) VALUES " . implode(', ', $valClauses);
        $conn->query($sql);
    }
}

try {
    $conn = get_db_connection();

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    if ($limit > 250) $limit = 250;
    if ($limit < 1) $limit = 100;
    $is_full = isset($_GET['full']) && $_GET['full'] == '1';

    // Scoped window for auto-sync: last 7 days unless full backfill is requested
    $date_filter_overlay = $is_full ? "1=1" : "o.created_at >= NOW() - INTERVAL 7 DAY";
    $date_filter_playfab = $is_full ? "1=1" : "p.created_at >= NOW() - INTERVAL 7 DAY";

    // Load captain name to cid map for fallback resolution
    $captain_name_map = [];
    $mapFile = __DIR__ . '/captain_map.json';
    if (file_exists($mapFile)) {
        $loadedMap = json_decode(file_get_contents($mapFile), true);
        if (is_array($loadedMap)) {
            $captain_name_map = $loadedMap;
        }
    }
    $capCardsRes = $conn->query("SELECT cid, name FROM analytics_cards WHERE type = 'captain'");
    if ($capCardsRes) {
        while ($cRow = $capCardsRes->fetch_assoc()) {
            $captain_name_map[$cRow['name']] = intval($cRow['cid']);
            $captain_name_map[strtolower(str_replace([' ', "'", ','], ['_', '', ''], $cRow['name']))] = intval($cRow['cid']);
        }
    }

    $matches_data = [];
    $decks_data = [];
    $turns_data = [];
    $processed = 0;

    // 1. Ingest from overlay_matches (Tracker app live matches)
    $q_overlay = "SELECT o.* FROM overlay_matches o 
                  LEFT JOIN analytics_matches a ON a.run_id = CONCAT(o.run_id, '_', o.placement) 
                  WHERE $date_filter_overlay AND a.run_id IS NULL AND o.placement IS NOT NULL 
                  LIMIT $limit";
    $res = $conn->query($q_overlay);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $run_id = $row['run_id'];
            $placement = intval($row['placement']);
            $player_run_id = $run_id . '_' . $placement;
            $date = !empty($row['date']) ? $row['date'] : (!empty($row['created_at']) ? $row['created_at'] : date('Y-m-d H:i:s'));
            $mmr = isset($row['mmr_start']) && $row['mmr_start'] !== null ? intval($row['mmr_start']) : (isset($row['mmr']) ? intval($row['mmr']) : 0);
            $captain_cid = isset($row['captain_cid']) ? intval($row['captain_cid']) : 0;
            if ($captain_cid === 0 && !empty($row['captain'])) {
                $capKey = $row['captain'];
                if (isset($captain_name_map[$capKey])) {
                    $captain_cid = $captain_name_map[$capKey];
                }
            }
            $rank = isset($row['rank']) ? $row['rank'] : '';
            $source = 'overlay';
            $rules_version = isset($row['rules_version']) ? $row['rules_version'] : '';
            
            $matches_data[] = [$player_run_id, $date, $mmr, $placement, $captain_cid, $rank, $source, $rules_version];
            
            $deck_cids = [];
            if (!empty($row['deck_cids'])) {
                $decoded_decks = json_decode($row['deck_cids'], true);
                if (is_array($decoded_decks)) {
                    foreach ($decoded_decks as $cid) {
                        $cid = intval($cid);
                        if ($cid > 0) $deck_cids[$cid] = true;
                    }
                }
            }

            $turns = !empty($row['turns']) ? json_decode($row['turns'], true) : null;
            if (is_array($turns)) {
                foreach ($turns as $turnNumStr => $tData) {
                    $turnNum = intval($turnNumStr);
                    if (isset($tData['crew']) && is_array($tData['crew'])) {
                        foreach ($tData['crew'] as $slot => $unit) {
                            if (isset($unit['cid'])) {
                                $cid = intval($unit['cid']);
                                if ($cid > 0) {
                                    if (empty($row['deck_cids'])) {
                                        $deck_cids[$cid] = true;
                                    }
                                    $turns_data[] = [$player_run_id, $turnNum, (string)$slot, $cid];
                                }
                            }
                        }
                    }
                }
            }
            
            foreach(array_keys($deck_cids) as $cid) {
                $decks_data[] = [$player_run_id, $cid];
            }
            
            $processed++;
        }
    }

    // 2. Ingest from playfab_matches (if limit remaining)
    if ($processed < $limit) {
        $rem_limit = $limit - $processed;
        $q_playfab = "SELECT p.* FROM playfab_matches p 
                      LEFT JOIN analytics_matches a ON a.run_id = CONCAT(p.run_id, '_', p.placement) 
                      WHERE $date_filter_playfab AND a.run_id IS NULL AND p.placement IS NOT NULL 
                      LIMIT $rem_limit";
        $res = $conn->query($q_playfab);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $run_id = $row['run_id'];
                $placement = intval($row['placement']);
                $player_run_id = $run_id . '_' . $placement;
                $date = !empty($row['date']) ? $row['date'] : (!empty($row['created_at']) ? $row['created_at'] : date('Y-m-d H:i:s'));
                $mmr = isset($row['mmr']) ? intval($row['mmr']) : 0;
                $captain_cid = isset($row['captain_cid']) ? intval($row['captain_cid']) : 0;
                if ($captain_cid === 0 && !empty($row['captain'])) {
                    $capKey = $row['captain'];
                    if (isset($captain_name_map[$capKey])) {
                        $captain_cid = $captain_name_map[$capKey];
                    }
                }
                $rank = isset($row['rank']) ? $row['rank'] : '';
                $source = 'playfab';
                $rules_version = isset($row['rules_version']) ? $row['rules_version'] : '';
                
                $matches_data[] = [$player_run_id, $date, $mmr, $placement, $captain_cid, $rank, $source, $rules_version];
                
                $turns = !empty($row['turns']) ? json_decode($row['turns'], true) : null;
                $deck_cids = [];
                
                if (is_array($turns)) {
                    foreach ($turns as $turnNumStr => $tData) {
                        $turnNum = intval($turnNumStr);
                        if (isset($tData['crew']) && is_array($tData['crew'])) {
                            foreach ($tData['crew'] as $slot => $unit) {
                                if (isset($unit['cid'])) {
                                    $cid = intval($unit['cid']);
                                    if ($cid > 0) {
                                        $deck_cids[$cid] = true;
                                        $turns_data[] = [$player_run_id, $turnNum, (string)$slot, $cid];
                                    }
                                }
                            }
                        }
                    }
                }
                
                foreach(array_keys($deck_cids) as $cid) {
                    $decks_data[] = [$player_run_id, $cid];
                }
                
                $processed++;
            }
        }
    }

    // 3. Execute bulk batch inserts in single transaction
    if ($processed > 0) {
        $conn->begin_transaction();
        
        bulk_insert($conn, 'analytics_matches', ['run_id', 'date', 'mmr', 'placement', 'captain_cid', 'rank', 'source', 'rules_version'], $matches_data);
        bulk_insert($conn, 'analytics_match_decks', ['run_id', 'card_cid'], $decks_data);
        bulk_insert($conn, 'analytics_match_turns', ['run_id', 'turn_number', 'slot_id', 'card_cid'], $turns_data);
        
        $conn->commit();
    }
    
    $res = $conn->query("SELECT COUNT(*) as c FROM analytics_matches");
    $total_now = $res->fetch_assoc()['c'];

    echo json_encode([
        'success' => true,
        'processed_this_batch' => $processed,
        'total_analytics_matches' => intval($total_now)
    ]);

} catch (Throwable $e) {
    if (isset($conn)) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>
