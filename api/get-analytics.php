<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

try {
    $conn = new mysqli($host, $user, $pass, $db);

    $min_mmr = isset($_GET['min_mmr']) ? intval($_GET['min_mmr']) : 0;
    $max_mmr = isset($_GET['max_mmr']) ? intval($_GET['max_mmr']) : 99999;

    // Base condition for MMR
    $mmr_cond = "mmr >= $min_mmr AND mmr < $max_mmr";

    $response = [];

    // 1. Total records analyzed
    $res = $conn->query("SELECT COUNT(*) as total FROM analytics_matches WHERE $mmr_cond");
    $response['total_matches'] = intval($res->fetch_assoc()['total']);

    // Total custom decks (only overlay matches have deck_cids)
    $res = $conn->query("SELECT COUNT(DISTINCT run_id) as total FROM analytics_match_decks md JOIN analytics_matches m ON md.run_id = m.run_id WHERE $mmr_cond");
    $response['total_custom_decks'] = intval($res->fetch_assoc()['total']);

    if ($response['total_matches'] == 0) {
        echo json_encode($response);
        exit;
    }

    // 2. Captain Stats (Pick rate, Average Placement, Win Rate)
    $query = "
        SELECT 
            m.captain_cid,
            c.name as captain_name,
            COUNT(*) as total_picks,
            (COUNT(*) / {$response['total_matches']}) * 100 as pick_rate,
            AVG(m.placement) as avg_placement,
            SUM(CASE WHEN m.placement = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100 as win_rate_1st,
            SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(*) * 100 as win_rate_top3
        FROM analytics_matches m
        LEFT JOIN analytics_cards c ON m.captain_cid = c.cid
        WHERE $mmr_cond AND m.captain_cid > 0
        GROUP BY m.captain_cid, c.name
        ORDER BY total_picks DESC
    ";
    $res = $conn->query($query);
    $captain_stats = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $captain_stats[] = $row;
        }
    }
    $response['captain_stats'] = $captain_stats;

    // 3. Card Stats in Decks (Deck inclusion rate, Deck Win Rate)
    $deck_card_stats = [];
    if ($response['total_custom_decks'] > 0) {
        $query = "
            SELECT 
                md.card_cid,
                c.name as card_name,
                COUNT(DISTINCT md.run_id) as total_decks,
                (COUNT(DISTINCT md.run_id) / {$response['total_custom_decks']}) * 100 as deck_inclusion_rate,
                AVG(m.placement) as avg_placement,
                SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(DISTINCT md.run_id) * 100 as deck_win_rate_top3
            FROM analytics_match_decks md
            JOIN analytics_matches m ON md.run_id = m.run_id
            LEFT JOIN analytics_cards c ON md.card_cid = c.cid
            WHERE $mmr_cond
            GROUP BY md.card_cid, c.name
            HAVING total_decks > 5
            ORDER BY deck_inclusion_rate DESC
            LIMIT 50
        ";
        $res = $conn->query($query);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $deck_card_stats[] = $row;
            }
        }
    }
    $response['deck_card_stats'] = $deck_card_stats;

    // 4. Cards on Final Board Rate
    $query = "
        SELECT 
            t.card_cid,
            c.name as card_name,
            COUNT(DISTINCT t.run_id) as final_boards,
            (COUNT(DISTINCT t.run_id) / {$response['total_matches']}) * 100 as final_board_rate,
            AVG(m.placement) as avg_placement
        FROM analytics_match_turns t
        JOIN (
            SELECT run_id, MAX(turn_number) as final_turn 
            FROM analytics_match_turns 
            GROUP BY run_id
        ) max_turns ON t.run_id = max_turns.run_id AND t.turn_number = max_turns.final_turn
        JOIN analytics_matches m ON t.run_id = m.run_id
        LEFT JOIN analytics_cards c ON t.card_cid = c.cid
        WHERE $mmr_cond
        GROUP BY t.card_cid, c.name
        HAVING final_boards > 5
        ORDER BY final_board_rate DESC
        LIMIT 50
    ";
    $res = $conn->query($query);
    $final_board_stats = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $final_board_stats[] = $row;
        }
    }
    $response['final_board_stats'] = $final_board_stats;

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database exception: ' . $e->getMessage()]);
}

$conn->close();
?>