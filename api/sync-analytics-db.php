<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = new mysqli($host, $user, $pass, $db);

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
    $processed = 0;

    $stmt_match = $conn->prepare("INSERT IGNORE INTO analytics_matches (run_id, date, mmr, placement, captain_cid, rank, source, rules_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_deck = $conn->prepare("INSERT INTO analytics_match_decks (run_id, card_cid) VALUES (?, ?)");
    $stmt_turn = $conn->prepare("INSERT INTO analytics_match_turns (run_id, turn_number, slot_id, card_cid) VALUES (?, ?, ?, ?)");

    $conn->begin_transaction();

    // 1. Process Playfab Matches
    $q = "SELECT * FROM playfab_runs WHERE run_id NOT IN (SELECT run_id FROM analytics_matches) LIMIT $limit";
    $res = $conn->query($q);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $run_id = $row['run_id'];
            $date = $row['date'];
            $mmr = $row['mmr'];
            $placement = $row['placement'];
            $captain_cid = $row['captain_cid'];
            $rank = $row['rank'];
            $source = 'playfab';
            $rules_version = $row['rules_version'];
            
            $stmt_match->bind_param("ssiiisss", $run_id, $date, $mmr, $placement, $captain_cid, $rank, $source, $rules_version);
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
                                $deck_cids[$cid] = true;
                                $stmt_turn->bind_param("sisi", $run_id, $turnNum, $slot, $cid);
                                $stmt_turn->execute();
                            }
                        }
                    }
                }
            }
            
            foreach(array_keys($deck_cids) as $cid) {
                $stmt_deck->bind_param("si", $run_id, $cid);
                $stmt_deck->execute();
            }
            
            $processed++;
        }
    }

    // 2. Process Overlay Matches (if still under limit)
    if ($processed < $limit) {
        $rem_limit = $limit - $processed;
        $q = "SELECT * FROM overlay_matches WHERE run_id NOT IN (SELECT run_id FROM analytics_matches) LIMIT $rem_limit";
        $res = $conn->query($q);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $run_id = $row['run_id'];
                $date = $row['date'];
                $mmr = $row['mmr_start']; // Overlay matches have mmr_start
                if ($mmr === null) $mmr = $row['mmr'];
                $placement = $row['placement'];
                $captain_cid = $row['captain_cid'];
                $rank = $row['rank'];
                $source = 'overlay';
                $rules_version = $row['rules_version'];
                
                $stmt_match->bind_param("ssiiisss", $run_id, $date, $mmr, $placement, $captain_cid, $rank, $source, $rules_version);
                $stmt_match->execute();
                
                $deck_cids = [];
                if (!empty($row['deck_cids'])) {
                    $decoded_decks = json_decode($row['deck_cids'], true);
                    if (is_array($decoded_decks)) {
                        foreach ($decoded_decks as $cid) {
                            $deck_cids[intval($cid)] = true;
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
                                    // if deck_cids was empty, build it from turns
                                    if (empty($row['deck_cids'])) {
                                        $deck_cids[$cid] = true;
                                    }
                                    $stmt_turn->bind_param("sisi", $run_id, $turnNum, $slot, $cid);
                                    $stmt_turn->execute();
                                }
                            }
                        }
                    }
                }
                
                foreach(array_keys($deck_cids) as $cid) {
                    $stmt_deck->bind_param("si", $run_id, $cid);
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
