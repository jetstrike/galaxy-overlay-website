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

$SECRET_TOKEN = "your_super_secret_token_123";

// Helper to load cards dictionary
function get_cards_catalog() {
    static $catalog = null;
    if ($catalog !== null) return $catalog;
    $catalog = [];
    $cards_file = __DIR__ . '/cards.json';
    if (file_exists($cards_file)) {
        $json = json_decode(file_get_contents($cards_file), true);
        if (is_array($json)) {
            $catalog = $json;
        }
    }
    return $catalog;
}

function resolve_card_details($cids, $catalog) {
    $result = [];
    if (!is_array($cids)) return $result;
    foreach ($cids as $cid) {
        $cid_int = intval($cid);
        if ($cid_int <= 0) continue;
        $cid_str = strval($cid_int);
        if (isset($catalog[$cid_str])) {
            $c = $catalog[$cid_str];
            $result[] = [
                'cid' => $cid_int,
                'name' => $c['name'] ?? "Card #{$cid_int}",
                'card_key' => $c['card_key'] ?? '',
                'card_type' => $c['card_type'] ?? 'character',
                'rarity' => $c['rarity'] ?? 'Common'
            ];
        } else {
            $result[] = [
                'cid' => $cid_int,
                'name' => "Card #{$cid_int}",
                'card_key' => '',
                'card_type' => 'character',
                'rarity' => 'Common'
            ];
        }
    }
    return $result;
}

try {
    $conn = get_db_connection();

    // Ensure custom_deck_history table exists
    $conn->query("CREATE TABLE IF NOT EXISTS custom_deck_history (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        deck_id VARCHAR(64) NOT NULL,
        playfab_id VARCHAR(32) NOT NULL,
        player_name VARCHAR(64) DEFAULT NULL,
        captain_cid INT NOT NULL,
        captain_name VARCHAR(64) DEFAULT NULL,
        slot_id VARCHAR(16) NOT NULL,
        deck_name VARCHAR(100) NOT NULL,
        deck_type VARCHAR(32) NOT NULL DEFAULT 'user',
        card_cids JSON NOT NULL,
        cards_hash VARCHAR(64) NOT NULL,
        version INT NOT NULL DEFAULT 1,
        cards_added JSON DEFAULT NULL,
        cards_removed JSON DEFAULT NULL,
        change_summary VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_deck_version (deck_id, version),
        INDEX idx_playfab_captain (playfab_id, captain_cid),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Ensure imported_hdtb_decks table exists
    $conn->query("CREATE TABLE IF NOT EXISTS imported_hdtb_decks (
        id VARCHAR(64) PRIMARY KEY,
        playfab_id VARCHAR(32) NOT NULL,
        captain_cid INT NOT NULL,
        slot_id VARCHAR(16) NOT NULL,
        deck_name VARCHAR(100) NOT NULL,
        deck_type VARCHAR(32) NOT NULL DEFAULT 'custom',
        card_cids JSON NOT NULL,
        last_used BOOLEAN NOT NULL DEFAULT FALSE,
        synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_playfab (playfab_id),
        INDEX idx_captain (captain_cid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // =========================================================================
    // GET: Query Deck History or User Decks
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = isset($_GET['action']) ? trim($_GET['action']) : 'history';
        $playfab_id = isset($_GET['playfab_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['playfab_id']) : '';
        $captain_cid = isset($_GET['captain_cid']) ? intval($_GET['captain_cid']) : 0;
        $slot_id = isset($_GET['slot_id']) ? trim($_GET['slot_id']) : '';
        $deck_id = isset($_GET['deck_id']) ? preg_replace('/[^a-f0-9]/', '', $_GET['deck_id']) : '';

        $catalog = get_cards_catalog();

        if ($action === 'history') {
            if (!$deck_id && $playfab_id && $captain_cid) {
                $slot = $slot_id ?: 'user';
                $deck_id = hash('sha256', "{$playfab_id}:{$captain_cid}:{$slot}");
            }

            if ($deck_id) {
                $stmt = $conn->prepare("
                    SELECT id, deck_id, playfab_id, player_name, captain_cid, captain_name, slot_id,
                           deck_name, deck_type, card_cids, cards_hash, version,
                           cards_added, cards_removed, change_summary, created_at
                    FROM custom_deck_history
                    WHERE deck_id = ?
                    ORDER BY version DESC
                ");
                $stmt->bind_param("s", $deck_id);
            } elseif ($playfab_id && $captain_cid) {
                $stmt = $conn->prepare("
                    SELECT id, deck_id, playfab_id, player_name, captain_cid, captain_name, slot_id,
                           deck_name, deck_type, card_cids, cards_hash, version,
                           cards_added, cards_removed, change_summary, created_at
                    FROM custom_deck_history
                    WHERE playfab_id = ? AND captain_cid = ?
                    ORDER BY version DESC
                ");
                $stmt->bind_param("si", $playfab_id, $captain_cid);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Missing deck_id or playfab_id + captain_cid parameters.']);
                exit;
            }

            $stmt->execute();
            $res = $stmt->get_result();
            $history = [];
            while ($row = $res->fetch_assoc()) {
                $cids = json_decode($row['card_cids'], true) ?: [];
                $added = json_decode($row['cards_added'], true) ?: [];
                $removed = json_decode($row['cards_removed'], true) ?: [];

                $history[] = [
                    'id' => intval($row['id']),
                    'deck_id' => $row['deck_id'],
                    'version' => intval($row['version']),
                    'deck_name' => $row['deck_name'],
                    'captain_cid' => intval($row['captain_cid']),
                    'captain_name' => $row['captain_name'] ?: ($catalog[strval($row['captain_cid'])]['name'] ?? "Captain #{$row['captain_cid']}"),
                    'slot_id' => $row['slot_id'],
                    'deck_type' => $row['deck_type'],
                    'card_cids' => $cids,
                    'cards_added' => $added,
                    'cards_removed' => $removed,
                    'cards_added_details' => resolve_card_details($added, $catalog),
                    'cards_removed_details' => resolve_card_details($removed, $catalog),
                    'change_summary' => $row['change_summary'],
                    'created_at' => $row['created_at']
                ];
            }

            echo json_encode([
                'success' => true,
                'deck_id' => $deck_id,
                'count' => count($history),
                'history' => $history
            ]);
            exit;
        }

        // Return recent changelog / timeline across ALL custom decks for this player
        if ($action === 'all_history') {
            if (!$playfab_id) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing playfab_id']);
                exit;
            }

            $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
            $stmt = $conn->prepare("
                SELECT id, deck_id, playfab_id, player_name, captain_cid, captain_name, slot_id,
                       deck_name, deck_type, card_cids, cards_hash, version,
                       cards_added, cards_removed, change_summary, created_at
                FROM custom_deck_history
                WHERE playfab_id = ?
                ORDER BY created_at DESC, version DESC
                LIMIT ?
            ");
            $stmt->bind_param("si", $playfab_id, $limit);
            $stmt->execute();
            $res = $stmt->get_result();

            $history = [];
            while ($row = $res->fetch_assoc()) {
                $added = json_decode($row['cards_added'], true) ?: [];
                $removed = json_decode($row['cards_removed'], true) ?: [];

                $history[] = [
                    'id' => intval($row['id']),
                    'deck_id' => $row['deck_id'],
                    'version' => intval($row['version']),
                    'deck_name' => $row['deck_name'],
                    'captain_cid' => intval($row['captain_cid']),
                    'captain_name' => $row['captain_name'] ?: ($catalog[strval($row['captain_cid'])]['name'] ?? "Captain #{$row['captain_cid']}"),
                    'slot_id' => $row['slot_id'],
                    'deck_type' => $row['deck_type'],
                    'card_cids' => json_decode($row['card_cids'], true) ?: [],
                    'cards_added' => $added,
                    'cards_removed' => $removed,
                    'cards_added_details' => resolve_card_details($added, $catalog),
                    'cards_removed_details' => resolve_card_details($removed, $catalog),
                    'change_summary' => $row['change_summary'],
                    'created_at' => $row['created_at']
                ];
            }

            echo json_encode([
                'success' => true,
                'playfab_id' => $playfab_id,
                'count' => count($history),
                'history' => $history
            ]);
            exit;
        }

        // Return latest active custom decks for player with version numbers
        if ($action === 'list') {
            if (!$playfab_id) {
                http_response_code(400);
                echo json_encode(['error' => 'Missing playfab_id']);
                exit;
            }

            $stmt = $conn->prepare("
                SELECT h.id, h.playfab_id, h.captain_cid, h.slot_id, h.deck_name, h.deck_type,
                       h.card_cids, h.last_used, h.synced_at,
                       COALESCE(hist.version, 1) as latest_version,
                       hist.change_summary, hist.created_at as version_created_at
                FROM imported_hdtb_decks h
                LEFT JOIN custom_deck_history hist ON hist.deck_id = h.id AND hist.version = (
                    SELECT MAX(sub.version) FROM custom_deck_history sub WHERE sub.deck_id = h.id
                )
                WHERE h.playfab_id = ?
                ORDER BY h.synced_at DESC
            ");
            $stmt->bind_param("s", $playfab_id);
            $stmt->execute();
            $res = $stmt->get_result();

            $decks = [];
            while ($row = $res->fetch_assoc()) {
                $row['captain_cid'] = intval($row['captain_cid']);
                $row['card_cids'] = json_decode($row['card_cids'], true) ?: [];
                $row['captain_name'] = $catalog[strval($row['captain_cid'])]['name'] ?? "Captain #{$row['captain_cid']}";
                $row['latest_version'] = intval($row['latest_version']);
                $row['last_used'] = (bool)$row['last_used'];
                $decks[] = $row;
            }

            echo json_encode([
                'success' => true,
                'playfab_id' => $playfab_id,
                'decks' => $decks
            ]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['error' => "Unsupported action '{$action}'"]);
        exit;
    }

    // =========================================================================
    // POST: Ingest / Upload Custom Decks with Automatic Version Tracking
    // =========================================================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];

        $playfab_id = !empty($data['playfab_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $data['playfab_id']) : '';
        $player_name = !empty($data['player_name']) ? trim($data['player_name']) : '';
        $decks = is_array($data['decks'] ?? null) ? $data['decks'] : [];

        if (!$playfab_id || empty($decks)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing playfab_id or non-empty decks array.']);
            exit;
        }

        $catalog = get_cards_catalog();

        $checkStmt = $conn->prepare("
            SELECT id, version, cards_hash, card_cids 
            FROM custom_deck_history 
            WHERE deck_id = ? 
            ORDER BY version DESC 
            LIMIT 1
        ");

        $insertHistoryStmt = $conn->prepare("
            INSERT INTO custom_deck_history 
                (deck_id, playfab_id, player_name, captain_cid, captain_name, slot_id, deck_name, deck_type, card_cids, cards_hash, version, cards_added, cards_removed, change_summary)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $upsertHdtbStmt = $conn->prepare("
            INSERT INTO imported_hdtb_decks 
                (id, playfab_id, captain_cid, slot_id, deck_name, deck_type, card_cids, last_used, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                deck_name = VALUES(deck_name),
                deck_type = VALUES(deck_type),
                card_cids = VALUES(card_cids),
                last_used = VALUES(last_used),
                synced_at = CURRENT_TIMESTAMP
        ");

        $added_count = 0;
        $updated_count = 0;
        $unchanged_count = 0;
        $synced_hdtb_count = 0;

        foreach ($decks as $d) {
            $cap = intval($d['captain_cid'] ?? ($d['captain'] ?? 0));
            $slot = strval($d['slot_id'] ?? ($d['id'] ?? 'user'));
            if (empty($slot)) $slot = 'user';
            
            $type = trim($d['deck_type'] ?? 'user');
            $last_used = !empty($d['last_used']) ? 1 : 0;

            // Extract cards
            $cards_raw = $d['card_cids'] ?? ($d['cards'] ?? []);
            $cards = [];
            if (is_array($cards_raw)) {
                foreach ($cards_raw as $c) {
                    $c_int = intval($c);
                    if ($c_int > 0) $cards[] = $c_int;
                }
            }

            if (!$cap || count($cards) === 0) {
                continue;
            }

            // Determine captain name & deck name
            $captain_name = !empty($d['captain_name']) ? trim($d['captain_name']) : '';
            if (empty($captain_name) && isset($catalog[strval($cap)])) {
                $captain_name = $catalog[strval($cap)]['name'];
            }
            if (empty($captain_name)) {
                $captain_name = "Captain #{$cap}";
            }

            $deck_name = trim($d['deck_name'] ?? ($d['name'] ?? ''));
            if (empty($deck_name) || $deck_name === 'Custom Deck' || $deck_name === 'My Custom Deck') {
                $deck_name = "{$captain_name} (Custom)";
            }

            $deck_id = hash('sha256', "{$playfab_id}:{$cap}:{$slot}");

            // Compute deterministic hash of cards regardless of slot ordering
            $sorted_cards = $cards;
            sort($sorted_cards, SORT_NUMERIC);
            $cards_hash = hash('sha256', implode(',', $sorted_cards));

            $cards_json = json_encode($cards);

            // Check previous version
            $checkStmt->bind_param("s", $deck_id);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();
            $prev = $checkRes->fetch_assoc();

            if (!$prev) {
                // Initial Version (v1)
                $version = 1;
                $cards_added_json = json_encode($cards);
                $cards_removed_json = json_encode([]);
                $change_summary = "Initial deck version";

                $insertHistoryStmt->bind_param(
                    "sssissssssisss",
                    $deck_id,
                    $playfab_id,
                    $player_name,
                    $cap,
                    $captain_name,
                    $slot,
                    $deck_name,
                    $type,
                    $cards_json,
                    $cards_hash,
                    $version,
                    $cards_added_json,
                    $cards_removed_json,
                    $change_summary
                );
                $insertHistoryStmt->execute();
                $added_count++;
            } else {
                // Check if card contents changed
                if ($prev['cards_hash'] === $cards_hash) {
                    $unchanged_count++;
                } else {
                    // Deck contents changed! Create next version
                    $prev_cards = json_decode($prev['card_cids'], true) ?: [];
                    $added = array_values(array_diff($cards, $prev_cards));
                    $removed = array_values(array_diff($prev_cards, $cards));
                    $version = intval($prev['version']) + 1;

                    $added_cnt = count($added);
                    $rem_cnt = count($removed);
                    $change_summary = "+{$added_cnt} / -{$rem_cnt} cards";

                    $cards_added_json = json_encode($added);
                    $cards_removed_json = json_encode($removed);

                    $insertHistoryStmt->bind_param(
                        "sssissssssisss",
                        $deck_id,
                        $playfab_id,
                        $player_name,
                        $cap,
                        $captain_name,
                        $slot,
                        $deck_name,
                        $type,
                        $cards_json,
                        $cards_hash,
                        $version,
                        $cards_added_json,
                        $cards_removed_json,
                        $change_summary
                    );
                    $insertHistoryStmt->execute();
                    $updated_count++;
                }
            }

            // Sync to imported_hdtb_decks table
            $upsertHdtbStmt->bind_param(
                "ssissssi",
                $deck_id,
                $playfab_id,
                $cap,
                $slot,
                $deck_name,
                $type,
                $cards_json,
                $last_used
            );
            $upsertHdtbStmt->execute();
            $synced_hdtb_count++;
        }

        echo json_encode([
            'success' => true,
            'playfab_id' => $playfab_id,
            'initial_versions_created' => $added_count,
            'new_versions_created' => $updated_count,
            'unchanged_decks' => $unchanged_count,
            'total_processed' => $synced_hdtb_count
        ]);
        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

if (isset($conn)) $conn->close();
?>
