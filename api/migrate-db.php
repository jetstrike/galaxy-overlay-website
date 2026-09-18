<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

// ONLY RUN THIS ONCE
$SECRET_TOKEN = "your_super_secret_token_123";
if (!isset($_GET['token']) || $_GET['token'] !== $SECRET_TOKEN) {
    http_response_code(401);
    die(json_encode(["error" => "Unauthorized"]));
}

$servername = "localhost";
$username = "u834540789_Tracker";
$password = "Slippery1!1!";
$dbname = "u834540789_Galaxy";

try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = new mysqli($servername, $username, $password, $dbname);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed", "details" => $e->getMessage()]));
}

$captain_map = [];
if (file_exists('captain_map.json')) {
    $captain_map = json_decode(file_get_contents('captain_map.json'), true);
}

// Fetch all legacy matches
$stmt = $conn->prepare("SELECT * FROM matches");
$stmt->execute();
$result = $stmt->get_result();

$insert_stmt = $conn->prepare("INSERT IGNORE INTO overlay_matches 
    (run_id, player_hash, captain_cid, captain_options, mmr_start, mmr_end, mmr_delta, placement, rank, deck_cids, turns, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$inserted = 0;
$skipped = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $run_id = $row['match_id'];
        $player_hash = $row['player_hash'];
        $captain_name = $row['captain_name'];
        $mmr_start = $row['mmr_start'];
        $mmr_end = $row['mmr_end'];
        $mmr_delta = $row['mmr_delta'];
        $placement = $row['placement'];
        $rank = $row['rank_name'];
        $created_at = $row['created_at'];
        
        $captain_options = $row['captain_options'];
        $deck_cids = $row['deck_cids'];
        
        // Translate Captain Name to CID
        $captain_cid = 0;
        if (isset($captain_map[$captain_name])) {
            $captain_cid = $captain_map[$captain_name];
        } else {
            // Strip out non-alphanumeric just in case there are mismatches
            foreach ($captain_map as $name => $cid) {
                if (strcasecmp(preg_replace('/[^A-Za-z0-9]/', '', $name), preg_replace('/[^A-Za-z0-9]/', '', $captain_name)) === 0) {
                    $captain_cid = $cid;
                    break;
                }
            }
        }
        
        // Translate round_boards to PlayFab turns format
        $turns = null;
        if (isset($row['round_boards'])) {
            $round_boards = json_decode($row['round_boards'], true);
            $turns_array = [];
            if (is_array($round_boards)) {
                foreach ($round_boards as $rb) {
                    if (isset($rb['turn'])) {
                        $turnNum = strval($rb['turn']);
                        $crew = [];
                        if (isset($rb['board']) && is_array($rb['board'])) {
                            foreach ($rb['board'] as $slot => $unit) {
                                if (isset($unit['cid'])) {
                                    $crew[strval($slot)] = ["cid" => intval($unit['cid'])];
                                }
                            }
                        }
                        $turns_array[$turnNum] = ["crew" => $crew];
                    }
                }
            }
            $turns = json_encode($turns_array);
        }

        $insert_stmt->bind_param("ssisiiiissss", 
            $run_id, $player_hash, $captain_cid, $captain_options, 
            $mmr_start, $mmr_end, $mmr_delta, $placement, $rank, 
            $deck_cids, $turns, $created_at);
            
        $insert_stmt->execute();
        
        if ($insert_stmt->affected_rows > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    }
}

echo json_encode([
    "success" => true,
    "inserted" => $inserted,
    "skipped" => $skipped,
    "message" => "Migration complete."
]);

$insert_stmt->close();
$stmt->close();
$conn->close();
?>
