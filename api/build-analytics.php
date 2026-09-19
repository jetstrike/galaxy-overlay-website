<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

set_time_limit(300); // Allow up to 5 minutes for syncing

$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

// 1. Sync Cards Table
$cardsJson = file_get_contents('https://guide.galaxy.fun/data/cards.json');
$cardsData = json_decode($cardsJson, true);

if ($cardsData) {
    $stmt = $conn->prepare("INSERT IGNORE INTO analytics_cards (cid, name, type, tribe, tier) VALUES (?, ?, ?, ?, ?)");
    foreach ($cardsData as $card) {
        if (!isset($card['cid'])) continue;
        $cid = $card['cid'];
        $name = $card['name'] ?? null;
        $type = $card['type'] ?? null;
        $tribe = $card['tribe'] ?? null;
        $tier = isset($card['tier']) ? intval($card['tier']) : null;
        $stmt->bind_param("isssi", $cid, $name, $type, $tribe, $tier);
        $stmt->execute();
    }
}

// 2. Sync Overlay Matches
$overlayResult = $conn->query("
    SELECT * FROM overlay_matches 
    WHERE run_id NOT IN (SELECT run_id FROM analytics_matches)
    LIMIT 1000
");

$matchesStmt = $conn->prepare("INSERT INTO analytics_matches (run_id, date, mmr, placement, captain_cid, rank, source, rules_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$decksStmt = $conn->prepare("INSERT INTO analytics_match_decks (run_id, card_cid) VALUES (?, ?)");
$turnsStmt = $conn->prepare("INSERT INTO analytics_match_turns (run_id, turn_number, slot_id, card_cid) VALUES (?, ?, ?, ?)");

$overlayCount = 0;
while ($row = $overlayResult->fetch_assoc()) {
    $source = 'overlay';
    $mmr = $row['mmr'] ?? $row['mmr_start'] ?? 0;
    
    $matchesStmt->bind_param("ssiiisss", 
        $row['run_id'], $row['date'], $mmr, $row['placement'], 
        $row['captain_cid'], $row['rank'], $source, $row['rules_version']
    );
    $matchesStmt->execute();

    if (!empty($row['deck_cids'])) {
        $decks = json_decode($row['deck_cids'], true);
        if (is_array($decks)) {
            foreach ($decks as $cid) {
                $cid = intval($cid);
                $decksStmt->bind_param("si", $row['run_id'], $cid);
                $decksStmt->execute();
            }
        }
    }

    if (!empty($row['turns'])) {
        $turns = json_decode($row['turns'], true);
        if (is_array($turns)) {
            foreach ($turns as $turn_num => $turn_data) {
                $turn_int = intval($turn_num);
                if (isset($turn_data['crew']) && is_array($turn_data['crew'])) {
                    foreach ($turn_data['crew'] as $slot => $card_data) {
                        if (isset($card_data['cid'])) {
                            $cid = intval($card_data['cid']);
                            $turnsStmt->bind_param("sisi", $row['run_id'], $turn_int, $slot, $cid);
                            $turnsStmt->execute();
                        }
                    }
                }
            }
        }
    }
    $overlayCount++;
}

// 3. Sync Playfab Matches
$playfabResult = $conn->query("
    SELECT * FROM playfab_matches 
    WHERE run_id NOT IN (SELECT run_id FROM analytics_matches)
    LIMIT 1000
");

$playfabCount = 0;
while ($row = $playfabResult->fetch_assoc()) {
    $source = 'playfab';
    $mmr = $row['mmr'] ?? 0;
    
    $matchesStmt->bind_param("ssiiisss", 
        $row['run_id'], $row['date'], $mmr, $row['placement'], 
        $row['captain_cid'], $row['rank'], $source, $row['rules_version']
    );
    $matchesStmt->execute();

    if (!empty($row['turns'])) {
        $turns = json_decode($row['turns'], true);
        if (is_array($turns)) {
            foreach ($turns as $turn_num => $turn_data) {
                $turn_int = intval($turn_num);
                if (isset($turn_data['crew']) && is_array($turn_data['crew'])) {
                    foreach ($turn_data['crew'] as $slot => $card_data) {
                        if (isset($card_data['cid'])) {
                            $cid = intval($card_data['cid']);
                            $turnsStmt->bind_param("sisi", $row['run_id'], $turn_int, $slot, $cid);
                            $turnsStmt->execute();
                        }
                    }
                }
            }
        }
    }
    $playfabCount++;
}

echo json_encode([
    'success' => true,
    'synced_overlay' => $overlayCount,
    'synced_playfab' => $playfabCount,
    'message' => 'ETL Sync Complete'
]);

$conn->close();
?>