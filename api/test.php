<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');
try {
    $conn = get_db_connection();
    
    // Check Bing Wu in overlay_matches as requested by user
    $q1 = "SELECT run_id, mmr, mmr_start, mmr_end, placement, deck_cids, captain, date, rules_version 
           FROM overlay_matches 
           WHERE (captain = 'Bing Wu' OR captain = 'bing_wu' OR captain_cid = 4052) 
             AND (mmr >= 4000 OR mmr_start >= 4000)
           ORDER BY date DESC";
    $res1 = $conn->query($q1);
    $overlay = [];
    while($r = $res1->fetch_assoc()) $overlay[] = $r;

    // Also check playfab_matches
    $q2 = "SELECT COUNT(*) as c FROM playfab_matches WHERE (captain = 'Bing Wu' OR captain = 'bing_wu' OR captain_cid = 4052) AND mmr >= 4000";
    $res2 = $conn->query($q2);
    $pf_count = $res2->fetch_assoc()['c'];

    echo json_encode([
        'overlay_count' => count($overlay),
        'playfab_count' => intval($pf_count),
        'overlay_matches' => $overlay
    ]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>