<?php
require_once __DIR__ . '/db.php';
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

try {
    $conn = get_db_connection();
    $res = $conn->query("DELETE FROM playfab_matches WHERE player_name IS NULL");
    echo json_encode([
        'success' => true,
        'affected_rows' => $conn->affected_rows
    ]);
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
