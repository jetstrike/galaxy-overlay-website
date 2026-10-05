<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$r = $c->query("SELECT run_id, placement FROM overlay_matches LIMIT 5");
$out = [];
while($row = $r->fetch_assoc()) {
    $out[] = $row;
}
echo json_encode($out);
?>
