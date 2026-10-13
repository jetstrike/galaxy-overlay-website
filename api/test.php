<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
try {
    $conn = get_db_connection();
    $res1 = $conn->query("SELECT run_id FROM analytics_matches WHERE source = 'overlay' LIMIT 3");
    $overlay_samples = [];
    while($r = $res1->fetch_assoc()) $overlay_samples[] = $r['run_id'];

    $res2 = $conn->query("SELECT run_id FROM analytics_matches WHERE source = 'playfab' LIMIT 3");
    $playfab_samples = [];
    while($r = $res2->fetch_assoc()) $playfab_samples[] = $r['run_id'];

    $res3 = $conn->query("SELECT run_id, placement FROM overlay_matches LIMIT 3");
    $raw_overlay_samples = [];
    while($r = $res3->fetch_assoc()) $raw_overlay_samples[] = $r;

    echo json_encode([
        'overlay_analytics' => $overlay_samples,
        'playfab_analytics' => $playfab_samples,
        'raw_overlay' => $raw_overlay_samples
    ]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>