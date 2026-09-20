<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

$conn->query("TRUNCATE TABLE analytics_cards");

$cardsJson = file_get_contents('cards.json');
$cardsData = json_decode($cardsJson, true);

if (!$cardsData) {
    die("Failed to parse cards.json\n");
}

$stmt = $conn->prepare("INSERT INTO analytics_cards (cid, name, type, tribe, tier) VALUES (?, ?, ?, ?, ?)");

$inserted = 0;
foreach ($cardsData as $cid => $card) {
    $c = intval($cid);
    $name = isset($card['name']) ? $card['name'] : '';
    
    // Corrected to use 'card_type' instead of 'type'
    $type = isset($card['card_type']) ? strtolower($card['card_type']) : '';
    
    $tribe = '';
    if (isset($card['factions']) && is_array($card['factions'])) {
        $tribe = implode(',', $card['factions']);
    } else if (isset($card['tribe'])) {
        $tribe = $card['tribe'];
    }
    
    $tier = isset($card['tier']) ? intval($card['tier']) : 0;
    
    $stmt->bind_param("isssi", $c, $name, $type, $tribe, $tier);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        $inserted++;
    }
}

$stmt->close();
$conn->close();

echo "Truncated and re-inserted $inserted cards into analytics_cards with correct types.\n";
?>
