<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
try {
    $conn = get_db_connection();
    $start = microtime(true);
    $q = "SELECT o.run_id, o.placement, o.date FROM overlay_matches o LEFT JOIN analytics_matches a ON a.run_id = CONCAT(o.run_id, '_', o.placement) WHERE a.run_id IS NULL AND o.placement IS NOT NULL LIMIT 5";
    $res = $conn->query($q);
    $rows = [];
    while($r = $res->fetch_assoc()) $rows[] = $r;
    $time = microtime(true) - $start;

    echo json_encode([
        'time_seconds' => $time,
        'count' => count($rows),
        'rows' => $rows
    ]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>