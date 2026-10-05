<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$conn->query("DELETE FROM analytics_match_turns LIMIT 500000");
$deleted_turns = $conn->affected_rows;
$conn->query("DELETE FROM analytics_match_decks LIMIT 500000");
$deleted_decks = $conn->affected_rows;
$conn->query("DELETE FROM analytics_matches LIMIT 50000");
$deleted_matches = $conn->affected_rows;
echo json_encode([
    'turns' => $deleted_turns,
    'decks' => $deleted_decks,
    'matches' => $deleted_matches
]);
?>
