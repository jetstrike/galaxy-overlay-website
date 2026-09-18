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
CREATE TABLE IF NOT EXISTS playfab_matches (
    run_id VARCHAR(64) PRIMARY KEY,
    player_id VARCHAR(64),
    date DATETIME,
    placement INT,
    mmr INT,
    captain_cid INT,
    rank VARCHAR(32),
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

$stmt = $conn->prepare("INSERT IGNORE INTO playfab_matches (run_id, player_id, date, placement, mmr, captain_cid, rank, turns) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

$inserted = 0;
$skipped = 0;

$conn->begin_transaction();

try {
    foreach ($data['matches'] as $match) {
        $run_id = $match['run_id'] ?? null;
        $player_id = $match['player_id'] ?? null;
        
        // Handle dates: parse ISO string and convert to MySQL DATETIME
        $raw_date = $match['date'] ?? null;
        $date = null;
        if ($raw_date) {
            $ts = strtotime($raw_date);
            if ($ts) $date = date('Y-m-d H:i:s', $ts);
        }

        $placement = $match['placement'] ?? null;
        $mmr = $match['mmr'] ?? null;
        $captain_cid = $match['captain_cid'] ?? null;
        $rank = $match['rank'] ?? null;
        
        $turns = null;
        if (isset($match['turns'])) {
            $turns = json_encode($match['turns']);
        }

        if (!$run_id) continue;

        $stmt->bind_param("sssiisss", $run_id, $player_id, $date, $placement, $mmr, $captain_cid, $rank, $turns);
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
