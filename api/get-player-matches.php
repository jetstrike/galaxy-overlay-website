<?php
/**
 * Get Player Matches API for Galaxy Tracker
 * Fetches matches for a specific player from the TiDB `playfab_matches` table.
 * Used to merge non-tracked/cloud matches into the overlay history & statistics.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/db.php';

$player_id = isset($_GET['player_id']) ? trim($_GET['player_id']) : '';
$player_name = isset($_GET['player_name']) ? trim($_GET['player_name']) : '';
$limit = isset($_GET['limit']) ? min(5000, max(1, intval($_GET['limit']))) : 1000;

if (empty($player_id) && empty($player_name)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Either player_id or player_name parameter is required.'
    ]);
    exit();
}

try {
    $conn = get_db_connection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit();
}

try {
    $sql = "SELECT 
                run_id, player_id, player_name, date, rules_version, source_tag, 
                placement, mmr, captain_cid, captain, `rank`, turns, created_at
            FROM playfab_matches 
            WHERE (player_id = ? AND ? != '') 
               OR (player_name = ? AND ? != '')
            ORDER BY date DESC 
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param('ssssi', $player_id, $player_id, $player_name, $player_name, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $matches = [];
    while ($row = $result->fetch_assoc()) {
        $run_id = $row['run_id'];
        $turns = !empty($row['turns']) ? json_decode($row['turns'], true) : null;
        
        $deck_cids = [];
        $maxTurn = 0;
        
        if (is_array($turns)) {
            foreach ($turns as $turnNumStr => $tData) {
                $tNum = intval($turnNumStr);
                if ($tNum > $maxTurn) {
                    $maxTurn = $tNum;
                }
                if (isset($tData['crew']) && is_array($tData['crew'])) {
                    foreach ($tData['crew'] as $slot => $unit) {
                        if (isset($unit['cid'])) {
                            $cid = intval($unit['cid']);
                            if ($cid > 0) {
                                $deck_cids[$cid] = true;
                            }
                        }
                    }
                }
            }
        }

        $dateStr = !empty($row['date']) ? $row['date'] : (!empty($row['created_at']) ? $row['created_at'] : date('Y-m-d H:i:s'));

        $matches[] = [
            'run_id' => $run_id,
            'player_id' => $row['player_id'],
            'player_name' => $row['player_name'],
            'date' => $dateStr,
            'placement' => intval($row['placement']),
            'mmr' => intval($row['mmr']),
            'mmr_end' => intval($row['mmr']),
            'captain_cid' => intval($row['captain_cid']),
            'captain' => $row['captain'],
            'rank' => $row['rank'],
            'turn' => $maxTurn,
            'deck_cids' => array_values(array_keys($deck_cids)),
            'turns' => $turns,
            'duration_seconds' => null,
            'avg_turn_duration_seconds' => null,
            'source' => 'cloud',
            'is_cloud_synced' => true
        ];
    }

    echo json_encode([
        'success' => true,
        'player_id' => $player_id,
        'player_name' => $player_name,
        'total' => count($matches),
        'matches' => $matches
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Query failed: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
