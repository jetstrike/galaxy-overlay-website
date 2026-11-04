<?php
require_once __DIR__ . '/card_sync_helper.php';

header('Content-Type: application/json');

$res = sync_cards_catalog(true);
echo json_encode($res, JSON_PRETTY_PRINT);
?>
