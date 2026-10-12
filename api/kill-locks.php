<?php
require_once __DIR__ . '/db.php';
$c = get_db_connection();
$r = $c->query("SHOW PROCESSLIST");
while($row = $r->fetch_assoc()){
    if ($row['Time'] > 30 && $row['Command'] != 'Sleep' && strpos($row['Info'], 'analytics_') !== false) {
        echo "Killing " . $row['Id'] . "\n";
        $c->query("KILL " . $row['Id']);
    }
}
echo "Done";
?>
