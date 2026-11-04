<?php
require_once __DIR__ . '/card_sync_helper.php';
header('Content-Type: application/json');

$force = !isset($_GET['force']) || $_GET['force'] === '1' || $_GET['force'] === 'true';
$res = sync_cards_catalog($force);
echo json_encode($res, JSON_PRETTY_PRINT);
?>
