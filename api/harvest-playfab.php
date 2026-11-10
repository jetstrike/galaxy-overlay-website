<?php
/**
 * Automated PlayFab Run Harvester for galaxy-overlay.com
 * Runs via Web Cron (e.g. Hostinger / cPanel) or CLI (php harvest-playfab.php).
 * Completely Steam-free: Authenticates directly via PlayFab REST API.
 * 
 * Captures matches into dedicated table: playfab_web_harvest
 */

set_time_limit(120);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/card_sync_helper.php';
require_once __DIR__ . '/match_ingest_helper.php';

// Auto-refresh cards from https://guide.galaxy.fun/data/cards.json periodically (once every 6 hours)
$card_sync_status = sync_cards_catalog(false);

header('Content-Type: application/json');

// Security check: Allow CLI or authorized web cron key
$CRON_SECRET = 'ouag_harvester_secure_cron_2026';
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    $provided_key = $_GET['key'] ?? $_POST['key'] ?? '';
    if ($provided_key !== $CRON_SECRET) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Forbidden: Invalid cron secret key."]);
        exit();
    }
}

// Target table for automated harvests
$TARGET_TABLE = "playfab_matches";

$TITLE_ID = "3372C";
$CUSTOM_ID = "ouag_website_harvester_prod";
$RULES_VERSION = "02.04.00";
$ACTIVE_RANKS = [0, 1, 2, 3, 4, 5];
$ENDPOINTS = ["GetRunDataRed", "GetRunDataBlue"];

$cache_dir = __DIR__ . '/../temp';
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}
$token_cache_file = $cache_dir . '/playfab_token_cache.json';

/**
 * Acquire or reuse cached PlayFab EntityToken
 */
function get_playfab_token($title_id, $custom_id, $cache_file) {
    $now = time();
    if (file_exists($cache_file)) {
        $cached = json_decode(@file_get_contents($cache_file), true);
        if ($cached && isset($cached['token']) && isset($cached['expires_at']) && $now < $cached['expires_at']) {
            return $cached['token'];
        }
    }

    $url = "https://{$title_id}.playfabapi.com/Client/LoginWithCustomID";
    $payload = json_encode([
        "TitleId" => $title_id,
        "CustomId" => $custom_id,
        "CreateAccount" => true
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        throw new Exception("PlayFab LoginWithCustomID failed with HTTP {$http_code}: {$response}");
    }

    $res_data = json_decode($response, true);
    $token = $res_data['data']['EntityToken']['EntityToken'] ?? null;
    if (!$token) {
        throw new Exception("EntityToken not present in PlayFab login response.");
    }

    // Cache token for 12 hours
    @file_put_contents($cache_file, json_encode([
        'token' => $token,
        'expires_at' => $now + (12 * 3600)
    ]));

    return $token;
}

/**
 * Execute CloudScript endpoint and decompress zlib chunk
 */
function fetch_endpoint_chunk($title_id, $endpoint, $token, $rules_version, $rank) {
    $url = "https://{$title_id}.playfabapi.com/CloudScript/ExecuteFunction";
    $payload = json_encode([
        "FunctionName" => $endpoint,
        "FunctionParameter" => [
            "rules_version" => $rules_version,
            "rank" => $rank,
            "min_mmr" => 0,
            "max_mmr" => 10000
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-EntityToken: {$token}"
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        return [];
    }

    $data = json_decode($response, true);
    $chunk = $data['data']['FunctionResult']['chunk'] ?? null;
    if (!$chunk) {
        return [];
    }

    $raw = base64_decode($chunk);
    if (!$raw) {
        return [];
    }

    $decomp = @gzuncompress($raw);
    if ($decomp === false) {
        $decomp = @gzinflate(substr($raw, 2, -4));
    }
    if ($decomp === false) {
        return [];
    }

    $runs = json_decode($decomp, true);
    if (is_string($runs)) {
        $runs = json_decode($runs, true);
    }

    return is_array($runs) ? $runs : [];
}

$start_time = microtime(true);

try {
    $token = get_playfab_token($TITLE_ID, $CUSTOM_ID, $token_cache_file);
    $conn = get_db_connection();
    $captain_name_map = load_captain_name_map($conn);

    // Check if analytics_match_decks has the new 4-column schema (first_turn, turns_on_board)
    $has_turn_cols = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM `analytics_match_decks` LIKE 'first_turn'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $has_turn_cols = true;
    }

    $total_inserted = 0;
    $total_upgraded = 0;
    $total_scanned = 0;
    $candidates = [];

    // 1. Fetch & deduplicate all PlayFab runs in memory first
    foreach ($ENDPOINTS as $ep) {
        $tag = ($ep === "GetRunDataRed") ? "playfab_red" : "playfab_blue";

        foreach ($ACTIVE_RANKS as $rk) {
            $runs = fetch_endpoint_chunk($TITLE_ID, $ep, $token, $RULES_VERSION, $rk);
            if (empty($runs)) {
                continue;
            }

            foreach ($runs as $r) {
                $rid = $r['run_id'] ?? null;
                $place = isset($r['placement']) ? (int)$r['placement'] : null;
                if (!$rid || $place === null) continue;

                $key = "{$rid}_{$place}";
                if (isset($candidates[$key])) continue;
                $total_scanned++;

                $r['_source_tag'] = $tag;
                $r['_default_rank'] = (string)$rk;
                $candidates[$key] = $r;
            }
        }
    }

    // 2. Pre-filter against playfab_matches in chunks of 500 run_ids so already-harvested matches do 0 writes
    $existing_map = [];
    if (!empty($candidates)) {
        $unique_rids = [];
        foreach ($candidates as $r) {
            $unique_rids[$r['run_id']] = true;
        }
        $rid_chunks = array_chunk(array_keys($unique_rids), 500);
        foreach ($rid_chunks as $chunk) {
            $escaped_rids = array_map(function($id) use ($conn) {
                return "'" . $conn->real_escape_string((string)$id) . "'";
            }, $chunk);
            $check_sql = "SELECT run_id, placement, source_tag FROM `{$TARGET_TABLE}` WHERE run_id IN (" . implode(',', $escaped_rids) . ")";
            $check_res = $conn->query($check_sql);
            if ($check_res) {
                while ($erow = $check_res->fetch_assoc()) {
                    $ekey = $erow['run_id'] . '_' . intval($erow['placement']);
                    $existing_map[$ekey] = $erow['source_tag'] ?? '';
                }
            }
        }
    }

    // 3. Separate into brand-new runs vs overlay-seeded runs that need upgrading
    $new_pf_rows = [];
    $new_am_rows = [];
    $new_deck_rows = [];
    $upgrade_runs = [];

    foreach ($candidates as $key => $r) {
        $existing_source = $existing_map[$key] ?? null;

        // Already harvested from PlayFab -> skip 100% (no DB writes, no JSON parsing)
        if ($existing_source !== null && $existing_source !== 'overlay') {
            continue;
        }

        $rid = (string)$r['run_id'];
        $place = (int)$r['placement'];
        $player_id = $r['player_id'] ?? null;
        $player_name = $r['player_name'] ?? null;

        $raw_date = $r['date'] ?? null;
        $date_str = null;
        if ($raw_date) {
            $ts = strtotime($raw_date);
            if ($ts) $date_str = date('Y-m-d H:i:s', $ts);
        }
        $analytics_date = $date_str ?: date('Y-m-d H:i:s');

        $rv = $r['rules_version'] ?? $RULES_VERSION;
        $tag = $r['_source_tag'];
        $mmr = isset($r['mmr']) ? (int)$r['mmr'] : 0;
        $captain = $r['captain'] ?? null;
        $captain_cid = resolve_captain_cid($r['captain_cid'] ?? 0, $captain, $captain_name_map);
        $rank_val = isset($r['rank']) ? (string)$r['rank'] : $r['_default_rank'];

        $turns_raw = $r['turns'] ?? null;
        $turns_json = compact_turns_payload($turns_raw);

        $metrics = extract_match_card_metrics($key, $turns_raw);
        foreach ($metrics['deck_rows'] as $drow) {
            $new_deck_rows[] = $drow;
        }

        if ($existing_source === 'overlay') {
            // Match arrived first via overlay_matches -> upgrade playfab_matches with PlayFab's richer server-side turns JSON
            $upgrade_runs[] = [
                'run_id' => $rid,
                'placement' => $place,
                'turns' => $turns_json,
                'source_tag' => $tag,
                'rules_version' => $rv,
                'captain_cid' => $captain_cid,
                'final_turn' => $metrics['final_turn']
            ];
        } else {
            $new_pf_rows[] = [
                $rid,
                $player_id,
                $player_name,
                $date_str,
                $rv,
                $tag,
                $place,
                $mmr,
                $captain_cid > 0 ? $captain_cid : null,
                $captain,
                $rank_val,
                $turns_json
            ];
            $new_am_rows[] = [
                $key,
                $analytics_date,
                $mmr,
                $place,
                $captain_cid,
                $rank_val,
                'playfab',
                $rv,
                $metrics['final_turn']
            ];
        }
    }

    // 4. Execute batched writes only if there are new or upgraded runs
    if (!empty($new_pf_rows) || !empty($upgrade_runs) || !empty($new_deck_rows)) {
        $conn->begin_transaction();

        if (!empty($new_pf_rows)) {
            $total_inserted = bulk_insert_ignore(
                $conn,
                $TARGET_TABLE,
                ['run_id', 'player_id', 'player_name', 'date', 'rules_version', 'source_tag', 'placement', 'mmr', 'captain_cid', 'captain', 'rank', 'turns'],
                $new_pf_rows,
                250
            );
        }

        if (!empty($new_am_rows)) {
            bulk_insert_ignore(
                $conn,
                'analytics_matches',
                ['run_id', 'date', 'mmr', 'placement', 'captain_cid', 'rank', 'source', 'rules_version', 'final_turn'],
                $new_am_rows,
                500
            );
        }

        if (!empty($upgrade_runs)) {
            $up_pf = $conn->prepare("UPDATE `{$TARGET_TABLE}` SET turns = ?, source_tag = ?, rules_version = ?, captain_cid = COALESCE(NULLIF(?, 0), captain_cid) WHERE run_id = ? AND placement = ?");
            $up_am = $conn->prepare("UPDATE `analytics_matches` SET source = 'playfab', final_turn = GREATEST(final_turn, ?), captain_cid = COALESCE(NULLIF(?, 0), captain_cid) WHERE run_id = ?");
            foreach ($upgrade_runs as $u) {
                $up_pf->bind_param("sssisi", $u['turns'], $u['source_tag'], $u['rules_version'], $u['captain_cid'], $u['run_id'], $u['placement']);
                $up_pf->execute();
                $p_run_id = $u['run_id'] . '_' . $u['placement'];
                $up_am->bind_param("iis", $u['final_turn'], $u['captain_cid'], $p_run_id);
                $up_am->execute();
                $total_upgraded++;
            }
            $up_pf->close();
            $up_am->close();
        }

        if (!empty($new_deck_rows)) {
            bulk_upsert_match_decks($conn, $new_deck_rows, $has_turn_cols, 1500);
        }

        $conn->commit();
    }

    // 5. Rolling 60-day retention for raw turns blobs (preserves match headers & analytics_match_decks forever)
    if (!empty($card_sync_status['synced'])) {
        try {
            $conn->query("UPDATE `{$TARGET_TABLE}` SET turns = NULL WHERE date < NOW() - INTERVAL 60 DAY AND turns IS NOT NULL LIMIT 5000");
        } catch (Throwable $prune_err) {
            error_log('Turns 60-day prune error: ' . $prune_err->getMessage());
        }
    }

    $conn->close();

    // Auto-sync leaderboards if cache is older than 30 minutes
    $lb_cache = __DIR__ . '/leaderboards_cache.json';
    $lb_synced = false;
    if (!file_exists($lb_cache) || (time() - filemtime($lb_cache) > 1800)) {
        try {
            require_once __DIR__ . '/harvest-leaderboard.php';
            if (function_exists('run_leaderboard_harvest')) {
                run_leaderboard_harvest(false);
                $lb_synced = true;
            }
        } catch (Throwable $lb_err) {
            error_log('Leaderboard auto-sync error: ' . $lb_err->getMessage());
        }
    }

    $elapsed = round(microtime(true) - $start_time, 2);

    echo json_encode([
        "status" => "success",
        "target_table" => $TARGET_TABLE,
        "inserted_new" => $total_inserted,
        "upgraded_overlay" => $total_upgraded,
        "scanned_runs" => $total_scanned,
        "leaderboards_synced" => $lb_synced,
        "elapsed_seconds" => $elapsed,
        "timestamp" => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli && $conn->ping()) {
        @$conn->rollback();
        @$conn->close();
    }
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "target_table" => $TARGET_TABLE,
        "message" => $e->getMessage(),
        "elapsed_seconds" => round(microtime(true) - $start_time, 2)
    ]);
}
