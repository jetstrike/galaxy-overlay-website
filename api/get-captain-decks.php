<?php
require_once __DIR__ . '/db.php';
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

$captain = isset($_GET['captain']) ? trim($_GET['captain']) : '';
$captain_cid = isset($_GET['captain_cid']) ? intval($_GET['captain_cid']) : 0;
$min_mmr = isset($_GET['min_mmr']) ? intval($_GET['min_mmr']) : 0;
$max_mmr = isset($_GET['max_mmr']) ? intval($_GET['max_mmr']) : 99999;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5000;

if (!$captain && !$captain_cid) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing captain or captain_cid parameter']);
    exit;
}

try {
    $conn = get_db_connection();

    $where = [];
    $params = [];
    $types = '';

    if ($captain && $captain_cid) {
        $where[] = "(captain = ? OR captain_cid = ?)";
        $params[] = $captain;
        $params[] = $captain_cid;
        $types .= 'si';
    } else if ($captain) {
        $where[] = "(captain = ? OR captain_cid = (SELECT cid FROM analytics_cards WHERE name = ? AND card_type = 'captain' LIMIT 1))";
        $params[] = $captain;
        $params[] = $captain;
        $types .= 'ss';
    } else {
        $where[] = "captain_cid = ?";
        $params[] = $captain_cid;
        $types .= 'i';
    }

    $where[] = "(mmr >= ? OR mmr_start >= ?)";
    $params[] = $min_mmr;
    $params[] = $min_mmr;
    $types .= 'ii';

    if ($max_mmr < 99999) {
        $where[] = "(mmr <= ? AND (mmr_start IS NULL OR mmr_start <= ?))";
        $params[] = $max_mmr;
        $params[] = $max_mmr;
        $types .= 'ii';
    }

    $where_sql = implode(' AND ', $where);
    $sql = "SELECT run_id, mmr, mmr_start, mmr_end, placement, deck_cids, captain, captain_cid, date, rules_version 
            FROM overlay_matches 
            WHERE $where_sql 
            ORDER BY date DESC 
            LIMIT ?";
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $matches = [];
    while ($row = $res->fetch_assoc()) {
        $matches[] = $row;
    }

    echo json_encode([
        'captain' => $captain,
        'captain_cid' => $captain_cid,
        'min_mmr' => $min_mmr,
        'max_mmr' => $max_mmr,
        'count' => count($matches),
        'overlay_matches' => $matches
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>