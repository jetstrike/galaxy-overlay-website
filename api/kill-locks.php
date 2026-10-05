<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$r = $c->query("SHOW PROCESSLIST");
while($row = $r->fetch_assoc()){
    if ($row['Time'] > 30 && $row['Command'] != 'Sleep' && strpos($row['Info'], 'analytics_') !== false) {
        echo "Killing " . $row['Id'] . "\n";
        $c->query("KILL " . $row['Id']);
    }
}
echo "Done";
?>
