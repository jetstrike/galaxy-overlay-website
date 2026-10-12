<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/db.php';
    echo "db.php loaded\n";
    $conn = get_db_connection();
    echo "Connected successfully to TiDB!\n";
    $res = $conn->query("SELECT VERSION()");
    $row = $res->fetch_row();
    echo "Version: " . $row[0] . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
