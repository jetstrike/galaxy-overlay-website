<?php
require_once __DIR__ . '/db.php';
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

if (!isset($_GET['name'])) {
    http_response_code(400);
    die(json_encode(["error" => "Missing captain name"]));
}
$captain_name = $_GET['name'];

try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = get_db_connection();

    $stmt = $conn->prepare("SELECT deck_cids, turns, placement FROM overlay_matches WHERE captain = ?");
    $stmt->bind_param("s", $captain_name);
    $stmt->execute();
    $result = $stmt->get_result();

    $deck_stats = [];
    $board_stats = [];
    $total_matches = 0;

    while ($row = $result->fetch_assoc()) {
        $total_matches++;
        $placement = (int)$row['placement'];
        
        // Process deck_cids
        if (!empty($row['deck_cids'])) {
            $deck = json_decode($row['deck_cids'], true);
            if (is_array($deck)) {
                $unique_deck = array_unique($deck);
                foreach ($unique_deck as $cid) {
                    $cid = (int)$cid;
                    if (!isset($deck_stats[$cid])) {
                        $deck_stats[$cid] = ['count' => 0, 'total_placement' => 0];
                    }
                    $deck_stats[$cid]['count']++;
                    $deck_stats[$cid]['total_placement'] += $placement;
                }
            }
        }
        
        // Process final board from turns
        if (!empty($row['turns'])) {
            $turns = json_decode($row['turns'], true);
            if (is_array($turns) && count($turns) > 0) {
                $turnNums = array_keys($turns);
                sort($turnNums, SORT_NUMERIC);
                $finalTurnKey = end($turnNums);
                $finalCrew = $turns[$finalTurnKey]['crew'] ?? [];
                
                if (is_array($finalCrew)) {
                    $seen_cids = [];
                    foreach ($finalCrew as $unit) {
                        if (isset($unit['cid'])) {
                            $cid = (int)$unit['cid'];
                            if (!isset($seen_cids[$cid])) {
                                $seen_cids[$cid] = true;
                                if (!isset($board_stats[$cid])) {
                                    $board_stats[$cid] = ['count' => 0, 'total_placement' => 0];
                                }
                                $board_stats[$cid]['count']++;
                                $board_stats[$cid]['total_placement'] += $placement;
                            }
                        }
                    }
                }
            }
        }
    }
    $stmt->close();

    $top_deck = [];
    foreach ($deck_stats as $cid => $data) {
        $top_deck[] = [
            'cid' => $cid,
            'play_rate' => $total_matches > 0 ? round(($data['count'] / $total_matches) * 100, 1) : 0,
            'avg_placement' => $data['count'] > 0 ? round($data['total_placement'] / $data['count'], 2) : 0,
            'count' => $data['count']
        ];
    }
    usort($top_deck, fn($a, $b) => $b['count'] <=> $a['count']);

    $top_board = [];
    foreach ($board_stats as $cid => $data) {
        $top_board[] = [
            'cid' => $cid,
            'play_rate' => $total_matches > 0 ? round(($data['count'] / $total_matches) * 100, 1) : 0,
            'avg_placement' => $data['count'] > 0 ? round($data['total_placement'] / $data['count'], 2) : 0,
            'count' => $data['count']
        ];
    }
    usort($top_board, fn($a, $b) => $b['count'] <=> $a['count']);

    echo json_encode([
        'captain_name' => $captain_name,
        'total_matches' => $total_matches,
        'top_deck' => array_slice($top_deck, 0, 30),
        'top_board' => array_slice($top_board, 0, 30)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>