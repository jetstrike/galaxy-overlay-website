<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
if (!$conn->query("DELETE FROM analytics_match_decks")) { echo json_encode(['error1' => $conn->error]); exit; }
if (!$conn->query("DELETE FROM analytics_match_turns")) { echo json_encode(['error2' => $conn->error]); exit; }
if (!$conn->query("DELETE FROM analytics_matches")) { echo json_encode(['error3' => $conn->error]); exit; }
echo "Deleted!!!";
?>
