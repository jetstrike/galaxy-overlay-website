<?php
$db_host = 'localhost';
$db_user = 'u834540789_Tracker';
$db_pass = 'Slippery1!1!'; 
$db_name = 'u834540789_Galaxy';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }

$start = microtime(true);
$captain_cid = 10747; // bo_peep
$stmt = $conn->prepare("
    SELECT
        rc.card_cid,
        c.name as card_name,
        COUNT(rc.run_id) as games_played,
        SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(rc.run_id) * 100 as win_rate_top3
    FROM (
        SELECT run_id, card_cid
        FROM analytics_match_turns
        GROUP BY run_id, card_cid
    ) rc
    JOIN analytics_matches m ON rc.run_id = m.run_id
    LEFT JOIN analytics_cards c ON rc.card_cid = c.cid
    WHERE m.captain_cid = ?
    GROUP BY rc.card_cid
    ORDER BY games_played DESC
    LIMIT 10
");
$stmt->bind_param('i', $captain_cid);
$stmt->execute();
$result = $stmt->get_result();
$time = microtime(true) - $start;
echo "Time: {$time} seconds\n";
while ($row = $result->fetch_assoc()) {
    echo $row['card_name'] . ': ' . $row['games_played'] . "\n";
}
?>
