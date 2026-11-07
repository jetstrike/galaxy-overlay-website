<?php
require_once __DIR__ . '/db.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-Key");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. Authenticate Request
function get_provided_admin_key() {
    if (isset($_SERVER['HTTP_X_ADMIN_KEY']) && trim($_SERVER['HTTP_X_ADMIN_KEY']) !== '') {
        return trim($_SERVER['HTTP_X_ADMIN_KEY']);
    }
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            return trim($matches[1]);
        }
    }
    if (isset($_POST['admin_key']) && trim($_POST['admin_key']) !== '') {
        return trim($_POST['admin_key']);
    }
    if (isset($_GET['admin_key']) && trim($_GET['admin_key']) !== '') {
        return trim($_GET['admin_key']);
    }
    return '';
}

$provided_key = get_provided_admin_key();
if (!$provided_key || !hash_equals($admin_secret, $provided_key)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: Invalid admin secret key']);
    exit;
}

$conn = get_db_connection();

// 2. Determine Action
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$raw_input = file_get_contents('php://input');
$post_data = [];
if (!empty($raw_input)) {
    $post_data = json_decode($raw_input, true) ?: [];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($post_data['action'])) {
    $action = trim($post_data['action']);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
}

if (!$action) {
    $action = ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'update' : 'list';
}

// 3. ACTION: verify
if ($action === 'verify') {
    echo json_encode(['success' => true, 'authenticated' => true, 'message' => 'Admin session verified']);
    exit;
}

// 4. ACTION: stats
if ($action === 'stats') {
    try {
        $stats = [
            'total' => 0,
            'public' => 0,
            'unlisted' => 0,
            'private' => 0,
            'primers' => 0,
            'recent_24h' => 0
        ];

        $res = $conn->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN visibility = 'public' THEN 1 ELSE 0 END) as public_cnt,
                SUM(CASE WHEN visibility = 'unlisted' THEN 1 ELSE 0 END) as unlisted_cnt,
                SUM(CASE WHEN visibility = 'private' THEN 1 ELSE 0 END) as private_cnt,
                SUM(CASE WHEN has_primer = 1 THEN 1 ELSE 0 END) as primers_cnt,
                SUM(CASE WHEN created_at >= NOW() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) as recent_cnt
            FROM website_decks
        ");
        if ($res && $row = $res->fetch_assoc()) {
            $stats['total'] = (int)$row['total'];
            $stats['public'] = (int)($row['public_cnt'] ?? 0);
            $stats['unlisted'] = (int)($row['unlisted_cnt'] ?? 0);
            $stats['private'] = (int)($row['private_cnt'] ?? 0);
            $stats['primers'] = (int)($row['primers_cnt'] ?? 0);
            $stats['recent_24h'] = (int)($row['recent_cnt'] ?? 0);
        }

        echo json_encode(['success' => true, 'stats' => $stats]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch stats: ' . $e->getMessage()]);
    }
    exit;
}

// 5. ACTION: set_visibility
if ($action === 'set_visibility') {
    $deck_id = trim($post_data['deck_id'] ?? $_POST['deck_id'] ?? $_GET['deck_id'] ?? '');
    $deck_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $deck_id);
    $new_vis = trim($post_data['visibility'] ?? $_POST['visibility'] ?? $_GET['visibility'] ?? '');

    if (!$deck_id || !in_array($new_vis, ['public', 'unlisted', 'private'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid deck_id or visibility value (must be public, unlisted, or private)']);
        exit;
    }

    try {
        $stmt = $conn->prepare("UPDATE website_decks SET visibility = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->bind_param("ss", $new_vis, $deck_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $chk = $conn->prepare("SELECT id, visibility FROM website_decks WHERE id = ?");
            $chk->bind_param("s", $deck_id);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => "Deck {$deck_id} not found"]);
                exit;
            }
        }

        echo json_encode([
            'success' => true,
            'deck_id' => $deck_id,
            'visibility' => $new_vis,
            'message' => "Deck visibility successfully set to {$new_vis}"
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// 6. ACTION: delete
if ($action === 'delete') {
    $deck_id = trim($post_data['deck_id'] ?? $_POST['deck_id'] ?? $_GET['deck_id'] ?? '');
    $deck_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $deck_id);

    if (!$deck_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing deck_id parameter']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // Verify deck exists
        $chk = $conn->prepare("SELECT id, name, author_name FROM website_decks WHERE id = ?");
        $chk->bind_param("s", $deck_id);
        $chk->execute();
        $deck_info = $chk->get_result()->fetch_assoc();
        if (!$deck_info) {
            $conn->rollback();
            http_response_code(404);
            echo json_encode(['error' => "Deck {$deck_id} does not exist"]);
            exit;
        }

        // Cascade delete from dependent tables
        $d1 = $conn->prepare("DELETE FROM deck_card_annotations WHERE deck_id = ?");
        $d1->bind_param("s", $deck_id);
        $d1->execute();

        $d2 = $conn->prepare("DELETE FROM deck_primers WHERE deck_id = ?");
        $d2->bind_param("s", $deck_id);
        $d2->execute();

        $d3 = $conn->prepare("DELETE FROM deck_upvotes WHERE deck_id = ?");
        $d3->bind_param("s", $deck_id);
        $d3->execute();

        $d4 = $conn->prepare("DELETE FROM website_decks WHERE id = ?");
        $d4->bind_param("s", $deck_id);
        $d4->execute();

        $conn->commit();
        echo json_encode([
            'success' => true,
            'deleted_id' => $deck_id,
            'name' => $deck_info['name'],
            'message' => "Deck '{$deck_info['name']}' ({$deck_id}) permanently deleted."
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Deletion failed: ' . $e->getMessage()]);
    }
    exit;
}

// 7. ACTION: list (Default)
try {
    $page = max(1, isset($_GET['page']) ? intval($_GET['page']) : 1);
    $limit = min(100, max(5, isset($_GET['limit']) ? intval($_GET['limit']) : 25));
    $offset = ($page - 1) * $limit;

    $search = trim($_GET['q'] ?? '');
    $vis_filter = trim($_GET['visibility'] ?? 'all');
    $primer_filter = trim($_GET['has_primer'] ?? 'all');
    $sort = trim($_GET['sort'] ?? 'newest');

    $where = [];
    $params = [];
    $types = '';

    // Visibility filter
    if (in_array($vis_filter, ['public', 'unlisted', 'private'])) {
        $where[] = "d.visibility = ?";
        $params[] = $vis_filter;
        $types .= 's';
    }

    // Primer filter
    if ($primer_filter === '1' || $primer_filter === 'primer') {
        $where[] = "d.has_primer = 1";
    } elseif ($primer_filter === '0' || $primer_filter === 'no_primer') {
        $where[] = "d.has_primer = 0";
    }

    // Search filter (id, name, author_name, summary)
    if ($search !== '') {
        $where[] = "(d.id = ? OR d.name LIKE ? OR d.author_name LIKE ? OR d.summary LIKE ?)";
        $params[] = $search;
        $wildcard = '%' . $search . '%';
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
        $types .= 'ssss';
    }

    $where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

    // Sort order
    $order_sql = "ORDER BY d.created_at DESC";
    if ($sort === 'oldest') {
        $order_sql = "ORDER BY d.created_at ASC";
    } elseif ($sort === 'upvotes') {
        $order_sql = "ORDER BY d.upvote_count DESC, d.created_at DESC";
    } elseif ($sort === 'winrate') {
        $order_sql = "ORDER BY d.in_game_win_rate DESC, d.upvote_count DESC";
    } elseif ($sort === 'name') {
        $order_sql = "ORDER BY d.name ASC";
    }

    // Total Count
    $cnt_sql = "SELECT COUNT(*) as total FROM website_decks d {$where_sql}";
    $cnt_stmt = $conn->prepare($cnt_sql);
    if ($types) {
        $cnt_stmt->bind_param($types, ...$params);
    }
    $cnt_stmt->execute();
    $total = intval($cnt_stmt->get_result()->fetch_assoc()['total'] ?? 0);

    // Query Decks
    $sql = "
        SELECT d.id, d.author_id, d.author_name, d.captain_cid, d.compatible_captain_cids,
               d.name, d.summary, d.card_cids, d.visibility, d.upvote_count, d.has_primer,
               d.in_game_win_rate, d.created_at, d.updated_at,
               MAX(c.name) as captain_name
        FROM website_decks d
        LEFT JOIN analytics_cards c ON (c.cid = d.captain_cid AND c.type = 'captain')
        {$where_sql}
        GROUP BY d.id
        {$order_sql}
        LIMIT ? OFFSET ?
    ";
    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $decks = [];
    while ($row = $result->fetch_assoc()) {
        $row['card_cids'] = json_decode($row['card_cids'], true) ?: [];
        $row['compatible_captain_cids'] = json_decode($row['compatible_captain_cids'] ?? '[]', true) ?: [];
        $row['share_url'] = "https://galaxy-overlay.com/d/{$row['id']}";
        $row['view_url'] = "/decks/view.html?id={$row['id']}";
        $decks[] = $row;
    }

    echo json_encode([
        'success' => true,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'pages' => max(1, (int)ceil($total / $limit)),
        'decks' => $decks
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query error: ' . $e->getMessage()]);
}

if (isset($conn)) $conn->close();
?>
