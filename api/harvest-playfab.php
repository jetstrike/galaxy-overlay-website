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

    // Ensure the dedicated comparison table exists
    $table_sql = "CREATE TABLE IF NOT EXISTS `{$TARGET_TABLE}` (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        run_id VARCHAR(64) NOT NULL,
        player_id VARCHAR(64) DEFAULT NULL,
        player_name VARCHAR(64) DEFAULT NULL,
        date DATETIME DEFAULT NULL,
        rules_version VARCHAR(32) DEFAULT NULL,
        source_tag VARCHAR(32) DEFAULT NULL,
        placement INT DEFAULT NULL,
        mmr INT DEFAULT NULL,
        captain_cid INT DEFAULT NULL,
        captain VARCHAR(64) DEFAULT NULL,
        `rank` VARCHAR(32) DEFAULT NULL,
        turns LONGTEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_harvest_run_place (run_id, placement),
        KEY idx_harvest_date (date),
        KEY idx_harvest_created (created_at),
        KEY idx_harvest_player (player_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $conn->query($table_sql);

    $sql = "INSERT INTO `{$TARGET_TABLE}` (
                run_id, player_id, player_name, date, rules_version, source_tag,
                placement, mmr, captain_cid, captain, `rank`, turns
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                rules_version = VALUES(rules_version),
                source_tag = VALUES(source_tag),
                captain = VALUES(captain),
                `rank` = VALUES(`rank`)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $total_inserted = 0;
    $total_scanned = 0;
    $seen = [];

    $conn->begin_transaction();

    foreach ($ENDPOINTS as $ep) {
        $tag = ($ep === "GetRunDataRed") ? "playfab_red" : "playfab_blue";

        foreach ($ACTIVE_RANKS as $rk) {
            $runs = fetch_endpoint_chunk($TITLE_ID, $ep, $token, $RULES_VERSION, $rk);
            if (empty($runs)) {
                continue;
            }

            foreach ($runs as $r) {
                $rid = $r['run_id'] ?? null;
                $place = $r['placement'] ?? null;
                if (!$rid) continue;

                $key = "{$rid}_{$place}";
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $total_scanned++;

                $player_id = $r['player_id'] ?? null;
                $player_name = $r['player_name'] ?? null;

                $raw_date = $r['date'] ?? null;
                $date_str = null;
                if ($raw_date) {
                    $ts = strtotime($raw_date);
                    if ($ts) $date_str = date('Y-m-d H:i:s', $ts);
                }

                $rv = $r['rules_version'] ?? $RULES_VERSION;
                $mmr = isset($r['mmr']) ? (int)$r['mmr'] : null;
                $captain_cid = isset($r['captain_cid']) ? (int)$r['captain_cid'] : null;
                $captain = $r['captain'] ?? null;
                $rank_val = isset($r['rank']) ? (string)$r['rank'] : (string)$rk;

                $turns = isset($r['turns']) ? (is_string($r['turns']) ? $r['turns'] : json_encode($r['turns'])) : null;

                $stmt->bind_param(
                    "ssssssiiisss",
                    $rid,
                    $player_id,
                    $player_name,
                    $date_str,
                    $rv,
                    $tag,
                    $place,
                    $mmr,
                    $captain_cid,
                    $captain,
                    $rank_val,
                    $turns
                );

                if ($stmt->execute()) {
                    if ($stmt->affected_rows === 1) {
                        $total_inserted++;
                    }
                }
            }
        }
    }

    $conn->commit();
    $stmt->close();
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
