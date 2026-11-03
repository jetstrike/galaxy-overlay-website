<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

try {
    $conn = get_db_connection();
    echo "Connected to TiDB Cloud successfully.\n";

    // 1. website_decks
    $conn->query("CREATE TABLE IF NOT EXISTS website_decks (
        id VARCHAR(12) PRIMARY KEY,
        author_id VARCHAR(64) NOT NULL,
        author_name VARCHAR(50) NOT NULL DEFAULT 'Anonymous',
        captain_cid INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        summary VARCHAR(280) DEFAULT '',
        card_cids JSON NOT NULL,
        visibility ENUM('public', 'unlisted', 'private') NOT NULL DEFAULT 'public',
        upvote_count INT UNSIGNED NOT NULL DEFAULT 0,
        has_primer BOOLEAN NOT NULL DEFAULT FALSE,
        in_game_win_rate DECIMAL(5,2) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_captain (captain_cid),
        INDEX idx_author (author_id),
        INDEX idx_visibility_created (visibility, created_at),
        INDEX idx_visibility_upvotes (visibility, upvote_count)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Table website_decks verified/created.\n";

    // 2. imported_hdtb_decks
    $conn->query("CREATE TABLE IF NOT EXISTS imported_hdtb_decks (
        id VARCHAR(64) PRIMARY KEY,
        playfab_id VARCHAR(32) NOT NULL,
        captain_cid INT NOT NULL,
        slot_id VARCHAR(16) NOT NULL,
        deck_name VARCHAR(100) NOT NULL,
        deck_type VARCHAR(32) NOT NULL DEFAULT 'custom',
        card_cids JSON NOT NULL,
        last_used BOOLEAN NOT NULL DEFAULT FALSE,
        synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_playfab (playfab_id),
        INDEX idx_captain (captain_cid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Table imported_hdtb_decks verified/created.\n";

    // 3. deck_primers
    $conn->query("CREATE TABLE IF NOT EXISTS deck_primers (
        deck_id VARCHAR(12) PRIMARY KEY,
        guide_markdown MEDIUMTEXT NOT NULL,
        early_game_notes TEXT DEFAULT NULL,
        mid_game_notes TEXT DEFAULT NULL,
        late_game_notes TEXT DEFAULT NULL,
        matchup_notes TEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_primer_deck (deck_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Table deck_primers verified/created.\n";

    // 4. deck_card_annotations
    $conn->query("CREATE TABLE IF NOT EXISTS deck_card_annotations (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        deck_id VARCHAR(12) NOT NULL,
        card_cid INT NOT NULL,
        card_note VARCHAR(500) DEFAULT NULL,
        alternate_cids JSON DEFAULT NULL,
        substitution_reason VARCHAR(255) DEFAULT NULL,
        INDEX idx_deck_card (deck_id, card_cid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Table deck_card_annotations verified/created.\n";

    // 5. deck_upvotes
    $conn->query("CREATE TABLE IF NOT EXISTS deck_upvotes (
        deck_id VARCHAR(12) NOT NULL,
        user_token VARCHAR(64) NOT NULL,
        voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (deck_id, user_token),
        INDEX idx_deck (deck_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    echo "Table deck_upvotes verified/created.\n";

    echo "All Deck Builder tables successfully created!\n";
} catch (Throwable $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
if (isset($conn)) $conn->close();
?>