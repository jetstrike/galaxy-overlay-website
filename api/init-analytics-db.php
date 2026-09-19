<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

$conn->begin_transaction();

try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS analytics_cards (
            cid INT PRIMARY KEY,
            name VARCHAR(64),
            type VARCHAR(32),
            tribe VARCHAR(32),
            tier INT
        )
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS analytics_matches (
            run_id VARCHAR(64) PRIMARY KEY,
            date DATETIME,
            mmr INT,
            placement INT,
            captain_cid INT,
            rank VARCHAR(32),
            source VARCHAR(16),
            rules_version VARCHAR(32)
        )
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS analytics_match_decks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_id VARCHAR(64),
            card_cid INT,
            FOREIGN KEY(run_id) REFERENCES analytics_matches(run_id) ON DELETE CASCADE,
            INDEX idx_card (card_cid)
        )
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS analytics_match_turns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            run_id VARCHAR(64),
            turn_number INT,
            slot_id VARCHAR(16),
            card_cid INT,
            is_buff BOOLEAN DEFAULT FALSE,
            FOREIGN KEY(run_id) REFERENCES analytics_matches(run_id) ON DELETE CASCADE,
            INDEX idx_turn_card (turn_number, card_cid)
        )
    ");

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Analytics tables created successfully.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
?>