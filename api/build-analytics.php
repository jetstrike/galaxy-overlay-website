<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

set_time_limit(300); // Allow up to 5 minutes for syncing

\System.Management.Automation.Internal.Host.InternalHost = 'localhost';
\ = 'u834540789_Galaxy';
\ = 'u834540789_Tracker';
\ = 'Slippery1!1!';

\ = new mysqli(\System.Management.Automation.Internal.Host.InternalHost, \, \, \);
if (\->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

// 1. Sync Cards Table
\ = file_get_contents('https://guide.galaxy.fun/data/cards.json');
\ = json_decode(\, true);

if (\) {
    \ = \->prepare("INSERT IGNORE INTO analytics_cards (cid, name, type, tribe, tier) VALUES (?, ?, ?, ?, ?)");
    foreach (\ as \) {
        if (!isset(\['cid'])) continue;
        \ = \['cid'];
        \ = \['name'] ?? null;
        \ = \['type'] ?? null;
        \ = \['tribe'] ?? null;
        \ = isset(\['tier']) ? intval(\['tier']) : null;
        \->bind_param("isssi", \, \, \, \, \);
        \->execute();
    }
}

// 2. Sync Overlay Matches
\ = \->query("
    SELECT * FROM overlay_matches 
    WHERE run_id NOT IN (SELECT run_id FROM analytics_matches)
    LIMIT 1000
");

\ = \->prepare("INSERT INTO analytics_matches (run_id, date, mmr, placement, captain_cid, rank, source, rules_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
\ = \->prepare("INSERT INTO analytics_match_decks (run_id, card_cid) VALUES (?, ?)");
\ = \->prepare("INSERT INTO analytics_match_turns (run_id, turn_number, slot_id, card_cid) VALUES (?, ?, ?, ?)");

\ = 0;
while (\ = \->fetch_assoc()) {
    \ = 'overlay';
    // Overlay table uses 'mmr_start' or 'mmr'
    \ = \['mmr'] ?? \['mmr_start'] ?? 0;
    
    \->bind_param("ssiiisss", 
        \['run_id'], \['date'], \, \['placement'], 
        \['captain_cid'], \['rank'], \, \['rules_version']
    );
    \->execute();

    if (!empty(\['deck_cids'])) {
        \ = json_decode(\['deck_cids'], true);
        if (is_array(\)) {
            foreach (\ as \) {
                \ = intval(\);
                \->bind_param("si", \['run_id'], \);
                \->execute();
            }
        }
    }

    if (!empty(\['turns'])) {
        \ = json_decode(\['turns'], true);
        if (is_array(\)) {
            foreach (\ as \ => \) {
                \ = intval(\);
                if (isset(\['crew']) && is_array(\['crew'])) {
                    foreach (\['crew'] as \ => \) {
                        if (isset(\['cid'])) {
                            \ = intval(\['cid']);
                            \->bind_param("sisi", \['run_id'], \, \, \);
                            \->execute();
                        }
                    }
                }
            }
        }
    }
    \++;
}

// 3. Sync Playfab Matches
\ = \->query("
    SELECT * FROM playfab_matches 
    WHERE run_id NOT IN (SELECT run_id FROM analytics_matches)
    LIMIT 1000
");

\ = 0;
while (\ = \->fetch_assoc()) {
    \ = 'playfab';
    \ = \['mmr'] ?? 0;
    
    \->bind_param("ssiiisss", 
        \['run_id'], \['date'], \, \['placement'], 
        \['captain_cid'], \['rank'], \, \['rules_version']
    );
    \->execute();

    if (!empty(\['turns'])) {
        \ = json_decode(\['turns'], true);
        if (is_array(\)) {
            foreach (\ as \ => \) {
                \ = intval(\);
                if (isset(\['crew']) && is_array(\['crew'])) {
                    foreach (\['crew'] as \ => \) {
                        if (isset(\['cid'])) {
                            \ = intval(\['cid']);
                            \->bind_param("sisi", \['run_id'], \, \, \);
                            \->execute();
                        }
                    }
                }
            }
        }
    }
    \++;
}

echo json_encode([
    'success' => true,
    'synced_overlay' => \,
    'synced_playfab' => \,
    'message' => 'ETL Sync Complete'
]);

\->close();
?>
