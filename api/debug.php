<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$r = $c->query("SELECT query_type, min_mmr, max_mmr, total_matches, last_updated FROM analytics_cache");
if (!$r) { echo "Error: " . $c->error; exit; }
$out = [];
while($row = $r->fetch_assoc()) {
    $out[] = $row;
}
echo json_encode($out);
?>
