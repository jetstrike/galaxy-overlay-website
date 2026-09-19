<?php
\System.Management.Automation.Internal.Host.InternalHost = 'localhost';
\ = 'u834540789_Galaxy';
\ = 'u834540789_Tracker';
\ = 'Slippery1!1!';

\ = new mysqli(\System.Management.Automation.Internal.Host.InternalHost, \, \, \);
\ = \->query('SELECT COUNT(*) as total FROM analytics_matches');
echo 'Matches: ' . \->fetch_assoc()['total'];
\->close();
?>