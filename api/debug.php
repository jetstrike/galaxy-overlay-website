<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$r = $c->query("SELECT rules_version, MIN(date) as min_d, MAX(date) as max_d, COUNT(*) as c FROM analytics_matches GROUP BY rules_version");
$out = [];
while($row = $r->fetch_assoc()) {
    $out[] = $row;
}
echo json_encode($out);
?>
