<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$c->query("ALTER TABLE analytics_cache DROP PRIMARY KEY, ADD COLUMN season VARCHAR(20) NOT NULL DEFAULT 'all' AFTER query_type, ADD PRIMARY KEY (query_type, season, min_mmr, max_mmr)");
echo $c->error;
?>
