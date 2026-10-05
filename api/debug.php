<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$r = $c->query("SHOW INDEXES FROM analytics_match_turns");
$out = [];
while($row = $r->fetch_assoc()) {
    $out[] = $row['Key_name'];
}
echo json_encode($out);
?>
