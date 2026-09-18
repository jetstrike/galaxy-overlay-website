<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// 1. Setup Database Connection Details
$servername = "localhost"; // Usually localhost on Hostinger
$username = "u834540789_Tracker";
$password = "Slippery1!1!"; // Fill this in!
$dbname = "u834540789_Galaxy";

// 2. Setup Security Token
$SECRET_TOKEN = "your_super_secret_token_123"; // Must match the token in apiClient.js

// 3. Verify Authorization Header
$headers = apache_request_headers();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (trim($authHeader) !== "Bearer " . $SECRET_TOKEN) {
    http_response_code(401);
    die(json_encode(["error" => "Unauthorized"]));
}

// 4. Read incoming JSON payload
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || !isset($data['match_id'])) {
    http_response_code(400);
    die(json_encode(["error" => "Invalid payload"]));
}

// 5. Connect to MySQL
try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = new mysqli($servername, $username, $password, $dbname);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed", "details" => $e->getMessage()]));
}

// 6. Prepare and Bind to prevent SQL Injection
$stmt = $conn->prepare("INSERT INTO matches (match_id, player_hash, captain_name, captain_options, mmr_start, mmr_end, mmr_delta, placement, rank_name, deck_cids, round_boards, final_board) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE match_id=match_id");

if (!$stmt) {
    http_response_code(500);
    die(json_encode(["error" => "Statement preparation failed", "details" => $conn->error]));
}

$captain_options_json = json_encode($data['captain_options']);
$deck_cids_json = json_encode($data['deck_cids']);
$round_boards_json = isset($data['round_boards']) ? json_encode($data['round_boards']) : json_encode([]);
$final_board_json = isset($data['final_board']) ? json_encode($data['final_board']) : json_encode(new stdClass());

$stmt->bind_param(
    "ssssiiiissss",
    $data['match_id'],
    $data['player_hash'],
    $data['captain_name'],
    $captain_options_json,
    $data['mmr_start'],
    $data['mmr_end'],
    $data['mmr_delta'],
    $data['placement'],
    $data['rank_name'],
    $deck_cids_json,
    $round_boards_json,
    $final_board_json
);

// 7. Execute and return response
if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Match recorded successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to record match", "details" => $stmt->error]);
}

$stmt->close();
$conn->close();
?>
