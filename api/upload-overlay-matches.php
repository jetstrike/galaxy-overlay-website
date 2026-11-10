<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/match_ingest_helper.php';
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$SECRET_TOKEN = "your_super_secret_token_123";

$inputData = file_get_contents('php://input');
$data = json_decode($inputData, true);

if (!isset($data['token']) || $data['token'] !== $SECRET_TOKEN) {
    http_response_code(401);
    die(json_encode(["error" => "Unauthorized"]));
}

if (!isset($data['matches']) || !is_array($data['matches'])) {
    http_response_code(400);
    die(json_encode(["error" => "Invalid payload, expecting an array of matches."]));
}

try {
    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
    $conn = get_db_connection();
} catch (Exception $e) {
    http_response_code(500);
    die(json_encode(["error" => "Database connection failed", "details" => $e->getMessage()]));
}

$captain_name_map = load_captain_name_map($conn);

$has_turn_cols = false;
$colCheck = $conn->query("SHOW COLUMNS FROM `analytics_match_decks` LIKE 'first_turn'");
if ($colCheck && $colCheck->num_rows > 0) {
    $has_turn_cols = true;
}

// Pre-filter existing run_ids in overlay_matches to avoid redundant writes on bulk history uploads
$incoming_rids = [];
foreach ($data['matches'] as $m) {
    if (!empty($m['run_id'])) {
        $incoming_rids[$m['run_id']] = true;
    }
}

$existing_ov = [];
if (!empty($incoming_rids)) {
    $rid_chunks = array_chunk(array_keys($incoming_rids), 500);
    foreach ($rid_chunks as $chunk) {
        $escaped = array_map(function($id) use ($conn) {
            return "'" . $conn->real_escape_string((string)$id) . "'";
        }, $chunk);
        $chk = $conn->query("SELECT run_id, placement FROM overlay_matches WHERE run_id IN (" . implode(',', $escaped) . ")");
        if ($chk) {
            while ($r = $chk->fetch_assoc()) {
                $existing_ov[$r['run_id'] . '_' . ($r['placement'] ?? '')] = true;
                $existing_ov[$r['run_id']] = true;
            }
        }
    }
}

$stmt = $conn->prepare("INSERT IGNORE INTO overlay_matches (run_id, player_id, player_name, player_hash, date, rules_version, source_tag, placement, mmr, mmr_start, mmr_end, mmr_delta, captain_cid, captain, captain_options, `rank`, deck_cids, turns) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$inserted = 0;
$skipped = 0;
$seed_pf_rows = [];
$seed_am_rows = [];
$seed_deck_rows = [];

$conn->begin_transaction();

try {
    foreach ($data['matches'] as $match) {
        $run_id = $match['run_id'] ?? null;
        if (!$run_id) continue;

        $placement = isset($match['placement']) ? (int)$match['placement'] : null;
        $ov_key = $run_id . '_' . ($placement ?? '');
        if (isset($existing_ov[$ov_key]) || isset($existing_ov[$run_id])) {
            $skipped++;
            continue;
        }
        $existing_ov[$ov_key] = true;

        $player_id = $match['player_id'] ?? null;
        $player_name = $match['player_name'] ?? null;
        $player_hash = $match['player_hash'] ?? null;
        
        $raw_date = $match['date'] ?? null;
        $date = null;
        if ($raw_date) {
            $ts = strtotime($raw_date);
            if ($ts) $date = date('Y-m-d H:i:s', $ts);
        }
        $analytics_date = $date ?: date('Y-m-d H:i:s');

        $rules_version = $match['rules_version'] ?? '02.04.00';
        $source_tag = $match['source_tag'] ?? 'overlay';
        $mmr = isset($match['mmr']) ? (int)$match['mmr'] : null;
        $mmr_start = isset($match['mmr_start']) ? (int)$match['mmr_start'] : null;
        $mmr_end = isset($match['mmr_end']) ? (int)$match['mmr_end'] : null;
        $mmr_delta = isset($match['mmr_delta']) ? (int)$match['mmr_delta'] : null;
        $captain = $match['captain'] ?? null;
        $captain_cid = resolve_captain_cid($match['captain_cid'] ?? 0, $captain, $captain_name_map);
        $rank = $match['rank'] ?? null;
        
        $captain_options = isset($match['captain_options']) ? json_encode($match['captain_options']) : null;
        $deck_cids = isset($match['deck_cids']) ? json_encode($match['deck_cids']) : null;
        $turns_raw = $match['turns'] ?? null;
        $turns = compact_turns_payload($turns_raw);

        $stmt->bind_param("ssssssiiiiiiisssss", $run_id, $player_id, $player_name, $player_hash, $date, $rules_version, $source_tag, $placement, $mmr, $mmr_start, $mmr_end, $mmr_delta, $captain_cid, $captain, $captain_options, $rank, $deck_cids, $turns);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $inserted++;

            // Also seed playfab_matches (with source_tag='overlay') and analytics tables if placement is valid
            // INSERT IGNORE ensures we NEVER overwrite richer PlayFab turns if PlayFab already harvested the match!
            if ($placement !== null) {
                $player_run_id = $run_id . '_' . $placement;
                $metrics = extract_match_card_metrics($player_run_id, $turns_raw);

                $seed_pf_rows[] = [
                    $run_id,
                    $player_id,
                    $player_name,
                    $date,
                    $rules_version,
                    'overlay',
                    $placement,
                    $mmr ?? 0,
                    $captain_cid > 0 ? $captain_cid : null,
                    $captain,
                    (string)($rank ?? ''),
                    $turns
                ];
                $seed_am_rows[] = [
                    $player_run_id,
                    $analytics_date,
                    $mmr ?? 0,
                    $placement,
                    $captain_cid,
                    (string)($rank ?? ''),
                    'overlay',
                    $rules_version,
                    $metrics['final_turn']
                ];
                foreach ($metrics['deck_rows'] as $drow) {
                    $seed_deck_rows[] = $drow;
                }
            }
        } else {
            $skipped++;
        }
    }

    if (!empty($seed_pf_rows)) {
        bulk_insert_ignore(
            $conn,
            'playfab_matches',
            ['run_id', 'player_id', 'player_name', 'date', 'rules_version', 'source_tag', 'placement', 'mmr', 'captain_cid', 'captain', 'rank', 'turns'],
            $seed_pf_rows,
            250
        );
    }
    if (!empty($seed_am_rows)) {
        bulk_insert_ignore(
            $conn,
            'analytics_matches',
            ['run_id', 'date', 'mmr', 'placement', 'captain_cid', 'rank', 'source', 'rules_version', 'final_turn'],
            $seed_am_rows,
            500
        );
    }
    if (!empty($seed_deck_rows)) {
        if ($has_turn_cols) {
            bulk_insert_ignore($conn, 'analytics_match_decks', ['run_id', 'card_cid', 'first_turn', 'turns_on_board'], $seed_deck_rows, 1000);
        } else {
            $two_col = array_map(function($r) { return [$r[0], $r[1]]; }, $seed_deck_rows);
            bulk_insert_ignore($conn, 'analytics_match_decks', ['run_id', 'card_cid'], $two_col, 1000);
        }
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    die(json_encode(["error" => "Transaction failed", "details" => $e->getMessage()]));
}

$stmt->close();
$conn->close();

echo json_encode([
    "success" => true,
    "inserted" => $inserted,
    "skipped" => $skipped,
    "message" => "Batch processed successfully."
]);
?>
