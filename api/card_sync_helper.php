<?php
/**
 * Automatically fetches the latest official cards from https://guide.galaxy.fun/data/cards.json
 * Updates local cards.json and captain_map.json
 * Bulk-upserts cards into the analytics_cards database table in TiDB Cloud
 */
require_once __DIR__ . '/db.php';

function sync_cards_catalog($force = false) {
    $cache_file = __DIR__ . '/cards.json';
    $map_file = __DIR__ . '/captain_map.json';
    $timestamp_file = __DIR__ . '/../temp/cards_last_synced.txt';
    
    $now = time();
    if (!$force && file_exists($timestamp_file)) {
        $last_sync = intval(@file_get_contents($timestamp_file));
        // Check once every 6 hours by default
        if ($now - $last_sync < 6 * 3600) {
            return ["status" => "skipped", "message" => "Cards synced recently (" . round(($now - $last_sync) / 60) . " mins ago)."];
        }
    }

    $url = "https://guide.galaxy.fun/data/cards.json";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'OUAG-Tracker/1.0');
    $raw = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || empty($raw)) {
        if (file_exists($cache_file)) {
            $raw = file_get_contents($cache_file);
        } else {
            return ["status" => "error", "message" => "Failed to fetch cards.json from guide and no local cache."];
        }
    }

    $guide_cards = json_decode($raw, true);
    if (!is_array($guide_cards) || count($guide_cards) < 100) {
        return ["status" => "error", "message" => "Invalid cards payload from guide."];
    }

    // 1. Build CID-keyed map and Captain Map
    $cid_cards = [];
    $captain_map = [];

    foreach ($guide_cards as $key => $card) {
        $cid = isset($card['cid']) ? intval($card['cid']) : null;
        if ($cid !== null && $cid > 0) {
            $cid_cards[strval($cid)] = $card;
        } else {
            $cid_cards[$key] = $card;
        }

        $type = isset($card['card_type']) ? strtolower($card['card_type']) : '';
        if ($type === 'captain' && !empty($card['name']) && $cid) {
            $captain_map[$card['name']] = $cid;
        }
    }

    // Save cards.json & captain_map.json to disk
    @file_put_contents($cache_file, json_encode($cid_cards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    @file_put_contents($map_file, json_encode($captain_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    
    $temp_dir = dirname($timestamp_file);
    if (!is_dir($temp_dir)) @mkdir($temp_dir, 0755, true);
    @file_put_contents($timestamp_file, strval($now));

    // 2. Bulk upsert into analytics_cards in TiDB
    $inserted_or_updated = 0;
    try {
        $conn = get_db_connection();
        if ($conn) {
            $rows = [];
            foreach ($cid_cards as $key => $card) {
                $c = isset($card['cid']) ? intval($card['cid']) : 0;
                if ($c <= 0) continue;

                $name = $card['name'] ?? '';
                $type = isset($card['card_type']) ? strtolower($card['card_type']) : '';
                
                $tribe = '';
                if (isset($card['factions']) && is_array($card['factions'])) {
                    $tribe = implode(',', $card['factions']);
                } else if (isset($card['tribe'])) {
                    $tribe = $card['tribe'];
                }

                $tier = isset($card['tier']) ? intval($card['tier']) : 0;
                $rarity = isset($card['rarity']) ? strtolower($card['rarity']) : '';
                $is_collectible = !empty($card['is_collectible']) ? 1 : 0;

                $rows[] = [
                    'cid' => $c,
                    'name' => $name,
                    'type' => $type,
                    'tribe' => $tribe,
                    'tier' => $tier,
                    'rarity' => $rarity,
                    'is_collectible' => $is_collectible
                ];
            }

            // Chunk bulk insert (150 rows per batch) for fast SSL execution
            $chunks = array_chunk($rows, 150);
            foreach ($chunks as $chunk) {
                $valClauses = [];
                foreach ($chunk as $r) {
                    $valClauses[] = sprintf(
                        "(%d, '%s', '%s', '%s', %d, '%s', %d)",
                        $r['cid'],
                        $conn->real_escape_string($r['name']),
                        $conn->real_escape_string($r['type']),
                        $conn->real_escape_string($r['tribe']),
                        $r['tier'],
                        $conn->real_escape_string($r['rarity']),
                        $r['is_collectible']
                    );
                }
                $sql = "INSERT INTO analytics_cards (`cid`, `name`, `type`, `tribe`, `tier`, `rarity`, `is_collectible`) 
                        VALUES " . implode(', ', $valClauses) . " 
                        ON DUPLICATE KEY UPDATE 
                        `name` = VALUES(`name`), 
                        `type` = VALUES(`type`), 
                        `tribe` = VALUES(`tribe`), 
                        `tier` = VALUES(`tier`), 
                        `rarity` = VALUES(`rarity`), 
                        `is_collectible` = VALUES(`is_collectible`)";
                $conn->query($sql);
                $inserted_or_updated += count($chunk);
            }

            // Invalidate cached captains queries so new names appear immediately in meta table
            $conn->query("DELETE FROM analytics_cache WHERE query_type = 'captains'");
        }
    } catch (Throwable $e) {
        error_log("Card sync database error: " . $e->getMessage());
        return [
            "status" => "partial_success",
            "message" => "Files saved, but DB upsert failed: " . $e->getMessage(),
            "total_cards" => count($cid_cards),
            "total_captains" => count($captain_map)
        ];
    }

    return [
        "status" => "success",
        "total_cards" => count($cid_cards),
        "total_captains" => count($captain_map),
        "upserted_db_rows" => $inserted_or_updated
    ];
}
