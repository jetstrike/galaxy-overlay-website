<?php
// get-image.php - Caches images from static.galaxy.fun

\ = isset(\['key']) ? preg_replace('/[^a-zA-Z0-9_]/', '', \['key']) : '';
if (!\) {
    http_response_code(400);
    die('Missing key');
}

// Define paths
\ = __DIR__ . '/../assets/cards';
if (!is_dir(\)) {
    mkdir(\, 0777, true);
}

\ = \ . '.webp';
\ = \ . '/' . \;

// If we already have it cached, just serve it
if (file_exists(\)) {
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=86400');
    readfile(\);
    exit;
}

// Otherwise, fetch it from the official CDN
// Based on: https://static.galaxy.fun/cards/skin_mercury_m_1000__default__300.webp
\ = "https://static.galaxy.fun/cards/{\}__default__300.webp";

\ = curl_init(\);
curl_setopt(\, CURLOPT_RETURNTRANSFER, true);
curl_setopt(\, CURLOPT_FOLLOWLOCATION, true);
// Need user-agent sometimes to prevent 403
curl_setopt(\, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GalaxyTracker'); 
\ = curl_exec(\);
\ = curl_getinfo(\, CURLINFO_HTTP_CODE);
curl_close(\);

if (\ == 200 && \) {
    // Save to cache
    file_put_contents(\, \);
    
    // Serve it
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=86400');
    echo \;
} else {
    // If not found, serve a fallback or 404
    http_response_code(404);
    die('Image not found on remote server.');
}
?>
