<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

$response = [];
try {
    $conn = get_db_connection();

    // Add compatible_captain_cids to website_decks if not exists
    $colsRes = $conn->query("SHOW COLUMNS FROM website_decks LIKE 'compatible_captain_cids'");
    if ($colsRes->num_rows === 0) {
        $conn->query("ALTER TABLE website_decks ADD COLUMN compatible_captain_cids JSON DEFAULT NULL AFTER captain_cid");
        $response['added_column'] = true;
    } else {
        $response['added_column'] = false;
        $response['message'] = 'Column compatible_captain_cids already exists';
    }

    $response['success'] = true;
} catch (Throwable $e) {
    $response['error'] = $e->getMessage();
}

if (isset($conn)) $conn->close();
echo json_encode($response, JSON_PRETTY_PRINT);
?>