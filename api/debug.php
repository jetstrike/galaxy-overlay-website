<?php
require_once __DIR__ . '/db.php';
$c = get_db_connection();
$r = $c->query("SHOW CREATE TABLE analytics_cache");
if (!$r) echo $c->error;
else echo $r->fetch_assoc()['Create Table'];
?>
