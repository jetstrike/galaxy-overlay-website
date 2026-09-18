<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

// Setup Database Connection Details
$servername = "localhost";
$username = "u834540789_Tracker";
$password = "Slippery1!1!";
$dbname = "u834540789_Galaxy";

if (!isset($_GET['name'])) {
    http_response_code(400);
    die(json_encode(["error" => "Missing captain name"]));
}
$captain_name = $_GET['name'];

try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = new mysqli($servername, $username, $password, $dbname);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed", "details" => $e->getMessage()]));
}

// Fetch all matches for this captain
$stmt = $conn->prepare("SELECT deck_cids, final_board, placement FROM matches WHERE captain_name = ?");
$stmt->bind_param("s", $captain_name);
$stmt->execute();
$result = $stmt->get_result();

$deck_stats = [];
$board_stats = [];
$total_matches = 0;

while ($row = $result->fetch_assoc()) {
    $total_matches++;
    $placement = (int)$row['placement'];
    
    // Process deck_cids (JSON Array)
    if ($row['deck_cids']) {
        $deck = json_decode($row['deck_cids'], true);
        if (is_array($deck)) {
            // Count unique per match (a card is either in the deck or not)
            $unique_deck = array_unique($deck);
            foreach ($unique_deck as $cid) {
                if (!isset($deck_stats[$cid])) {
                    $deck_stats[$cid] = ['count' => 0, 'total_placement' => 0];
                }
                $deck_stats[$cid]['count']++;
                $deck_stats[$cid]['total_placement'] += $placement;
            }
        }
    }
    
    // Process final_board (JSON Object)
    if ($row['final_board']) {
        $board = json_decode($row['final_board'], true);
        if (is_array($board)) {
            // A player can have multiple of the same unit on board, so count all
            foreach ($board as $slot => $unit) {
                if (isset($unit['cid'])) {
                    $cid = $unit['cid'];
                    if ($cid == '0') continue; // 0 usually means empty/unknown
                    
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

// Format Deck Stats
$deck_results = [];
foreach ($deck_stats as $cid => $data) {
    $deck_results[] = [
        "cid" => $cid,
        "count" => $data['count'],
        "avg_placement" => round($data['total_placement'] / $data['count'], 2)
    ];
}
// Sort by count descending
usort($deck_results, function($a, $b) {
    return $b['count'] <=> $a['count'];
});
// Take top 50 deck cards
$deck_results = array_slice($deck_results, 0, 50);

// Format Board Stats
$board_results = [];
foreach ($board_stats as $cid => $data) {
    $board_results[] = [
        "cid" => $cid,
        "count" => $data['count'],
        "avg_placement" => round($data['total_placement'] / $data['count'], 2)
    ];
}
// Sort by count descending
usort($board_results, function($a, $b) {
    return $b['count'] <=> $a['count'];
});
// Take top 10 board cards
$board_results = array_slice($board_results, 0, 10);

echo json_encode([
    "captain" => $captain_name,
    "total_matches" => $total_matches,
    "top_deck" => $deck_results,
    "top_board" => $board_results
]);

$stmt->close();
$conn->close();
?>
