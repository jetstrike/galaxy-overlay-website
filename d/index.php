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

if (!$id) {
    header("Location: /decks/browse.html", true, 302);
    exit;
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_crawler = (bool)preg_match('/(Discordbot|Twitterbot|facebookexternalhit|WhatsApp|TelegramBot|Slackbot|LinkedInBot|redditbot|Applebot|Googlebot|bingbot)/i', $ua);

if ($is_crawler) {
    $_GET['id'] = $id;
    require_once __DIR__ . '/../api/deck.php';
    exit;
}

header("Location: /decks/view.html?id=" . urlencode($id), true, 302);
exit;
?>
