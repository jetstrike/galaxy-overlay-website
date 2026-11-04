<?php
/**
 * Automated PlayFab Leaderboard Harvester for galaxy-overlay.com
 * Fetches all 8 competitive, career, and historical leaderboards from PlayFab,
 * stores them in TiDB Cloud (website_leaderboards), and writes a lightning-fast
 * local cache (api/leaderboards_cache.json).
 * 
 * Can run standalone via Web Cron / CLI or be required by other worker scripts.
 */

set_time_limit(180);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/db.php';

$CRON_SECRET = 'ouag_harvester_secure_cron_2026';
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $provided_key = $_GET['key'] ?? $_POST['key'] ?? '';
    if ($provided_key !== $CRON_SECRET) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "Forbidden: Invalid cron secret key."]);
        exit();
    }
}

const LEADERBOARD_STATS_CONFIG = [
    [
        'stat' => 'mmr_B',
        'name' => 'Current Season MMR',
        'category' => 'current',
        'group' => 'Current Season',
        'unit' => 'MMR',
        'desc' => 'Current seasonal competitive rating.'
    ],
    [
        'stat' => 'Wins_seasonal_B',
        'name' => 'Current Season Wins',
        'category' => 'current',
        'group' => 'Current Season',
        'unit' => 'Wins',
        'desc' => '1st place victories won during the current season.'
    ],
    [
        'stat' => 'top_3s_seasonal_B',
        'name' => 'Current Season Top 3s',
        'category' => 'current',
        'group' => 'Current Season',
        'unit' => 'Top 3s',
        'desc' => 'Podium finishes achieved during the current season.'
    ],
    [
        'stat' => 'matches_played_v2',
        'name' => 'All-Time Matches Played',
        'category' => 'all_time',
        'group' => 'Hall of Fame',
        'unit' => 'Matches',
        'desc' => 'Total lifetime matches played across all seasons.'
    ],
    [
        'stat' => 'top_3s_v2',
        'name' => 'All-Time Career Top 3s',
        'category' => 'all_time',
        'group' => 'Hall of Fame',
        'unit' => 'Top 3s',
        'desc' => 'Total career podium finishes across all seasons.'
    ],
    [
        'stat' => 'mmr_A',
        'name' => 'Previous Season MMR',
        'category' => 'archive',
        'group' => 'Previous Season',
        'unit' => 'MMR',
        'desc' => 'Final competitive MMR rating at the previous season conclusion.'
    ],
    [
        'stat' => 'Wins_seasonal_A',
        'name' => 'Previous Season Wins',
        'category' => 'archive',
        'group' => 'Previous Season',
        'unit' => 'Wins',
        'desc' => '1st place victories recorded during the previous season.'
    ],
    [
        'stat' => 'top_3s_seasonal_A',
        'name' => 'Previous Season Top 3s',
        'category' => 'archive',
        'group' => 'Previous Season',
        'unit' => 'Top 3s',
        'desc' => 'Podium finishes recorded during the previous season.'
    ]
];

function run_leaderboard_harvest(bool $archive_snapshot = false) {
    $title_id = '3372C';
    $custom_id = 'ouag_website_harvester_prod';
    $start_time = microtime(true);

    // 1. Authenticate with PlayFab via CustomID
    $login_url = "https://{$title_id}.playfabapi.com/Client/LoginWithCustomID";
    $login_payload = json_encode([
        'TitleId' => $title_id,
        'CustomId' => $custom_id,
        'CreateAccount' => true
    ]);

    $ch = curl_init($login_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $login_payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    $login_res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$login_res) {
        throw new Exception("PlayFab login failed (HTTP {$http_code}): {$login_res}");
    }

    $login_json = json_decode($login_res, true);
    $ticket = $login_json['data']['SessionTicket'] ?? null;
    if (!$ticket) {
        throw new Exception("SessionTicket missing from PlayFab login response.");
    }

    // 2. Connect to Database
    $conn = get_db_connection();

    $cache_data = [
        'updated_at' => gmdate('Y-m-d H:i:s \U\T\C'),
        'categories' => [
            'current' => [
                'title' => 'Current Season',
                'stats' => ['mmr_B', 'Wins_seasonal_B', 'top_3s_seasonal_B']
            ],
            'all_time' => [
                'title' => 'All-Time Legends',
                'stats' => ['matches_played_v2', 'top_3s_v2']
            ],
            'archive' => [
                'title' => 'Previous Season',
                'stats' => ['mmr_A', 'Wins_seasonal_A', 'top_3s_seasonal_A']
            ]
        ],
        'stats' => []
    ];

    $total_harvested = 0;

    $stmt_upsert = $conn->prepare("
        INSERT INTO website_leaderboards (stat_name, position, playfab_id, display_name, stat_value, version, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            playfab_id = VALUES(playfab_id),
            display_name = VALUES(display_name),
            stat_value = VALUES(stat_value),
            version = VALUES(version),
            updated_at = NOW()
    ");

    $stmt_archive = null;
    if ($archive_snapshot) {
        $stmt_archive = $conn->prepare("
            INSERT INTO website_leaderboard_archives (season_label, stat_name, position, playfab_id, display_name, stat_value, captured_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
    }

    $conn->begin_transaction();

    try {
        foreach (LEADERBOARD_STATS_CONFIG as $cfg) {
            $stat = $cfg['stat'];
            $lb_url = "https://{$title_id}.playfabapi.com/Client/GetLeaderboard";
            $lb_payload = json_encode([
                'StatisticName' => $stat,
                'StartPosition' => 0,
                'MaxResultsCount' => 100
            ]);

            $ch_lb = curl_init($lb_url);
            curl_setopt_array($ch_lb, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $lb_payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Authorization: ' . $ticket
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15
            ]);
            $lb_res = curl_exec($ch_lb);
            $lb_code = curl_getinfo($ch_lb, CURLINFO_HTTP_CODE);
            curl_close($ch_lb);

            if ($lb_code !== 200 || !$lb_res) {
                continue;
            }

            $lb_json = json_decode($lb_res, true);
            $lb_data = $lb_json['data'] ?? [];
            $entries = $lb_data['Leaderboard'] ?? [];
            $version = (int)($lb_data['Version'] ?? 0);

            $standings = [];

            foreach ($entries as $e) {
                $pos = (int)$e['Position'];
                $pid = (string)$e['PlayFabId'];
                $name = trim((string)($e['DisplayName'] ?? 'Anonymous'));
                $val = (int)$e['StatValue'];

                $stmt_upsert->bind_param("sissii", $stat, $pos, $pid, $name, $val, $version);
                $stmt_upsert->execute();

                if ($stmt_archive) {
                    $season_lbl = $cfg['group'];
                    $stmt_archive->bind_param("ssissi", $season_lbl, $stat, $pos, $pid, $name, $val);
                    $stmt_archive->execute();
                }

                $standings[] = [
                    'rank' => $pos + 1,
                    'playfab_id' => $pid,
                    'name' => $name,
                    'value' => $val
                ];
                $total_harvested++;
            }

            $cache_data['stats'][$stat] = [
                'stat' => $stat,
                'name' => $cfg['name'],
                'category' => $cfg['category'],
                'group' => $cfg['group'],
                'unit' => $cfg['unit'],
                'description' => $cfg['desc'],
                'version' => $version,
                'count' => count($standings),
                'standings' => $standings
            ];
        }

        $conn->commit();
    } catch (Throwable $t) {
        $conn->rollback();
        throw $t;
    } finally {
        $stmt_upsert->close();
        if ($stmt_archive) $stmt_archive->close();
        $conn->close();
    }

    // 3. Write cache file
    $cache_file = __DIR__ . '/leaderboards_cache.json';
    @file_put_contents($cache_file, json_encode($cache_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return [
        'status' => 'success',
        'harvested_records' => $total_harvested,
        'statistics_count' => count($cache_data['stats']),
        'elapsed_seconds' => round(microtime(true) - $start_time, 2),
        'updated_at' => $cache_data['updated_at']
    ];
}

// Standalone execution handler
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json');
    try {
        $archive = isset($_GET['archive']) && ($_GET['archive'] === '1' || $_GET['archive'] === 'true');
        $res = run_leaderboard_harvest($archive);
        echo json_encode($res, JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ], JSON_PRETTY_PRINT);
    }
}
