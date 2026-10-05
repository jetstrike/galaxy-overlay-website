<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$r = $c->query("SHOW COLUMNS FROM overlay_matches");
$out = [];
while($row = $r->fetch_assoc()) {
    $out[] = $row['Field'];
}
echo json_encode($out);
?>
