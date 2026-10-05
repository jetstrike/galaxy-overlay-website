<?php
// We no longer TRUNCATE here because it fails on Hostinger.
// The tables should be manually truncated/deleted if a full rebuild is needed.

for($i=0; $i<200; $i++) {
    $res = file_get_contents("https://galaxy-overlay.com/api/sync-analytics-db.php");
    echo "Iteration $i: $res\n";
    if (strpos($res, "No unsynced matches") !== false) {
        break;
    }
    sleep(1);
}
echo "Done";
?>
