<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
try {
    $conn = get_db_connection();
    $res = $conn->query("SELECT source, COUNT(*) as cnt, MIN(date) as min_d, MAX(date) as max_d FROM analytics_matches GROUP BY source");
    $out = [];
    while($r = $res->fetch_assoc()) $out[] = $r;
    echo json_encode($out);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>