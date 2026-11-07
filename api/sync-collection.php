<?php
require_once __DIR__ . '/db.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Token, X-Author-Token");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $conn = get_db_connection();

    // Ensure user_collections table exists
    $conn->query("CREATE TABLE IF NOT EXISTS user_collections (
        playfab_id VARCHAR(32) PRIMARY KEY,
        owned_cards_json MEDIUMTEXT NOT NULL,
        infinity_captains_json TEXT NOT NULL,
        total_cards INT NOT NULL DEFAULT 0,
        total_captains INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // =========================================================================
    // GET: Query User Collection by PlayFab ID
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $playfab_id = isset($_GET['playfab_id']) ? strtoupper(preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['playfab_id'])) : '';
        if (empty($playfab_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'playfab_id is required']);
            exit;
        }

        $stmt = $conn->prepare("SELECT playfab_id, owned_cards_json, infinity_captains_json, total_cards, total_captains, updated_at FROM user_collections WHERE playfab_id = ? LIMIT 1");
        $stmt->bind_param("s", $playfab_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();

        if (!$row) {
            echo json_encode([
                'success' => false,
                'found' => false,
                'message' => 'No collection found for this PlayFab ID'
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'found' => true,
            'playfab_id' => $row['playfab_id'],
            'owned_cards' => json_decode($row['owned_cards_json'], true) ?: new stdClass(),
            'infinity_captains' => json_decode($row['infinity_captains_json'], true) ?: [],
            'total_cards' => (int)$row['total_cards'],
            'total_captains' => (int)$row['total_captains'],
            'updated_at' => $row['updated_at']
        ]);
        exit;
    }

    // =========================================================================
    // POST: Save / Sync User Collection
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (!is_array($body) || empty($body['playfab_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid request payload. playfab_id is required.']);
            exit;
        }

        $playfab_id = strtoupper(preg_replace('/[^a-zA-Z0-9_-]/', '', $body['playfab_id']));
        $owned_cards = isset($body['owned_cards']) && is_array($body['owned_cards']) ? $body['owned_cards'] : [];
        $infinity_captains = isset($body['infinity_captains']) && is_array($body['infinity_captains']) ? $body['infinity_captains'] : [];

        $owned_json = json_encode($owned_cards);
        $inf_json = json_encode(array_values(array_unique(array_map('intval', $infinity_captains))));

        $total_captains = count($owned_cards);
        $all_cids = [];
        foreach ($owned_cards as $cids) {
            if (is_array($cids)) {
                foreach ($cids as $cid) $all_cids[intval($cid)] = true;
            }
        }
        $total_cards = count($all_cids);

        $stmt = $conn->prepare("REPLACE INTO user_collections (playfab_id, owned_cards_json, infinity_captains_json, total_cards, total_captains, updated_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssii", $playfab_id, $owned_json, $inf_json, $total_cards, $total_captains);
        $stmt->execute();

        echo json_encode([
            'success' => true,
            'message' => 'Collection synced successfully',
            'playfab_id' => $playfab_id,
            'total_cards' => $total_cards,
            'total_captains' => $total_captains
        ]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
