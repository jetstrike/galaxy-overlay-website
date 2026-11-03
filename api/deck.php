<?php
require_once __DIR__ . '/db.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Author-Token");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function get_client_token() {
    $token = isset($_SERVER['HTTP_X_AUTHOR_TOKEN']) ? trim($_SERVER['HTTP_X_AUTHOR_TOKEN']) : '';
    if (!$token && isset($_GET['author_token'])) $token = trim($_GET['author_token']);
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
}

function generate_deck_id($conn) {
    $chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $id = '';
        for ($i = 0; $i < 8; $i++) {
            $id .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $conn->prepare("SELECT id FROM website_decks WHERE id = ? LIMIT 1");
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            return $id;
        }
    }
    return bin2hex(random_bytes(4));
}

function is_discord_or_crawler() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
    $crawlers = ['discordbot', 'twitterbot', 'facebookexternalhit', 'telegrambot', 'slackbot', 'whatsapp', 'skypeuripreview'];
    foreach ($crawlers as $bot) {
        if (strpos($ua, $bot) !== false) return true;
    }
    return isset($_GET['crawler']) && $_GET['crawler'] === '1';
}

$conn = get_db_connection();

// --- 1. GET Request: View Single Deck ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    $deck_id = isset($_GET['id']) ? trim($_GET['id']) : '';
    $deck_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $deck_id);

    if (!$deck_id) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing deck id parameter']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT d.*, c.name AS captain_name 
        FROM website_decks d 
        LEFT JOIN analytics_cards c ON (c.cid = d.captain_cid AND c.type = 'captain') 
        WHERE d.id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("s", $deck_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $deck = $res->fetch_assoc();

    if (!$deck) {
        http_response_code(404);
        if (is_discord_or_crawler()) {
            echo "<!DOCTYPE html><html><head><title>Deck Not Found - Once Upon a Galaxy</title></head><body>Deck not found.</body></html>";
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Deck not found']);
        exit;
    }

    $user_token = get_client_token();

    // Check private visibility
    if ($deck['visibility'] === 'private' && $deck['author_id'] !== $user_token) {
        http_response_code(403);
        if (is_discord_or_crawler()) {
            echo "<!DOCTYPE html><html><head><title>Private Deck - Once Upon a Galaxy</title></head><body>This deck is private.</body></html>";
            exit;
        }
        header('Content-Type: application/json');
        echo json_encode(['error' => 'This deck is private']);
        exit;
    }

    // Discord / Social Media Crawler Unfurl Response
    if (is_discord_or_crawler()) {
        $deck_title = htmlspecialchars($deck['name'], ENT_QUOTES, 'UTF-8');
        $cap_name = htmlspecialchars($deck['captain_name'] ?: 'Captain', ENT_QUOTES, 'UTF-8');
        $author = htmlspecialchars($deck['author_name'] ?: 'Anonymous', ENT_QUOTES, 'UTF-8');
        $summary = htmlspecialchars($deck['summary'] ?: "12-card custom Once Upon a Galaxy deck for {$cap_name}.", ENT_QUOTES, 'UTF-8');
        $share_url = "https://galaxy-overlay.com/d/" . urlencode($deck['id']);
        $img_url = "https://galaxy-overlay.com/api/deck-image.php?id=" . urlencode($deck['id']);

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{$deck_title} ({$cap_name}) by {$author}</title>
    <meta name="description" content="{$summary}">
    <meta property="og:site_name" content="Once Upon a Galaxy Hub">
    <meta property="og:title" content="{$deck_title} ({$cap_name}) by {$author}">
    <meta property="og:description" content="{$summary}">
    <meta property="og:image" content="{$img_url}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="{$share_url}">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{$deck_title} ({$cap_name})">
    <meta name="twitter:description" content="{$summary}">
    <meta name="twitter:image" content="{$img_url}">
    <meta http-equiv="refresh" content="0;url=/decks/view.html?id={$deck['id']}">
</head>
<body>
    <p>Viewing <a href="/decks/view.html?id={$deck['id']}">{$deck_title}</a>...</p>
</body>
</html>
HTML;
        exit;
    }

    // If accessed via share route /d/<id> or directly in human browser
    $req_uri = $_SERVER['REQUEST_URI'] ?? '';
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($req_uri, '/d/') !== false || (strpos($accept, 'text/html') !== false && !isset($_GET['json']))) {
        header("Location: /decks/view.html?id=" . urlencode($deck['id']), true, 302);
        exit;
    }

    // Regular JSON response for Frontend SPA
    $deck['card_cids'] = json_decode($deck['card_cids'], true) ?: [];
    $deck['compatible_captain_cids'] = json_decode($deck['compatible_captain_cids'] ?? '[]', true) ?: [];

    // Fetch Primer
    $primer = null;
    $p_stmt = $conn->prepare("SELECT * FROM deck_primers WHERE deck_id = ? LIMIT 1");
    $p_stmt->bind_param("s", $deck_id);
    $p_stmt->execute();
    $p_res = $p_stmt->get_result();
    if ($p_row = $p_res->fetch_assoc()) {
        $primer = $p_row;
    }

    // Fetch Card Annotations & Alternates
    $annotations = [];
    $a_stmt = $conn->prepare("SELECT card_cid, card_note, alternate_cids, substitution_reason FROM deck_card_annotations WHERE deck_id = ?");
    $a_stmt->bind_param("s", $deck_id);
    $a_stmt->execute();
    $a_res = $a_stmt->get_result();
    while ($a_row = $a_res->fetch_assoc()) {
        $a_row['alternate_cids'] = json_decode($a_row['alternate_cids'], true) ?: [];
        $annotations[] = $a_row;
    }

    // Check if current user has upvoted
    $is_upvoted = false;
    if ($user_token) {
        $v_stmt = $conn->prepare("SELECT 1 FROM deck_upvotes WHERE deck_id = ? AND user_token = ? LIMIT 1");
        $v_stmt->bind_param("ss", $deck_id, $user_token);
        $v_stmt->execute();
        $is_upvoted = $v_stmt->get_result()->num_rows > 0;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'deck' => $deck,
        'primer' => $primer,
        'annotations' => $annotations,
        'is_upvoted' => $is_upvoted,
        'is_author' => ($user_token && $deck['author_id'] === $user_token)
    ]);
    exit;
}

// --- 2. POST Request: Create, Update, Delete, Upvote ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true) ?: [];
    $action = isset($data['action']) ? $data['action'] : 'save';

    $user_token = get_client_token();
    if (!$user_token && !empty($data['author_id'])) {
        $user_token = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['author_id']);
    }

    if (!$user_token) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing author session token']);
        exit;
    }

    // ACTION: UPVOTE
    if ($action === 'upvote') {
        $deck_id = isset($data['deck_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $data['deck_id']) : '';
        if (!$deck_id) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing deck_id']);
            exit;
        }

        // Check if already upvoted
        $check_stmt = $conn->prepare("SELECT 1 FROM deck_upvotes WHERE deck_id = ? AND user_token = ?");
        $check_stmt->bind_param("ss", $deck_id, $user_token);
        $check_stmt->execute();
        $already = $check_stmt->get_result()->num_rows > 0;

        if ($already) {
            $del_stmt = $conn->prepare("DELETE FROM deck_upvotes WHERE deck_id = ? AND user_token = ?");
            $del_stmt->bind_param("ss", $deck_id, $user_token);
            $del_stmt->execute();
            $upvoted = false;
        } else {
            $ins_stmt = $conn->prepare("INSERT IGNORE INTO deck_upvotes (deck_id, user_token) VALUES (?, ?)");
            $ins_stmt->bind_param("ss", $deck_id, $user_token);
            $ins_stmt->execute();
            $upvoted = true;
        }

        // Recalculate upvote count
        $cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM deck_upvotes WHERE deck_id = ?");
        $cnt_stmt->bind_param("s", $deck_id);
        $cnt_stmt->execute();
        $new_count = intval($cnt_stmt->get_result()->fetch_row()[0]);

        $upd_stmt = $conn->prepare("UPDATE website_decks SET upvote_count = ? WHERE id = ?");
        $upd_stmt->bind_param("is", $new_count, $deck_id);
        $upd_stmt->execute();

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'upvoted' => $upvoted, 'upvote_count' => $new_count]);
        exit;
    }

    // ACTION: DELETE
    if ($action === 'delete') {
        $deck_id = isset($data['deck_id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $data['deck_id']) : '';
        $chk = $conn->prepare("SELECT author_id FROM website_decks WHERE id = ? LIMIT 1");
        $chk->bind_param("s", $deck_id);
        $chk->execute();
        $cres = $chk->get_result();
        if ($cres->num_rows === 0) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Deck not found']);
            exit;
        }
        $owner = $cres->fetch_assoc()['author_id'];
        if ($owner !== $user_token) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized to delete this deck']);
            exit;
        }

        $del = $conn->prepare("DELETE FROM website_decks WHERE id = ?");
        $del->bind_param("s", $deck_id);
        $del->execute();

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'deleted_id' => $deck_id]);
        exit;
    }

    // ACTION: SAVE (Create or Update)
    if ($action === 'save') {
        $deck_id = !empty($data['id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $data['id']) : '';
        $captain_cid = intval($data['captain_cid'] ?? 0);
        $name = trim($data['name'] ?? 'Untitled Deck');
        $author_name = trim($data['author_name'] ?? 'Anonymous');
        $summary = trim($data['summary'] ?? '');
        $card_cids = is_array($data['card_cids'] ?? null) ? array_map('intval', $data['card_cids']) : [];
        $visibility = in_array($data['visibility'] ?? '', ['public', 'unlisted', 'private']) ? $data['visibility'] : 'public';
        $primer = is_array($data['primer'] ?? null) ? $data['primer'] : null;
        $has_primer = (!empty($primer) && !empty(trim($primer['guide_markdown'] ?? ''))) ? 1 : 0;
        $annotations = is_array($data['annotations'] ?? null) ? $data['annotations'] : [];

        if (!$captain_cid || count($card_cids) !== 12) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Deck requires a valid Captain and exactly 12 cards']);
            exit;
        }

        $card_cids_json = json_encode($card_cids);
        $compatible_captains = is_array($data['compatible_captain_cids'] ?? null) ? array_map('intval', $data['compatible_captain_cids']) : [];
        $compat_json = json_encode($compatible_captains);

        $conn->begin_transaction();

        try {
            if ($deck_id) {
                // Editing existing deck - verify ownership
                $chk = $conn->prepare("SELECT author_id FROM website_decks WHERE id = ? LIMIT 1");
                $chk->bind_param("s", $deck_id);
                $chk->execute();
                $cres = $chk->get_result();
                if ($cres->num_rows === 0) {
                    throw new Exception("Deck {$deck_id} does not exist");
                }
                $existing_owner = $cres->fetch_assoc()['author_id'];
                if ($existing_owner !== $user_token) {
                    http_response_code(403);
                    throw new Exception("Unauthorized to modify this deck");
                }

                $upd = $conn->prepare("
                    UPDATE website_decks 
                    SET author_name = ?, captain_cid = ?, compatible_captain_cids = ?, name = ?, summary = ?, card_cids = ?, visibility = ?, has_primer = ? 
                    WHERE id = ?
                ");
                $upd->bind_param("sisssssis", $author_name, $captain_cid, $compat_json, $name, $summary, $card_cids_json, $visibility, $has_primer, $deck_id);
                $upd->execute();
            } else {
                // Creating new deck
                $deck_id = generate_deck_id($conn);
                $ins = $conn->prepare("
                    INSERT INTO website_decks (id, author_id, author_name, captain_cid, compatible_captain_cids, name, summary, card_cids, visibility, has_primer) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->bind_param("sssisssssi", $deck_id, $user_token, $author_name, $captain_cid, $compat_json, $name, $summary, $card_cids_json, $visibility, $has_primer);
                $ins->execute();
            }

            // Save Primer
            if ($primer && !empty(trim($primer['guide_markdown'] ?? ''))) {
                $p_guide = trim($primer['guide_markdown'] ?? '');
                $p_early = trim($primer['early_game_notes'] ?? '');
                $p_mid = trim($primer['mid_game_notes'] ?? '');
                $p_late = trim($primer['late_game_notes'] ?? '');
                $p_match = trim($primer['matchup_notes'] ?? '');

                $p_stmt = $conn->prepare("
                    INSERT INTO deck_primers (deck_id, guide_markdown, early_game_notes, mid_game_notes, late_game_notes, matchup_notes) 
                    VALUES (?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        guide_markdown = VALUES(guide_markdown), 
                        early_game_notes = VALUES(early_game_notes), 
                        mid_game_notes = VALUES(mid_game_notes), 
                        late_game_notes = VALUES(late_game_notes), 
                        matchup_notes = VALUES(matchup_notes)
                ");
                $p_stmt->bind_param("ssssss", $deck_id, $p_guide, $p_early, $p_mid, $p_late, $p_match);
                $p_stmt->execute();
            } else {
                // Remove primer if cleared
                $p_del = $conn->prepare("DELETE FROM deck_primers WHERE deck_id = ?");
                $p_del->bind_param("s", $deck_id);
                $p_del->execute();
            }

            // Save Card Annotations & Alternates
            $del_ann = $conn->prepare("DELETE FROM deck_card_annotations WHERE deck_id = ?");
            $del_ann->bind_param("s", $deck_id);
            $del_ann->execute();

            if (!empty($annotations)) {
                $ins_ann = $conn->prepare("
                    INSERT INTO deck_card_annotations (deck_id, card_cid, card_note, alternate_cids, substitution_reason) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                foreach ($annotations as $ann) {
                    $c_cid = intval($ann['card_cid'] ?? 0);
                    if (!$c_cid) continue;
                    $c_note = trim($ann['card_note'] ?? '');
                    $c_subs = is_array($ann['alternate_cids'] ?? null) ? json_encode(array_map('intval', $ann['alternate_cids'])) : '[]';
                    $c_reason = trim($ann['substitution_reason'] ?? '');

                    if ($c_note || $c_subs !== '[]' || $c_reason) {
                        $ins_ann->bind_param("sisss", $deck_id, $c_cid, $c_note, $c_subs, $c_reason);
                        $ins_ann->execute();
                    }
                }
            }

            $conn->commit();

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'id' => $deck_id,
                'share_url' => "https://galaxy-overlay.com/d/{$deck_id}",
                'view_url' => "/decks/view.html?id={$deck_id}"
            ]);
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
}

if (isset($conn)) $conn->close();
?>