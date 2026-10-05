<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);
$conn->query("TRUNCATE TABLE analytics_matches");
$conn->query("TRUNCATE TABLE analytics_match_decks");
$conn->query("TRUNCATE TABLE analytics_match_turns");

// Now rebuild!
for($i = 0; $i < 200; $i++) {
    $res = json_decode(file_get_contents("https://galaxy-overlay.com/api/sync-analytics-db.php?limit=500"), true);
    if (isset($res['processed_this_batch']) && $res['processed_this_batch'] == 0) {
        break;
    }
    sleep(1); // prevent overloading server
}

echo "Database Truncated and Rebuilt completely!";
?>
