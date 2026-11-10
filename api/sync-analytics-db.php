<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/match_ingest_helper.php';
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED);
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = get_db_connection();

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 1000;
    if ($limit > 5000) $limit = 5000;
    if ($limit < 1) $limit = 1000;
    $is_full = isset($_GET['full']) && $_GET['full'] == '1';

    // Scoped window for auto-sync: last 24 hours (uses idx_playfab_created) unless full backfill is requested
    $date_filter_playfab = $is_full ? "1=1" : "p.created_at >= NOW() - INTERVAL 1 DAY";

    $captain_name_map = load_captain_name_map($conn);

    $has_turn_cols = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM `analytics_match_decks` LIKE 'first_turn'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $has_turn_cols = true;
    }

    $matches_data = [];
    $decks_data = [];
    $processed = 0;

    // Catch-up any unsynced playfab_matches rows
    $q_playfab = "SELECT p.* FROM playfab_matches p 
                  LEFT JOIN analytics_matches a ON a.run_id = CONCAT(p.run_id, '_', p.placement) 
                  WHERE $date_filter_playfab AND a.run_id IS NULL AND p.placement IS NOT NULL 
                  LIMIT $limit";
    $res = $conn->query($q_playfab);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $run_id = $row['run_id'];
            $placement = intval($row['placement']);
            $player_run_id = $run_id . '_' . $placement;
            $date = !empty($row['date']) ? $row['date'] : (!empty($row['created_at']) ? $row['created_at'] : date('Y-m-d H:i:s'));
            $mmr = isset($row['mmr']) ? intval($row['mmr']) : 0;
            $captain_cid = resolve_captain_cid($row['captain_cid'] ?? 0, $row['captain'] ?? '', $captain_name_map);
            $rank = isset($row['rank']) ? $row['rank'] : '';
            $source = 'playfab';
            $rules_version = isset($row['rules_version']) ? $row['rules_version'] : '';

            $metrics = extract_match_card_metrics($player_run_id, $row['turns'] ?? null);
            $matches_data[] = [$player_run_id, $date, $mmr, $placement, $captain_cid, $rank, $source, $rules_version, $metrics['final_turn']];

            foreach ($metrics['deck_rows'] as $drow) {
                $decks_data[] = $drow;
            }

            $processed++;
        }
    }

    // Execute bulk batch inserts in single transaction (no longer writing to 28.9M-row analytics_match_turns!)
    if ($processed > 0) {
        $conn->begin_transaction();

        bulk_insert_ignore($conn, 'analytics_matches', ['run_id', 'date', 'mmr', 'placement', 'captain_cid', 'rank', 'source', 'rules_version', 'final_turn'], $matches_data, 500);
        bulk_upsert_match_decks($conn, $decks_data, $has_turn_cols, 1500);

        $conn->commit();
    }
    
    $res = $conn->query("SELECT COUNT(*) as c FROM analytics_matches");
    $total_now = $res->fetch_assoc()['c'];

    echo json_encode([
        'success' => true,
        'processed_this_batch' => $processed,
        'total_analytics_matches' => intval($total_now)
    ]);

} catch (Throwable $e) {
    if (isset($conn)) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
    }
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>
