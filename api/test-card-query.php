<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';
$conn = new mysqli($host, $user, $pass, $db);

$min_mmr = 0;
$max_mmr = 99999;

$start = microtime(true);

$q = "
SELECT
    rc.card_cid,
    COUNT(rc.run_id) as games_played,
    AVG(rc.turns_on_board) as avg_turns_on_board,
    AVG(rc.first_appearance) as avg_first_appearance,
    SUM(CASE WHEN m.placement = 1 THEN 1 ELSE 0 END) / COUNT(rc.run_id) * 100 as win_rate_1st,
    SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(rc.run_id) * 100 as win_rate_top3
FROM (
    SELECT run_id, card_cid, MIN(turn_number) as first_appearance, COUNT(DISTINCT turn_number) as turns_on_board
    FROM analytics_match_turns
    GROUP BY run_id, card_cid
) rc
JOIN analytics_matches m ON rc.run_id = m.run_id
WHERE m.mmr >= $min_mmr AND m.mmr < $max_mmr
GROUP BY rc.card_cid
LIMIT 5
";

$res = $conn->query($q);
while ($row = $res->fetch_assoc()) {
    print_r($row);
}

$end = microtime(true);
echo "Time: " . ($end - $start) . " seconds\n";
?>
