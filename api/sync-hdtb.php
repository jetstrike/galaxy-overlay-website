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
            $p_res = $conn->query("SELECT playfab_id FROM imported_hdtb_decks ORDER BY synced_at DESC LIMIT 1");
            if ($p_res && $p_row = $p_res->fetch_assoc()) {
                $playfab_id = $p_row['playfab_id'];
            }
        }
        if (!$playfab_id) {
            echo json_encode(['success' => true, 'playfab_id' => '', 'decks' => []]);
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

        echo json_encode(['success' => true, 'playfab_id' => $playfab_id, 'decks' => $decks]);
        exit;
    }

// POST: Ingest extracted HDTB decks
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];
        $action = isset($data['action']) ? $data['action'] : '';

        // Handle deck deletion
        if ($action === 'delete') {
            $id = !empty($data['id']) ? trim($data['id']) : '';
            $playfab_id = !empty($data['playfab_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $data['playfab_id']) : '';
            $captain_cid = intval($data['captain_cid'] ?? 0);
            $slot_id = strval($data['slot_id'] ?? '');

            if ($id) {
                $del = $conn->prepare("DELETE FROM imported_hdtb_decks WHERE id = ?");
                $del->bind_param("s", $id);
                $del->execute();
            }
            if ($playfab_id && $captain_cid && $slot_id) {
                $del2 = $conn->prepare("DELETE FROM imported_hdtb_decks WHERE playfab_id = ? AND captain_cid = ? AND slot_id = ?");
                $del2->bind_param("sis", $playfab_id, $captain_cid, $slot_id);
                $del2->execute();
            }

            echo json_encode(['success' => true]);
            exit;
        }

        $playfab_id = !empty($data['playfab_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $data['playfab_id']) : '';
        $decks = is_array($data['decks'] ?? null) ? $data['decks'] : [];

        if (!$playfab_id || empty($decks)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing playfab_id or decks array']);
            exit;
        }

        // Pre-fetch existing imported_hdtb_decks for this player in 1 query
        $existing_hdtb = [];
        $preHdtbStmt = $conn->prepare("SELECT id, deck_name, deck_type, card_cids, last_used FROM imported_hdtb_decks WHERE playfab_id = ?");
        $preHdtbStmt->bind_param("s", $playfab_id);
        $preHdtbStmt->execute();
        $preHdtbRes = $preHdtbStmt->get_result();
        while ($erow = $preHdtbRes->fetch_assoc()) {
            $ecards = json_decode($erow['card_cids'], true) ?: [];
            $esorted = $ecards;
            sort($esorted, SORT_NUMERIC);
            $erow['cards_hash'] = hash('sha256', implode(',', $esorted));
            $existing_hdtb[$erow['id']] = $erow;
        }
        $preHdtbStmt->close();

        $checkHistStmt = $conn->prepare("
            SELECT id, version, cards_hash, card_cids 
            FROM custom_deck_history 
            WHERE deck_id = ? 
            ORDER BY version DESC 
            LIMIT 1
        ");

        $insertHistStmt = $conn->prepare("
            INSERT INTO custom_deck_history 
                (deck_id, playfab_id, player_name, captain_cid, captain_name, slot_id, deck_name, deck_type, card_cids, cards_hash, version, cards_added, cards_removed, change_summary)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

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

        $player_name = !empty($data['player_name']) ? trim($data['player_name']) : '';
        $count = 0;
        foreach ($decks as $d) {
            $cap = intval($d['captain_cid'] ?? ($d['captain'] ?? 0));
            $slot = strval($d['slot_id'] ?? ($d['id'] ?? 'custom'));
            $name = trim($d['deck_name'] ?? ($d['name'] ?? ''));
            $cap_name_found = '';
            if ($name === 'Custom Deck' || $name === 'My Custom Deck' || empty($name)) {
                $c_stmt = $conn->prepare("SELECT name FROM analytics_cards WHERE cid = ? AND type = 'captain' LIMIT 1");
                if ($c_stmt) {
                    $c_stmt->bind_param("i", $cap);
                    $c_stmt->execute();
                    $c_res = $c_stmt->get_result();
                    if ($c_row = $c_res->fetch_assoc()) {
                        $cap_name_found = $c_row['name'];
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

            // History tracking
            $sorted_cards = $cards;
            sort($sorted_cards, SORT_NUMERIC);
            $cards_hash = hash('sha256', implode(',', $sorted_cards));

            if (isset($existing_hdtb[$hash_id]) && $existing_hdtb[$hash_id]['cards_hash'] === $cards_hash) {
                $count++;
                $ex = $existing_hdtb[$hash_id];
                if ($ex['deck_name'] !== $name || $ex['deck_type'] !== $type || intval($ex['last_used']) !== $last_used) {
                    $stmt->bind_param("ssissssi", $hash_id, $playfab_id, $cap, $slot, $name, $type, $cards_json, $last_used);
                    $stmt->execute();
                }
                continue;
            }

            $checkHistStmt->bind_param("s", $hash_id);
            $checkHistStmt->execute();
            $histRes = $checkHistStmt->get_result();
            $prevHist = $histRes->fetch_assoc();

            if (!$prevHist) {
                $v = 1;
                $added_json = json_encode($cards);
                $rem_json = json_encode([]);
                $summary = "Initial deck version";
                $insertHistStmt->bind_param(
                    "sssissssssisss",
                    $hash_id,
                    $playfab_id,
                    $player_name,
                    $cap,
                    $cap_name_found,
                    $slot,
                    $name,
                    $type,
                    $cards_json,
                    $cards_hash,
                    $v,
                    $added_json,
                    $rem_json,
                    $summary
                );
                $insertHistStmt->execute();
            } elseif ($prevHist['cards_hash'] !== $cards_hash) {
                $prev_c = json_decode($prevHist['card_cids'], true) ?: [];
                $added = array_values(array_diff($cards, $prev_c));
                $removed = array_values(array_diff($prev_c, $cards));
                $v = intval($prevHist['version']) + 1;
                $added_cnt = count($added);
                $rem_cnt = count($removed);
                $summary = "+{$added_cnt} / -{$rem_cnt} cards";
                $added_json = json_encode($added);
                $rem_json = json_encode($removed);
                $insertHistStmt->bind_param(
                    "sssissssssisss",
                    $hash_id,
                    $playfab_id,
                    $player_name,
                    $cap,
                    $cap_name_found,
                    $slot,
                    $name,
                    $type,
                    $cards_json,
                    $cards_hash,
                    $v,
                    $added_json,
                    $rem_json,
                    $summary
                );
                $insertHistStmt->execute();
            }

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