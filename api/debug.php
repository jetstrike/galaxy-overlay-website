<?php
$c = new mysqli('localhost', 'u834540789_Tracker', 'Slippery1!1!', 'u834540789_Galaxy');
$c->query("ALTER TABLE analytics_match_turns ADD INDEX idx_run_card (run_id, card_cid, turn_number)");
echo $c->error;
?>
