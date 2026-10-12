<?php
require_once __DIR__ . '/db.php';
$host = 'localhost';
$db = 'u834540789_Galaxy';
$user = 'u834540789_Tracker';
$pass = 'Slippery1!1!';

try {
    $conn = get_db_connection();
    
    // Add columns
    $conn->query("ALTER TABLE analytics_cards ADD COLUMN rarity VARCHAR(32)");
    $conn->query("ALTER TABLE analytics_cards ADD COLUMN is_collectible TINYINT(1)");
    
    echo "Columns added successfully.\n";
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
