<?php
require_once __DIR__ . '/db.php';

$deck_id = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id']) : '';

if (!$deck_id) {
    http_response_code(400);
    die('Missing deck id');
}

$cache_dir = __DIR__ . '/../assets/deck_images';
if (!file_exists($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}
$cache_file = "{$cache_dir}/{$deck_id}.png";

// Query DB for deck details
$conn = get_db_connection();
$stmt = $conn->prepare("
    SELECT d.id, d.name, d.author_name, d.captain_cid, d.card_cids, d.updated_at, c.name as captain_name
    FROM website_decks d
    LEFT JOIN analytics_cards c ON (c.cid = d.captain_cid AND c.type = 'captain')
    WHERE d.id = ? LIMIT 1
");
$stmt->bind_param("s", $deck_id);
$stmt->execute();
$deck = $stmt->get_result()->fetch_assoc();

if (!$deck) {
    http_response_code(404);
    die('Deck not found');
}

// Check cache
$deck_mtime = strtotime($deck['updated_at'] ?? 'now');
if (file_exists($cache_file) && filemtime($cache_file) >= $deck_mtime && !isset($_GET['refresh'])) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cache_file);
    exit;
}

// Load Cards Catalog
$cards_db = [];
$catalog_path = __DIR__ . '/cards.json';
if (file_exists($catalog_path)) {
    $cards_db = json_decode(file_get_contents($catalog_path), true) ?: [];
}

// Image Loader Helper (GD + Imagick fallback)
function load_image_res($remote_url, $cache_name) {
    $temp_dir = __DIR__ . '/../temp';
    if (!file_exists($temp_dir)) @mkdir($temp_dir, 0755, true);
    $local_path = "{$temp_dir}/{$cache_name}";

    if (!file_exists($local_path)) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 5, 'user_agent' => 'Mozilla/5.0 GalaxyDeckImage']
        ]);
        $data = @file_get_contents($remote_url, false, $ctx);
        if ($data) @file_put_contents($local_path, $data);
    }

    if (!file_exists($local_path) || filesize($local_path) === 0) return null;

    if (function_exists('imagecreatefromwebp')) {
        $res = @imagecreatefromwebp($local_path);
        if ($res) return $res;
    }

    if (extension_loaded('imagick')) {
        try {
            $imagick = new Imagick($local_path);
            $imagick->setImageFormat('png');
            $blob = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();
            return @imagecreatefromstring($blob);
        } catch (Throwable $e) {}
    }

    return null;
}

// Dimensions
$W = 1200;
$H = 630;
$im = imagecreatetruecolor($W, $H);
imagealphablending($im, true);
imagesavealpha($im, true);

// Color Tokens
$c_bg_card = imagecolorallocate($im, 24, 28, 43);
$c_white   = imagecolorallocate($im, 255, 255, 255);
$c_gold    = imagecolorallocate($im, 255, 209, 102);
$c_cyan    = imagecolorallocate($im, 78, 205, 196);
$c_muted   = imagecolorallocate($im, 160, 170, 195);
$c_black   = imagecolorallocate($im, 0, 0, 0);

$rarity_colors = [
    'common'    => imagecolorallocate($im, 142, 154, 175),
    'uncommon'  => imagecolorallocate($im, 46, 196, 182),
    'rare'      => imagecolorallocate($im, 58, 134, 255),
    'epic'      => imagecolorallocate($im, 157, 78, 221),
    'legendary' => imagecolorallocate($im, 255, 183, 3)
];

// Gradient Background
for ($y = 0; $y < $H; $y++) {
    $ratio = $y / $H;
    $r = (int)(13 + $ratio * 12);
    $g = (int)(17 + $ratio * 14);
    $b = (int)(28 + $ratio * 22);
    $line_col = imagecolorallocate($im, $r, $g, $b);
    imageline($im, 0, $y, $W, $y, $line_col);
}

// Top Accent Glow line
$accent_col = imagecolorallocate($im, 99, 102, 241);
imageline($im, 0, 0, $W, 0, $accent_col);
imageline($im, 0, 1, $W, 1, $accent_col);

$font_path = __DIR__ . '/../assets/fonts/arial.ttf';
$has_ttf = file_exists($font_path) && function_exists('imagettftext');

function draw_t($im, $text, $x, $y, $size, $color, $font_path, $has_ttf) {
    if ($has_ttf) {
        imagettftext($im, $size, 0, (int)$x, (int)$y, $color, $font_path, $text);
    } else {
        $gd_size = min(5, max(1, (int)round($size / 4)));
        imagestring($im, $gd_size, (int)$x, (int)($y - $size), $text, $color);
    }
}

// Captain Avatar in Header
$cap_card = $cards_db[strval($deck['captain_cid'])] ?? null;
$cap_key = $cap_card['card_key'] ?? ($cap_card['key'] ?? 'wizard_headmaster');
$cap_url = "https://static.galaxy.fun/cards/{$cap_key}__default__240.webp";
$cap_res = load_image_res($cap_url, "{$cap_key}__default__240.webp");

if ($cap_res) {
    imagecopyresampled($im, $cap_res, 50, 24, 0, 0, 80, 80, imagesx($cap_res), imagesy($cap_res));
    imagerectangle($im, 49, 23, 131, 105, $c_gold);
    imagedestroy($cap_res);
}

// Deck Name & Subtitle
$deck_name = $deck['name'] ?: 'Untitled Deck';
if (strlen($deck_name) > 34) $deck_name = substr($deck_name, 0, 31) . '...';
draw_t($im, $deck_name, 150, 60, 30, $c_white, $font_path, $has_ttf);

$cap_name = $deck['captain_name'] ?: ($cap_card['name'] ?? 'Captain #' . $deck['captain_cid']);
$author_name = $deck['author_name'] ?: 'Anonymous';
$sub_text = "Captain: {$cap_name}  •  Author: {$author_name}  •  12 Cards";
draw_t($im, $sub_text, 150, 95, 15, $c_cyan, $font_path, $has_ttf);

// Brand Badge
draw_t($im, "Once Upon a Galaxy  •  Deck Primer", 880, 60, 14, $c_gold, $font_path, $has_ttf);

// 12 Cards Grid
$card_cids = json_decode($deck['card_cids'], true) ?: [];
$card_w = 168;
$card_h = 205;
$gap_x = 20;
$gap_y = 20;
$start_x = 46;
$start_y = 125;

for ($i = 0; $i < 12; $i++) {
    $cid = $card_cids[$i] ?? null;
    $row = (int)($i / 6);
    $col = $i % 6;
    $cx = $start_x + $col * ($card_w + $gap_x);
    $cy = $start_y + $row * ($card_h + $gap_y);

    $card_info = $cards_db[strval($cid)] ?? [
        'name' => "Card #{$cid}",
        'rarity' => 'common'
    ];

    $rarity = strtolower($card_info['rarity'] ?? 'common');
    $r_col = $rarity_colors[$rarity] ?? $rarity_colors['common'];

    // Fill Card Rect
    imagefilledrectangle($im, $cx, $cy, $cx + $card_w, $cy + $card_h, $c_bg_card);
    imagerectangle($im, $cx, $cy, $cx + $card_w, $cy + $card_h, $r_col);

    // Card Thumbnail Art
    $card_key = $card_info['card_key'] ?? ($card_info['cardKey'] ?? ($card_info['key'] ?? null));
    $art_drawn = false;

    if ($card_key) {
        $thumb_url = "https://static.galaxy.fun/cards/{$card_key}__bronze__240.webp";
        $art_res = load_image_res($thumb_url, "{$card_key}__bronze__240.webp");
        if ($art_res) {
            imagecopyresampled($im, $art_res, $cx + 4, $cy + 4, 0, 0, $card_w - 8, $card_h - 40, imagesx($art_res), imagesy($art_res));
            imagedestroy($art_res);
            $art_drawn = true;
        }
    }

    if (!$art_drawn) {
        $inner_bg = imagecolorallocate($im, 32, 38, 58);
        imagefilledrectangle($im, $cx + 4, $cy + 4, $cx + $card_w - 4, $cy + $card_h - 40, $inner_bg);
    }

    // Top Rarity Label Pill
    $rarity_label = ucfirst($rarity);
    imagefilledrectangle($im, $cx + 6, $cy + 6, $cx + 84, $cy + 24, $c_black);
    draw_t($im, $rarity_label, $cx + 10, $cy + 20, 10, $r_col, $font_path, $has_ttf);

    // Bottom Bar: Card Name
    $c_name = $card_info['name'] ?? "Card #{$cid}";
    if (strlen($c_name) > 17) $c_name = substr($c_name, 0, 15) . '..';
    imagefilledrectangle($im, $cx, $cy + $card_h - 34, $cx + $card_w, $cy + $card_h, $c_black);
    imagerectangle($im, $cx, $cy + $card_h - 34, $cx + $card_w, $cy + $card_h, $r_col);
    draw_t($im, $c_name, $cx + 8, $cy + $card_h - 12, 11, $c_white, $font_path, $has_ttf);
}

// Footer
$footer_url = "https://galaxy-overlay.com/d/{$deck['id']}";
draw_t($im, $footer_url, 50, 605, 14, $c_muted, $font_path, $has_ttf);
draw_t($im, "View Full Primer, Matchups & Substitutions", 820, 605, 13, $c_cyan, $font_path, $has_ttf);

imagepng($im, $cache_file);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($im);
imagedestroy($im);
if (isset($conn)) $conn->close();
?>