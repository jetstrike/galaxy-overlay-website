<?php
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

$mysqli = mysqli_init();
if (!$mysqli) {
    die('mysqli_init failed');
}

if (!$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2)) {
    die('Setting MYSQLI_OPT_CONNECT_TIMEOUT failed');
}

echo "Attempting to connect...\n";
if (!$mysqli->real_connect($host, $user, $pass, $db)) {
    die('Connect Error (' . mysqli_connect_errno() . ') '
            . mysqli_connect_error());
}

echo 'Success... ' . $mysqli->host_info . "\n";
$mysqli->close();
?>
