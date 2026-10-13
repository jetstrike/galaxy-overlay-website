<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
try {
    $conn = get_db_connection();
    $start = microtime(true);
    $q = "SELECT p.run_id, p.placement FROM playfab_matches p LEFT JOIN analytics_matches a ON a.run_id = CONCAT(p.run_id, '_', p.placement) WHERE p.created_at >= NOW() - INTERVAL 3 DAY AND a.run_id IS NULL AND p.placement IS NOT NULL LIMIT 5";
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