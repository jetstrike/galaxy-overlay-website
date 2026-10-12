<?php
require_once __DIR__ . '/db.php';

try {
    $conn = get_db_connection();
    $res = $conn->query('SELECT COUNT(*) as total FROM analytics_matches');
    echo 'Matches: ' . $res->fetch_assoc()['total'];
    $conn->close();
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
