<?php
// We don't have test-db.php credentials here because they are not hardcoded. 
// Let's copy from get-analytics.php's db connection logic.
$db_host = 'localhost';
$db_user = 'u148286987_galaxy_overlay';
$db_pass = 'Y0d@zM1n3cr@ft!'; // From alter-schema.php
$db_name = 'u148286987_galaxy_overlay';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$start = microtime(true);
$captain = 'bo_peep';
$stmt = $conn->prepare("
    SELECT 
        c.card_name, 
        SUM(c.purchases) as total_purchases, 
        COUNT(DISTINCT m.run_id) as games_played,
        SUM(m.placement) / COUNT(DISTINCT m.run_id) as avg_placement
    FROM analytics_matches m
    JOIN analytics_cards c ON m.run_id = c.run_id
    WHERE m.captain_name = ?
    GROUP BY c.card_name
    ORDER BY games_played DESC
    LIMIT 10
");
$stmt->bind_param('s', $captain);
$stmt->execute();
$result = $stmt->get_result();
$time = microtime(true) - $start;
echo 'Time: ' . $time . " seconds\n";
while ($row = $result->fetch_assoc()) {
    echo $row['card_name'] . ': ' . $row['games_played'] . "\n";
}
?>
