<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$deleted_turns = 0;
while(true) {
    $conn->query("DELETE FROM analytics_match_turns LIMIT 5000");
    $a = $conn->affected_rows;
    if($a <= 0) break;
    $deleted_turns += $a;
    if(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"] > 10) break;
}
$deleted_decks = 0;
while(true) {
    $conn->query("DELETE FROM analytics_match_decks LIMIT 5000");
    $a = $conn->affected_rows;
    if($a <= 0) break;
    $deleted_decks += $a;
    if(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"] > 20) break;
}
$deleted_matches = 0;
while(true) {
    $conn->query("DELETE FROM analytics_matches LIMIT 5000");
    $a = $conn->affected_rows;
    if($a <= 0) break;
    $deleted_matches += $a;
    if(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"] > 30) break;
}
echo json_encode([
    'turns' => $deleted_turns,
    'decks' => $deleted_decks,
    'matches' => $deleted_matches
]);
?>
