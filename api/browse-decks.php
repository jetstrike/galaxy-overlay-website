<?php
require_once __DIR__ . '/db.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Author-Token");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $conn = get_db_connection();

    $captain_cid = isset($_GET['captain']) ? intval($_GET['captain']) : 0;
    $has_primer = isset($_GET['has_primer']) ? trim($_GET['has_primer']) : '';
    $sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'trending';
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';
    $author_id = isset($_GET['author_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['author_id']) : '';
    $view_mode = isset($_GET['view']) ? trim($_GET['view']) : 'community'; // 'community' vs 'my_decks'
    $page = max(1, isset($_GET['page']) ? intval($_GET['page']) : 1);
    $limit = min(50, max(6, isset($_GET['limit']) ? intval($_GET['limit']) : 24));
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];
    $types = '';

    // Visibility filter
    if ($view_mode === 'my_decks' && $author_id) {
        $where[] = "d.author_id = ?";
        $params[] = $author_id;
        $types .= 's';
    } else {
        $where[] = "d.visibility = 'public'";
    }

    // Captain filter
    if ($captain_cid > 0) {
        $where[] = "d.captain_cid = ?";
        $params[] = $captain_cid;
        $types .= 'i';
    }

    // Primer filter
    if ($has_primer === '1') {
        $where[] = "d.has_primer = 1";
    }

    // Search query filter
    if ($search !== '') {
        $where[] = "(d.name LIKE ? OR d.author_name LIKE ? OR d.summary LIKE ?)";
        $wildcard = '%' . $search . '%';
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
        $types .= 'sss';
    }

    $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    // Order By
    $order_sql = "ORDER BY d.upvote_count DESC, d.created_at DESC";
    if ($sort === 'newest') {
        $order_sql = "ORDER BY d.created_at DESC";
    } else if ($sort === 'winrate') {
        $order_sql = "ORDER BY d.in_game_win_rate DESC, d.upvote_count DESC";
    }

    // Total Count
    $cnt_sql = "SELECT COUNT(*) as total FROM website_decks d {$where_sql}";
    $cnt_stmt = $conn->prepare($cnt_sql);
    if ($types) {
        $cnt_stmt->bind_param($types, ...$params);
    }
    $cnt_stmt->execute();
    $total = intval($cnt_stmt->get_result()->fetch_assoc()['total'] ?? 0);

    // Fetch Records
    $sql = "
        SELECT d.id, d.author_id, d.author_name, d.captain_cid, d.compatible_captain_cids, d.name, d.summary, 
               d.card_cids, d.visibility, d.upvote_count, d.has_primer, d.in_game_win_rate, 
               d.created_at, c.name as captain_name
        FROM website_decks d
        LEFT JOIN analytics_cards c ON (c.cid = d.captain_cid AND c.type = 'captain')
        {$where_sql}
        {$order_sql}
        LIMIT ? OFFSET ?
    ";

    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $decks = [];
    while ($row = $res->fetch_assoc()) {
        $row['card_cids'] = json_decode($row['card_cids'], true) ?: [];
        $row['compatible_captain_cids'] = json_decode($row['compatible_captain_cids'] ?? '[]', true) ?: [];
        $decks[] = $row;
    }

    echo json_encode([
        'success' => true,
        'decks' => $decks,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => ceil($total / $limit)
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>