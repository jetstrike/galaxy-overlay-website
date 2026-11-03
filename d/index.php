<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$parts = explode('/', trim($uri, '/'));
$id = '';
if (count($parts) >= 2 && $parts[0] === 'd') {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $parts[1]);
}
if (!$id && isset($_GET['id'])) {
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id']);
}
$_GET['id'] = $id;
require_once __DIR__ . '/../api/deck.php';
?>