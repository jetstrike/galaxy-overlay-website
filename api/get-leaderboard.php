<?php
/**
 * Leaderboards API Endpoint for Once Upon a Galaxy
 * Serves live leaderboards bundle or individual statistics with search & pagination.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$cache_file = __DIR__ . '/leaderboards_cache.json';
$stat = $_GET['stat'] ?? '';
$search = trim($_GET['search'] ?? '');
$limit = isset($_GET['limit']) ? max(1, min(100, intval($_GET['limit']))) : 100;

// Fast-path: If cache exists and no deep search query is supplied
if (file_exists($cache_file)) {
    $raw = @file_get_contents($cache_file);
    if ($raw) {
        $cached = json_decode($raw, true);
        if ($cached && isset($cached['stats'])) {
            // If requesting all statistics
            if (!$stat || $stat === 'all') {
                if ($search !== '') {
                    $search_lower = mb_strtolower($search);
                    foreach ($cached['stats'] as &$st) {
                        $st['standings'] = array_values(array_filter($st['standings'], function($p) use ($search_lower) {
                            return (mb_strpos(mb_strtolower($p['name']), $search_lower) !== false) ||
                                   (mb_strpos(mb_strtolower($p['playfab_id']), $search_lower) !== false);
                        }));
                        $st['count'] = count($st['standings']);
                    }
                }
                echo json_encode(['success' => true, 'data' => $cached], JSON_UNESCAPED_SLASHES);
                exit(0);
            }

            // If requesting a specific statistic
            if (isset($cached['stats'][$stat])) {
                $stat_obj = $cached['stats'][$stat];
                if ($search !== '') {
                    $search_lower = mb_strtolower($search);
                    $stat_obj['standings'] = array_values(array_filter($stat_obj['standings'], function($p) use ($search_lower) {
                        return (mb_strpos(mb_strtolower($p['name']), $search_lower) !== false) ||
                               (mb_strpos(mb_strtolower($p['playfab_id']), $search_lower) !== false);
                    }));
                }
                if ($limit < count($stat_obj['standings'])) {
                    $stat_obj['standings'] = array_slice($stat_obj['standings'], 0, $limit);
                }
                $stat_obj['count'] = count($stat_obj['standings']);
                echo json_encode([
                    'success' => true,
                    'updated_at' => $cached['updated_at'],
                    'statistic' => $stat_obj
                ], JSON_UNESCAPED_SLASHES);
                exit(0);
            }
        }
    }
}

// Fallback to database query if cache is missing or stat not found in cache
require_once __DIR__ . '/db.php';

try {
    $conn = get_db_connection();

    $where = [];
    $params = [];
    $types = '';

    if ($stat && $stat !== 'all') {
        $where[] = "stat_name = ?";
        $params[] = $stat;
        $types .= 's';
    }

    if ($search !== '') {
        $where[] = "(display_name LIKE ? OR playfab_id LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }

    $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT stat_name, position, playfab_id, display_name, stat_value, version, updated_at 
            FROM website_leaderboards 
            {$where_sql} 
            ORDER BY stat_name, position ASC 
            LIMIT ?";
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $results = [];
    $latest_update = null;

    while ($row = $res->fetch_assoc()) {
        $sname = $row['stat_name'];
        if (!isset($results[$sname])) {
            $results[$sname] = [];
        }
        $results[$sname][] = [
            'rank' => (int)$row['position'] + 1,
            'playfab_id' => $row['playfab_id'],
            'name' => $row['display_name'],
            'value' => (int)$row['stat_value']
        ];
        if (!$latest_update || $row['updated_at'] > $latest_update) {
            $latest_update = $row['updated_at'];
        }
    }
    $stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'from_db' => true,
        'updated_at' => $latest_update ?: gmdate('Y-m-d H:i:s \U\T\C'),
        'results' => $results
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
