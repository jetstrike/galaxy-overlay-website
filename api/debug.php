<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$r = $c->query("SHOW CREATE TABLE analytics_cache");
echo $r->fetch_assoc()['Create Table'];
?>
