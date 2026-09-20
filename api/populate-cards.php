<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

$cardsJson = file_get_contents('../cards.json');
$cardsData = json_decode($cardsJson, true);

if (!$cardsData) {
    die("Failed to parse cards.json\n");
}

$stmt = $conn->prepare("INSERT IGNORE INTO analytics_cards (cid, name, type, tribe, tier) VALUES (?, ?, ?, ?, ?)");

$inserted = 0;
foreach ($cardsData as $cid => $card) {
    $c = intval($cid);
    $name = isset($card['name']) ? $card['name'] : '';
    $type = isset($card['type']) ? strtolower($card['type']) : '';
    $tribe = isset($card['tribe']) ? $card['tribe'] : '';
    $tier = isset($card['tier']) ? intval($card['tier']) : 0;
    
    $stmt->bind_param("isssi", $c, $name, $type, $tribe, $tier);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        $inserted++;
    }
}

$stmt->close();
$conn->close();

echo "Inserted $inserted cards into analytics_cards.\n";
?>
