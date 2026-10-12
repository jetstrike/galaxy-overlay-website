<?php
// Centralized Database Connection for TiDB Cloud Serverless

 = 'gateway01.us-west-2.prod.aws.tidbcloud.com';
 = 4000;
 = '8H9QVXuqz8WDTEg.root';
 = 'rlNgzuVxjg5hAqad';
 = 'u834540789_Galaxy';

// Compatibility aliases for legacy scripts
System.Management.Automation.Internal.Host.InternalHost       = ;
 = ;
       = ;
   = ;
       = ;
   = ;
         = ;
     = ;
       = ;

/**
 * Returns an SSL-encrypted mysqli connection to TiDB Cloud
 */
function get_db_connection() {
    global , , , , ;

    mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);

     = mysqli_init();
    if (!) {
        throw new Exception('mysqli_init failed');
    }

    ->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);

    // TiDB Cloud requires TLS
     = __DIR__ . '/isrgrootx1.pem';
    if (file_exists()) {
        ->ssl_set(NULL, NULL, , NULL, NULL);
    } else {
        ->ssl_set(NULL, NULL, NULL, NULL, NULL);
    }

     = ->real_connect(
        ,
        ,
        ,
        ,
        ,
        NULL,
        MYSQLI_CLIENT_SSL
    );

    if (! || ->connect_error) {
        throw new Exception('TiDB Connection failed: ' . (->connect_error ?: 'Unknown error'));
    }

    ->set_charset('utf8mb4');
    return ;
}
