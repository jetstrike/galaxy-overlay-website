<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$r = $c->query("SELECT query_type, season, min_mmr, total_matches FROM analytics_cache");
$out = [];
while($row = $r->fetch_assoc()) {
    $out[] = $row;
}
echo json_encode($out);
?>
