<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = get_db_connection();

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
    $processed = 0;

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

    $stmt_match = $conn->prepare("INSERT IGNORE INTO analytics_matches (`run_id`, `date`, `mmr`, `placement`, `captain_cid`, `rank`, `source`, `rules_version`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_deck = $conn->prepare("INSERT IGNORE INTO analytics_match_decks (run_id, card_cid) VALUES (?, ?)");
    $stmt_turn = $conn->prepare("INSERT IGNORE INTO analytics_match_turns (run_id, turn_number, slot_id, card_cid) VALUES (?, ?, ?, ?)");

    $conn->begin_transaction();

    // 1. Process Playfab Matches
    $q = "SELECT p.* FROM playfab_matches p 
          LEFT JOIN analytics_matches a ON (a.run_id = CONCAT(p.run_id, '_', p.placement) OR a.run_id = p.run_id) 
          WHERE a.run_id IS NULL AND p.placement IS NOT NULL 
          LIMIT $limit";
    $res = $conn->query($q);
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
            
            $stmt_match->bind_param("ssiiisss", $player_run_id, $date, $mmr, $placement, $captain_cid, $rank, $source, $rules_version);
            $stmt_match->execute();
            
            $turns = json_decode($row['turns'], true);
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
                                    $stmt_turn->bind_param("sisi", $player_run_id, $turnNum, $slot, $cid);
                                    $stmt_turn->execute();
                                }
                            }
                        }
                    }
                }
            }
            
            foreach(array_keys($deck_cids) as $cid) {
                $stmt_deck->bind_param("si", $player_run_id, $cid);
                $stmt_deck->execute();
            }
            
            $processed++;
        }
    }

    // 2. Process Overlay Matches (if still under limit)
    if ($processed < $limit) {
        $rem_limit = $limit - $processed;
        $q = "SELECT o.* FROM overlay_matches o 
              LEFT JOIN analytics_matches a ON (a.run_id = CONCAT(o.run_id, '_', o.placement) OR a.run_id = o.run_id) 
              WHERE a.run_id IS NULL AND o.placement IS NOT NULL 
              LIMIT $rem_limit";
        $res = $conn->query($q);
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
                
                $stmt_match->bind_param("ssiiisss", $player_run_id, $date, $mmr, $placement, $captain_cid, $rank, $source, $rules_version);
                $stmt_match->execute();
                
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

                $turns = json_decode($row['turns'], true);
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
                                        $stmt_turn->bind_param("sisi", $player_run_id, $turnNum, $slot, $cid);
                                        $stmt_turn->execute();
                                    }
                                }
                            }
                        }
                    }
                }
                
                foreach(array_keys($deck_cids) as $cid) {
                    $stmt_deck->bind_param("si", $player_run_id, $cid);
                    $stmt_deck->execute();
                }
                
                $processed++;
            }
        }
    }

    $conn->commit();
    
    $res = $conn->query("SELECT COUNT(*) as c FROM analytics_matches");
    $total_now = $res->fetch_assoc()['c'];

    echo json_encode([
        'success' => true,
        'processed_this_batch' => $processed,
        'total_analytics_matches' => intval($total_now)
    ]);

} catch (Throwable $e) {
    if (isset($conn)) $conn->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

if (isset($stmt_match)) $stmt_match->close();
if (isset($stmt_deck)) $stmt_deck->close();
if (isset($stmt_turn)) $stmt_turn->close();
if (isset($conn)) $conn->close();
?>