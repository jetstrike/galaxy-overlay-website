<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

\System.Management.Automation.Internal.Host.InternalHost = 'localhost';
\ = 'u834540789_Galaxy';
\ = 'u834540789_Tracker';
\ = 'Slippery1!1!';

\ = new mysqli(\System.Management.Automation.Internal.Host.InternalHost, \, \, \);
if (\->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

try {
    \ = \->query("DELETE FROM playfab_matches WHERE player_name IS NULL");
    echo json_encode([
        'success' => true,
        'affected_rows' => \->affected_rows
    ]);
} catch (Exception \) {
    echo json_encode(['error' => \->getMessage()]);
}
\->close();
?>
