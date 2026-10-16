<?php
// Centralized Database Connection for TiDB Cloud Serverless

// Safe defaults / environment variables (NO secrets hardcoded in git)
$db_host = getenv('DB_HOST') ?: 'gateway01.us-west-2.prod.aws.tidbcloud.com';
$db_port = (int)(getenv('DB_PORT') ?: 4000);
$db_user = getenv('DB_USER') ?: '8H9QVXuqz8WDTEg.root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'u834540789_Galaxy';

// Load untracked local / server configuration if present
$local_config = __DIR__ . '/db_config.php';
if (file_exists($local_config)) {
    require_once $local_config;
}

// Compatibility aliases for legacy scripts
$host       = $db_host;
$servername = $db_host;
$user       = $db_user;
$username   = $db_user;
$pass       = $db_pass;
$password   = $db_pass;
$db         = $db_name;
$dbname     = $db_name;
$port       = $db_port;

/**
 * Returns an SSL-encrypted mysqli connection to TiDB Cloud
 */
function get_db_connection() {
    global $db_host, $db_user, $db_pass, $db_name, $db_port;

    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

    $conn = mysqli_init();
    if (!$conn) {
        throw new Exception('mysqli_init failed');
    }

    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

    // TiDB Cloud requires TLS
    $ca_cert = __DIR__ . '/isrgrootx1.pem';
    if (file_exists($ca_cert)) {
        $conn->ssl_set(NULL, NULL, $ca_cert, NULL, NULL);
    } else {
        $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
    }

    $connected = $conn->real_connect(
        $db_host,
        $db_user,
        $db_pass,
        $db_name,
        $db_port,
        NULL,
        MYSQLI_CLIENT_SSL
    );

    if (!$connected || $conn->connect_error) {
        throw new Exception('TiDB Connection failed: ' . ($conn->connect_error ?: 'Unknown error'));
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}
