<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$conn->query("TRUNCATE TABLE analytics_matches");
$conn->query("TRUNCATE TABLE analytics_match_decks");
$conn->query("TRUNCATE TABLE analytics_match_turns");
echo "Truncated V2!";
?>
