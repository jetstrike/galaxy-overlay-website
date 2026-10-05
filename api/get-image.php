<?php
// get-image.php - Caches images from static.galaxy.fun

$key = isset($_GET['key']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['key']) : '';
if (!$key) {
    http_response_code(400);
    die('Missing key');
}

// Define paths
$cache_dir = __DIR__ . '/../assets/cards';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0777, true);
}

$filename = $key . '.webp';
$local_path = $cache_dir . '/' . $filename;

// If we already have it cached, just serve it
if (file_exists($local_path)) {
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=86400');
    readfile($local_path);
    exit;
}

// Otherwise, fetch it from the official CDN
$remote_url = "https://static.galaxy.fun/cards/{$key}__default__300.webp";

$ch = curl_init($remote_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GalaxyTracker'); 
$image_data = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code == 200 && $image_data) {
    file_put_contents($local_path, $image_data);
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=86400');
    echo $image_data;
} else {
    http_response_code(404);
    die('Image not found on remote server.');
}
?>
