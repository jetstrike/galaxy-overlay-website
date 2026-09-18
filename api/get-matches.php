<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

// 1. Setup Database Connection Details
$servername = "localhost"; // Usually localhost on Hostinger
$username = "u834540789_Tracker";
$password = "Slippery1!1!"; // Same as submit-match.php
$dbname = "u834540789_Galaxy";

// 2. Setup Security Token (so nobody else can steal the data)
$SECRET_TOKEN = "your_super_secret_token_123";

// 3. Verify Authorization
if (!isset($_GET['token']) || $_GET['token'] !== $SECRET_TOKEN) {
    http_response_code(401);
    die(json_encode(["error" => "Unauthorized"]));
}

// 4. Connect to MySQL
try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = new mysqli($servername, $username, $password, $dbname);
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed", "details" => $e->getMessage()]));
}

// 5. Fetch all matches
$result = $conn->query("SELECT * FROM matches ORDER BY created_at DESC");

$matches = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Decode JSON columns for easier parsing
        if (isset($row['captain_options'])) {
            $row['captain_options'] = json_decode($row['captain_options'], true);
        }
        if (isset($row['deck_cids'])) {
            $row['deck_cids'] = json_decode($row['deck_cids'], true);
        }
        $matches[] = $row;
    }
}

// 6. Output as JSON
echo json_encode($matches);

$conn->close();
?>
