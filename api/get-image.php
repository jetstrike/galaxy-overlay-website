<?php
// get-image.php - Caches images from static.galaxy.fun
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    // Allow filename like "aladdin__default__300.webp"
    $file = isset($_GET['file']) ? preg_replace('/[^a-zA-Z0-9_\.]/', '', $_GET['file']) : '';
    
    // Backwards compatibility for the old 'key' parameter (e.g. key=skin_mercury_m_1000 -> skin_mercury_m_1000__default__300.webp)
    if (!$file && isset($_GET['key'])) {
        $key = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['key']);
        $file = "{$key}__default__300.webp";
    }

    if (!$file) {
        http_response_code(400);
        die('Missing file parameter');
    }

    $remote_url = "https://static.galaxy.fun/cards/{$file}";

    if (!function_exists('curl_init')) {
        die('cURL is not installed!');
    }

    $ch = curl_init($remote_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) GalaxyTracker'); 
    $image_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        die('Curl error: ' . curl_error($ch));
    }
    curl_close($ch);

    if ($http_code == 200 && $image_data) {
        $cache_dir = __DIR__ . '/../assets/cards';
        if (!is_dir($cache_dir)) {
            @mkdir($cache_dir, 0777, true);
        }
        $local_path = $cache_dir . '/' . $file;
        @file_put_contents($local_path, $image_data);
        
        header('Content-Type: image/webp');
        header('Cache-Control: public, max-age=86400');
        echo $image_data;
    } else {
        http_response_code(404);
        die("Image not found on remote server. URL: " . $remote_url . " HTTP CODE: " . $http_code);
    }
} catch (Exception $e) {
    http_response_code(500);
    die('Exception: ' . $e->getMessage());
} catch (Error $e) {
    http_response_code(500);
    die('Error: ' . $e->getMessage());
}
?>
