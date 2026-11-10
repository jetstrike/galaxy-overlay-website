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
$since_date = isset($_GET['since_date']) ? trim($_GET['since_date']) : '';
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
    $since_ts = null;
    if (!empty($since_date)) {
        $parsed_ts = strtotime($since_date);
        if ($parsed_ts !== false) {
            $since_ts = gmdate('Y-m-d H:i:s', $parsed_ts);
        }
    }

    // Use separate indexed queries instead of an OR clause so TiDB uses idx_pf_player_id_date & idx_pf_player_name_date
    $raw_rows_by_key = [];

    if (!empty($player_id)) {
        if ($since_ts !== null) {
            $sql_id = "SELECT run_id, player_id, player_name, date, rules_version, source_tag,
                              placement, mmr, captain_cid, captain, `rank`, turns, created_at
                       FROM playfab_matches
                       WHERE player_id = ? AND date > ?
                       ORDER BY date DESC
                       LIMIT ?";
            $stmt = $conn->prepare($sql_id);
            $stmt->bind_param('ssi', $player_id, $since_ts, $limit);
        } else {
            $sql_id = "SELECT run_id, player_id, player_name, date, rules_version, source_tag,
                              placement, mmr, captain_cid, captain, `rank`, turns, created_at
                       FROM playfab_matches
                       WHERE player_id = ?
                       ORDER BY date DESC
                       LIMIT ?";
            $stmt = $conn->prepare($sql_id);
            $stmt->bind_param('si', $player_id, $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $k = $r['run_id'] . '_' . ($r['placement'] ?? '');
            $raw_rows_by_key[$k] = $r;
        }
        $stmt->close();
    }

    if (!empty($player_name)) {
        if ($since_ts !== null) {
            $sql_name = "SELECT run_id, player_id, player_name, date, rules_version, source_tag,
                                placement, mmr, captain_cid, captain, `rank`, turns, created_at
                         FROM playfab_matches
                         WHERE player_name = ? AND date > ?
                         ORDER BY date DESC
                         LIMIT ?";
            $stmt = $conn->prepare($sql_name);
            $stmt->bind_param('ssi', $player_name, $since_ts, $limit);
        } else {
            $sql_name = "SELECT run_id, player_id, player_name, date, rules_version, source_tag,
                                placement, mmr, captain_cid, captain, `rank`, turns, created_at
                         FROM playfab_matches
                         WHERE player_name = ?
                         ORDER BY date DESC
                         LIMIT ?";
            $stmt = $conn->prepare($sql_name);
            $stmt->bind_param('si', $player_name, $limit);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $k = $r['run_id'] . '_' . ($r['placement'] ?? '');
            if (!isset($raw_rows_by_key[$k])) {
                $raw_rows_by_key[$k] = $r;
            }
        }
        $stmt->close();
    }

    $raw_rows = array_values($raw_rows_by_key);
    usort($raw_rows, function($a, $b) {
        return strcmp($b['date'] ?? '', $a['date'] ?? '');
    });
    if (count($raw_rows) > $limit) {
        $raw_rows = array_slice($raw_rows, 0, $limit);
    }

    $matches = [];
    foreach ($raw_rows as $row) {
        $run_id = $row['run_id'];
        $turns = !empty($row['turns']) ? json_decode($row['turns'], true) : null;
        
        $deck_cids = [];
        $maxTurn = 0;
        $normalized_turns = [];
        
        if (is_array($turns)) {
            $hasZero = isset($turns['0']) || isset($turns[0]);
            foreach ($turns as $turnNumStr => $tData) {
                $tNum = intval($turnNumStr);
                $adjustedTurn = $hasZero ? ($tNum + 1) : $tNum;
                $normalized_turns[strval($adjustedTurn)] = $tData;
                if ($adjustedTurn > $maxTurn) {
                    $maxTurn = $adjustedTurn;
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

        $rawDate = !empty($row['date']) ? $row['date'] : (!empty($row['created_at']) ? $row['created_at'] : null);
        $dateStr = $rawDate ? gmdate('Y-m-d\TH:i:s\Z', strtotime($rawDate . ' UTC')) : gmdate('Y-m-d\TH:i:s\Z');

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
            'turns' => $normalized_turns,
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
