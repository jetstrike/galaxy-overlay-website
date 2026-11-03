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

// Check cache if file is newer than last deck update
$deck_mtime = strtotime($deck['updated_at'] ?? 'now');
if (file_exists($cache_file) && filemtime($cache_file) >= $deck_mtime && !isset($_GET['refresh'])) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cache_file);
    exit;
}

// Load Cards Catalog for metadata
$cards_db = [];
$catalog_path = __DIR__ . '/cards.json';
if (file_exists($catalog_path)) {
    $cards_db = json_decode(file_get_contents($catalog_path), true) ?: [];
}

// Dimensions
$W = 1200;
$H = 630;
$im = imagecreatetruecolor($W, $H);
imagealphablending($im, true);
imagesavealpha($im, true);

// Color Tokens
$c_bg_dark = imagecolorallocate($im, 14, 17, 27);
$c_bg_card = imagecolorallocate($im, 24, 28, 43);
$c_border  = imagecolorallocate($im, 45, 52, 75);
$c_white   = imagecolorallocate($im, 255, 255, 255);
$c_gold    = imagecolorallocate($im, 255, 209, 102);
$c_cyan    = imagecolorallocate($im, 78, 205, 196);
$c_muted   = imagecolorallocate($im, 160, 170, 195);
$c_black   = imagecolorallocate($im, 0, 0, 0);

// Rarity Colors
$rarity_colors = [
    'common'    => imagecolorallocate($im, 150, 160, 180),
    'uncommon'  => imagecolorallocate($im, 46, 196, 182),
    'rare'      => imagecolorallocate($im, 67, 97, 238),
    'epic'      => imagecolorallocate($im, 157, 78, 221),
    'legendary' => imagecolorallocate($im, 255, 183, 3)
];

// Fill Background with sleek gradient
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

// Helper to draw text
function draw_text($im, $text, $x, $y, $size, $color, $font_path, $has_ttf) {
    if ($has_ttf) {
        imagettftext($im, $size, 0, (int)$x, (int)$y, $color, $font_path, $text);
    } else {
        $gd_size = min(5, max(1, (int)round($size / 4)));
        imagestring($im, $gd_size, (int)$x, (int)($y - $size), $text, $color);
    }
}

// Draw Header
$deck_name = $deck['name'] ?: 'Untitled Deck';
if (strlen($deck_name) > 36) $deck_name = substr($deck_name, 0, 33) . '...';
draw_text($im, $deck_name, 50, 70, 32, $c_white, $font_path, $has_ttf);

$cap_name = $deck['captain_name'] ?: 'Captain #' . $deck['captain_cid'];
$author_name = $deck['author_name'] ?: 'Anonymous';
$sub_text = "Captain: {$cap_name}  •  Author: {$author_name}  •  12 Cards";
draw_text($im, $sub_text, 50, 105, 16, $c_cyan, $font_path, $has_ttf);

// Header badge right
$brand_badge = "Once Upon a Galaxy  •  Deck Primer";
draw_text($im, $brand_badge, 880, 70, 14, $c_gold, $font_path, $has_ttf);

// Card Grid
$card_cids = json_decode($deck['card_cids'], true) ?: [];
$card_w = 168;
$card_h = 205;
$gap_x = 20;
$gap_y = 20;
$start_x = 46;
$start_y = 135;

for ($i = 0; $i < 12; $i++) {
    $cid = $card_cids[$i] ?? null;
    $row = (int)($i / 6);
    $col = $i % 6;
    $cx = $start_x + $col * ($card_w + $gap_x);
    $cy = $start_y + $row * ($card_h + $gap_y);

    $card_info = $cards_db[strval($cid)] ?? [
        'name' => "Card #{$cid}",
        'tier' => 1,
        'cost' => 1,
        'rarity' => 'common'
    ];

    $rarity = strtolower($card_info['rarity'] ?? 'common');
    $r_col = $rarity_colors[$rarity] ?? $rarity_colors['common'];

    // Card background & rarity border
    imagefilledrectangle($im, $cx, $cy, $cx + $card_w, $cy + $card_h, $c_bg_card);
    imagerectangle($im, $cx, $cy, $cx + $card_w, $cy + $card_h, $r_col);

    // Card Thumbnail Art (fetch from static.galaxy.fun or cache)
    $img_rendered = false;
    $card_key = $card_info['cardKey'] ?? ($card_info['key'] ?? null);
    if ($card_key) {
        $img_file = __DIR__ . "/../temp/{$card_key}__bronze__240.webp";
        if (!file_exists($img_file)) {
            $remote = "https://static.galaxy.fun/cards/{$card_key}__bronze__240.webp";
            $data = @file_get_contents($remote);
            if ($data) {
                @file_put_contents($img_file, $data);
            }
        }
        if (file_exists($img_file) && function_exists('imagecreatefromwebp')) {
            $art = @imagecreatefromwebp($img_file);
            if ($art) {
                imagecopyresampled($im, $art, $cx + 6, $cy + 28, 0, 0, $card_w - 12, $card_h - 68, imagesx($art), imagesy($art));
                imagedestroy($art);
                $img_rendered = true;
            }
        }
    }

    if (!$img_rendered) {
        // Fallback inner card rect
        $inner_bg = imagecolorallocate($im, 32, 38, 58);
        imagefilledrectangle($im, $cx + 6, $cy + 28, $cx + $card_w - 6, $cy + $card_h - 40, $inner_bg);
    }

    // Top Bar: Tier & Cost
    $tier = $card_info['tier'] ?? 1;
    $cost = $card_info['cost'] ?? 1;

    // Tier badge left
    imagefilledrectangle($im, $cx + 4, $cy + 4, $cx + 42, $cy + 24, $c_black);
    draw_text($im, "T{$tier}", $cx + 10, $cy + 19, 12, $c_gold, $font_path, $has_ttf);

    // Cost circle right
    imagefilledrectangle($im, $cx + $card_w - 42, $cy + 4, $cx + $card_w - 4, $cy + 24, $c_black);
    draw_text($im, "{$cost}g", $cx + $card_w - 36, $cy + 19, 12, $c_cyan, $font_path, $has_ttf);

    // Bottom Bar: Card Name
    $c_name = $card_info['name'] ?? "Card #{$cid}";
    if (strlen($c_name) > 17) $c_name = substr($c_name, 0, 15) . '..';
    imagefilledrectangle($im, $cx, $cy + $card_h - 36, $cx + $card_w, $cy + $card_h, $c_black);
    imagerectangle($im, $cx, $cy + $card_h - 36, $cx + $card_w, $cy + $card_h, $r_col);
    draw_text($im, $c_name, $cx + 8, $cy + $card_h - 14, 11, $c_white, $font_path, $has_ttf);
}

// Footer
$footer_url = "https://galaxy-overlay.com/d/{$deck['id']}";
draw_text($im, $footer_url, 50, 605, 14, $c_muted, $font_path, $has_ttf);
draw_text($im, "View Full Primer, Matchups & Substitutions", 820, 605, 13, $c_cyan, $font_path, $has_ttf);

// Save to cache & output
imagepng($im, $cache_file);
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($im);
imagedestroy($im);
if (isset($conn)) $conn->close();
?>