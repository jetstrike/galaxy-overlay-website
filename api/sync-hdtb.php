<?php
require_once __DIR__ . '/db.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $conn = get_db_connection();

    // GET: Fetch user's imported in-game decks
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $playfab_id = isset($_GET['playfab_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['playfab_id']) : '';
        if (!$playfab_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing playfab_id parameter']);
            exit;
        }

        $stmt = $conn->prepare("
            SELECT h.*, c.name as captain_name
            FROM imported_hdtb_decks h
            LEFT JOIN analytics_cards c ON (c.cid = h.captain_cid AND c.type = 'captain')
            WHERE h.playfab_id = ?
            ORDER BY h.synced_at DESC
        ");
        $stmt->bind_param("s", $playfab_id);
        $stmt->execute();
        $res = $stmt->get_result();

        $decks = [];
        while ($row = $res->fetch_assoc()) {
            $row['card_cids'] = json_decode($row['card_cids'], true) ?: [];
            $decks[] = $row;
        }

        echo json_encode(['success' => true, 'decks' => $decks]);
        exit;
    }

    // POST: Ingest extracted HDTB decks
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];

        $playfab_id = !empty($data['playfab_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $data['playfab_id']) : '';
        $decks = is_array($data['decks'] ?? null) ? $data['decks'] : [];

        if (!$playfab_id || empty($decks)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing playfab_id or decks array']);
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO imported_hdtb_decks (id, playfab_id, captain_cid, slot_id, deck_name, deck_type, card_cids, last_used)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                deck_name = VALUES(deck_name),
                deck_type = VALUES(deck_type),
                card_cids = VALUES(card_cids),
                last_used = VALUES(last_used),
                synced_at = CURRENT_TIMESTAMP
        ");

        $count = 0;
        foreach ($decks as $d) {
            $cap = intval($d['captain_cid'] ?? ($d['captain'] ?? 0));
            $slot = strval($d['slot_id'] ?? ($d['id'] ?? 'custom'));
            $name = trim($d['deck_name'] ?? ($d['name'] ?? ''));
            if ($name === 'Custom Deck' || $name === 'My Custom Deck' || empty($name)) {
                $c_stmt = $conn->prepare("SELECT name FROM analytics_cards WHERE cid = ? AND type = 'captain' LIMIT 1");
                if ($c_stmt) {
                    $c_stmt->bind_param("i", $cap);
                    $c_stmt->execute();
                    $c_res = $c_stmt->get_result();
                    if ($c_row = $c_res->fetch_assoc()) {
                        $name = $c_row['name'];
                        if (!empty($slot) && $slot !== '1' && $slot !== 'custom') {
                            $name .= " (Deck {$slot})";
                        }
                    }
                }
                if (empty($name)) $name = "Captain #{$cap}";
            }
            $type = trim($d['deck_type'] ?? 'custom');
            $last_used = !empty($d['last_used']) ? 1 : 0;

            // Handle cards (either array or object with numeric keys '1'..'12')
            $cards_raw = $d['card_cids'] ?? ($d['cards'] ?? []);
            $cards = [];
            if (is_array($cards_raw)) {
                foreach ($cards_raw as $c) {
                    $c_int = intval($c);
                    if ($c_int > 0) $cards[] = $c_int;
                }
            }

            if (!$cap || count($cards) === 0) continue;

            $hash_id = hash('sha256', "{$playfab_id}:{$cap}:{$slot}");
            $cards_json = json_encode($cards);

            $stmt->bind_param("ssissssi", $hash_id, $playfab_id, $cap, $slot, $name, $type, $cards_json, $last_used);
            $stmt->execute();
            $count++;
        }

        echo json_encode(['success' => true, 'synced_count' => $count]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>