<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$servername = "localhost";
$username = "u834540789_Tracker";
$password = "Slippery1!1!";
$dbname = "u834540789_Galaxy";
$SECRET_TOKEN = "your_super_secret_token_123";

$inputData = file_get_contents('php://input');
$data = json_decode($inputData, true);

if (!isset($data['token']) || $data['token'] !== $SECRET_TOKEN) {
    http_response_code(401);
    die(json_encode(["error" => "Unauthorized"]));
}

if (!isset($data['matches']) || !is_array($data['matches'])) {
    http_response_code(400);
    die(json_encode(["error" => "Invalid payload, expecting an array of matches."]));
}

try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = new mysqli($servername, $username, $password, $dbname);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed", "details" => $e->getMessage()]));
}

// Ensure the table exists
$tableQuery = "
CREATE TABLE IF NOT EXISTS overlay_matches (
    run_id VARCHAR(64) PRIMARY KEY,
    player_id VARCHAR(64),
    player_name VARCHAR(64),
    player_hash VARCHAR(64),
    date DATETIME,
    rules_version VARCHAR(32),
    source_tag VARCHAR(32),
    placement INT,
    mmr INT,
    mmr_start INT,
    mmr_end INT,
    mmr_delta INT,
    captain_cid INT,
    captain VARCHAR(64),
    captain_options LONGTEXT,
    rank VARCHAR(32),
    deck_cids LONGTEXT,
    turns LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $conn->query($tableQuery);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(["error" => "Failed to create table", "details" => $e->getMessage()]));
}

$stmt = $conn->prepare("INSERT IGNORE INTO overlay_matches (run_id, player_id, player_name, player_hash, date, rules_version, source_tag, placement, mmr, mmr_start, mmr_end, mmr_delta, captain_cid, captain, captain_options, rank, deck_cids, turns) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$inserted = 0;
$skipped = 0;

$conn->begin_transaction();

try {
    foreach ($data['matches'] as $match) {
        $run_id = $match['run_id'] ?? null;
        if (!$run_id) continue;

        $player_id = $match['player_id'] ?? null;
        $player_name = $match['player_name'] ?? null;
        $player_hash = $match['player_hash'] ?? null;
        
        $raw_date = $match['date'] ?? null;
        $date = null;
        if ($raw_date) {
            $ts = strtotime($raw_date);
            if ($ts) $date = date('Y-m-d H:i:s', $ts);
        }

        $rules_version = $match['rules_version'] ?? null;
        $source_tag = $match['source_tag'] ?? null;
        $placement = $match['placement'] ?? null;
        $mmr = $match['mmr'] ?? null;
        $mmr_start = $match['mmr_start'] ?? null;
        $mmr_end = $match['mmr_end'] ?? null;
        $mmr_delta = $match['mmr_delta'] ?? null;
        $captain_cid = $match['captain_cid'] ?? null;
        $captain = $match['captain'] ?? null;
        $rank = $match['rank'] ?? null;
        
        $captain_options = null;
        if (isset($match['captain_options'])) {
            $captain_options = json_encode($match['captain_options']);
        }
        
        $deck_cids = null;
        if (isset($match['deck_cids'])) {
            $deck_cids = json_encode($match['deck_cids']);
        }

        $turns = null;
        if (isset($match['turns'])) {
            $turns = json_encode($match['turns']);
        }

        $stmt->bind_param("ssssssiiiiiiisssss", $run_id, $player_id, $player_name, $player_hash, $date, $rules_version, $source_tag, $placement, $mmr, $mmr_start, $mmr_end, $mmr_delta, $captain_cid, $captain, $captain_options, $rank, $deck_cids, $turns);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    }
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    die(json_encode(["error" => "Transaction failed", "details" => $e->getMessage()]));
}

$stmt->close();
$conn->close();

echo json_encode([
    "success" => true,
    "inserted" => $inserted,
    "skipped" => $skipped,
    "message" => "Batch processed successfully."
]);
?>
