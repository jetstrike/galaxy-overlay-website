<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$conn->query("DELETE FROM analytics_match_turns");
$conn->query("DELETE FROM analytics_match_decks");
$conn->query("DELETE FROM analytics_matches");
echo "Cleaned!";
?>
