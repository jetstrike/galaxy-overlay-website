<?php
require_once __DIR__ . '/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

try {
    $conn = get_db_connection();
    echo "Connected successfully to TiDB.\n";
    
    // 1. Ensure unique key on analytics_cache
    try {
        $conn->query("ALTER TABLE analytics_cache ADD UNIQUE KEY idx_cache_unique (query_type, season, min_mmr, max_mmr)");
        echo "Added idx_cache_unique to analytics_cache.\n";
    } catch (Throwable $e) {
        echo "idx_cache_unique: " . $e->getMessage() . "\n";
    }
    
    // 2. Ensure indexes on analytics_matches
    try {
        $conn->query("ALTER TABLE analytics_matches ADD INDEX idx_mmr_date (mmr, date)");
        echo "Added idx_mmr_date to analytics_matches.\n";
    } catch (Throwable $e) {
        echo "idx_mmr_date: " . $e->getMessage() . "\n";
    }
    
    echo "Schema update completed.\n";
} catch (Throwable $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}
if (isset($conn)) $conn->close();
?>