<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$r = $c->query("SELECT DATE_FORMAT(date, '%Y-%m') as m, COUNT(*) as c FROM analytics_matches GROUP BY m");
$out = [];
while($row = $r->fetch_assoc()) {
    $out[] = $row;
}
echo json_encode($out);
?>
