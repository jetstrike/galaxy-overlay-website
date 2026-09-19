<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

\System.Management.Automation.Internal.Host.InternalHost = 'localhost';
\ = 'u834540789_Galaxy';
\ = 'u834540789_Tracker';
\ = 'Slippery1!1!';

\ = new mysqli(\System.Management.Automation.Internal.Host.InternalHost, \, \, \);
if (\->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

\ = isset(\['min_mmr']) ? intval(\['min_mmr']) : 0;
\ = isset(\['max_mmr']) ? intval(\['max_mmr']) : 99999;

// Base condition for MMR
\ = "mmr >= \ AND mmr < \";

\ = [];

// 1. Total records analyzed
\ = \->query("SELECT COUNT(*) as total FROM analytics_matches WHERE \");
\['total_matches'] = \->fetch_assoc()['total'];

// Total custom decks (only overlay matches have deck_cids)
\ = \->query("SELECT COUNT(DISTINCT run_id) as total FROM analytics_match_decks md JOIN analytics_matches m ON md.run_id = m.run_id WHERE \");
\['total_custom_decks'] = \->fetch_assoc()['total'];

if (\['total_matches'] == 0) {
    echo json_encode(\);
    exit;
}

// 2. Captain Stats (Pick rate, Average Placement, Win Rate)
// Win rate is defined as Placement <= 3 (or 1 depending on game, let's provide 1st place and top 3)
\ = "
    SELECT 
        m.captain_cid,
        c.name as captain_name,
        COUNT(*) as total_picks,
        (COUNT(*) / {\['total_matches']}) * 100 as pick_rate,
        AVG(m.placement) as avg_placement,
        SUM(CASE WHEN m.placement = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100 as win_rate_1st,
        SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(*) * 100 as win_rate_top3
    FROM analytics_matches m
    LEFT JOIN analytics_cards c ON m.captain_cid = c.cid
    WHERE \ AND m.captain_cid > 0
    GROUP BY m.captain_cid, c.name
    ORDER BY total_picks DESC
";
\ = \->query(\);
\['captain_stats'] = \->fetch_all(MYSQLI_ASSOC);

// 3. Card Stats in Decks (Deck inclusion rate, Deck Win Rate)
if (\['total_custom_decks'] > 0) {
    \ = "
        SELECT 
            md.card_cid,
            c.name as card_name,
            COUNT(DISTINCT md.run_id) as total_decks,
            (COUNT(DISTINCT md.run_id) / {\['total_custom_decks']}) * 100 as deck_inclusion_rate,
            AVG(m.placement) as avg_placement,
            SUM(CASE WHEN m.placement <= 3 THEN 1 ELSE 0 END) / COUNT(DISTINCT md.run_id) * 100 as deck_win_rate_top3
        FROM analytics_match_decks md
        JOIN analytics_matches m ON md.run_id = m.run_id
        LEFT JOIN analytics_cards c ON md.card_cid = c.cid
        WHERE \
        GROUP BY md.card_cid, c.name
        HAVING total_decks > 5
        ORDER BY deck_inclusion_rate DESC
        LIMIT 50
    ";
    \ = \->query(\);
    \['deck_card_stats'] = \->fetch_all(MYSQLI_ASSOC);
} else {
    \['deck_card_stats'] = [];
}

// 4. Cards on Final Board Rate (Assuming max turn_number per run is the final board)
\ = "
    SELECT 
        t.card_cid,
        c.name as card_name,
        COUNT(DISTINCT t.run_id) as final_boards,
        (COUNT(DISTINCT t.run_id) / {\['total_matches']}) * 100 as final_board_rate,
        AVG(m.placement) as avg_placement
    FROM analytics_match_turns t
    JOIN (
        SELECT run_id, MAX(turn_number) as final_turn 
        FROM analytics_match_turns 
        GROUP BY run_id
    ) max_turns ON t.run_id = max_turns.run_id AND t.turn_number = max_turns.final_turn
    JOIN analytics_matches m ON t.run_id = m.run_id
    LEFT JOIN analytics_cards c ON t.card_cid = c.cid
    WHERE \
    GROUP BY t.card_cid, c.name
    HAVING final_boards > 5
    ORDER BY final_board_rate DESC
    LIMIT 50
";
\ = \->query(\);
\['final_board_stats'] = \->fetch_all(MYSQLI_ASSOC);

echo json_encode(\);
\->close();
?>
