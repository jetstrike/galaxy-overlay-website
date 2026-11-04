<?php
/**
 * Comparison Tool: Overlay App vs. Web Harvest Automation
 * Compares match captures between overlay_matches and playfab_web_harvest
 * Supports both JSON API and interactive HTML dashboard.
 */

require_once __DIR__ . '/db.php';

header("Access-Control-Allow-Origin: *");

$wants_json = isset($_GET['json']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false && !strpos($_SERVER['HTTP_ACCEPT'], 'text/html'));

try {
    $conn = get_db_connection();

    // 1. Overall counts
    $res_overlay = $conn->query("SELECT COUNT(*) as cnt, COUNT(DISTINCT run_id) as runs FROM overlay_matches");
    $overlay_stats = $res_overlay->fetch_assoc();

    $res_harvest = $conn->query("SELECT COUNT(*) as cnt, COUNT(DISTINCT run_id) as runs FROM playfab_matches");
    $harvest_stats = $res_harvest->fetch_assoc();

    // 2. Overlap metrics
    $overlap_sql = "
        SELECT 
            COUNT(DISTINCT o.run_id) as common_runs
        FROM overlay_matches o
        INNER JOIN playfab_matches h ON o.run_id = h.run_id
    ";
    $res_overlap = $conn->query($overlap_sql);
    $overlap_stats = $res_overlap->fetch_assoc();

    $common_runs = (int)($overlap_stats['common_runs'] ?? 0);
    $overlay_runs = (int)($overlay_stats['runs'] ?? 0);
    $harvest_runs = (int)($harvest_stats['runs'] ?? 0);

    // 3. Recent 25 matches from both to inspect side-by-side
    $recent_sql = "
        SELECT 
            h.run_id,
            h.date,
            h.player_name as harvest_player,
            h.captain as harvest_captain,
            h.placement as harvest_placement,
            h.mmr as harvest_mmr,
            h.source_tag,
            IF(o.run_id IS NOT NULL, 1, 0) as in_overlay
        FROM playfab_matches h
        LEFT JOIN (SELECT DISTINCT run_id FROM overlay_matches) o ON h.run_id = o.run_id
        ORDER BY h.date DESC
        LIMIT 25
    ";
    $recent_res = $conn->query($recent_sql);
    $recent_matches = [];
    while ($row = $recent_res->fetch_assoc()) {
        $recent_matches[] = $row;
    }

    $overlay_coverage_pct = $harvest_runs > 0 ? round(($common_runs / $harvest_runs) * 100, 2) : 0;

    $payload = [
        "status" => "success",
        "summary" => [
            "overlay_app" => [
                "total_rows" => (int)$overlay_stats['cnt'],
                "unique_runs" => $overlay_runs
            ],
            "playfab_matches" => [
                "total_rows" => (int)$harvest_stats['cnt'],
                "unique_runs" => $harvest_runs
            ],
            "comparison" => [
                "captured_by_both_runs" => $common_runs,
                "captured_by_overlay_only" => max(0, $overlay_runs - $common_runs),
                "captured_by_playfab_only" => max(0, $harvest_runs - $common_runs),
                "overlay_coverage_of_playfab_percent" => $overlay_coverage_pct
            ]
        ],
        "recent_harvested_sample" => $recent_matches
    ];

    if ($wants_json) {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_PRETTY_PRINT);
        exit();
    }

    // Render HTML Dashboard
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Match Capture Audit | Overlay vs. Web Harvest</title>
    <style>
        :root {
            --bg: #0f172a;
            --card-bg: #1e293b;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --accent-blue: #38bdf8;
            --accent-green: #4ade80;
            --accent-purple: #c084fc;
            --border: #334155;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 30px;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
            padding-bottom: 20px;
            margin-bottom: 25px;
        }
        h1 { margin: 0; font-size: 24px; color: var(--text); }
        .btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn:hover { background: #1d4ed8; }
        .btn-green { background: #16a34a; }
        .btn-green:hover { background: #15803d; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
        }
        .card-title { font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 8px; }
        .card-value { font-size: 32px; font-weight: 700; }
        .card-sub { font-size: 13px; color: var(--text-muted); margin-top: 5px; }
        .val-blue { color: var(--accent-blue); }
        .val-green { color: var(--accent-green); }
        .val-purple { color: var(--accent-purple); }
        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
        th { background: #182234; color: var(--text-muted); font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-yes { background: rgba(74, 222, 128, 0.15); color: #4ade80; border: 1px solid #4ade80; }
        .badge-no { background: rgba(248, 113, 113, 0.15); color: #f87171; border: 1px solid #f87171; }
        .badge-red { background: rgba(239, 68, 68, 0.1); color: #fca5a5; }
        .badge-blue { background: rgba(56, 189, 248, 0.1); color: #7dd3fc; }
        .status-bar {
            padding: 12px 16px;
            background: #1e293b;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Match Capture Audit</h1>
                <div style="color: var(--text-muted); font-size: 14px; margin-top: 4px;">Comparing Desktop Overlay App vs. Web Harvest Automation</div>
            </div>
            <div>
                <button class="btn btn-green" id="harvestBtn" onclick="runManualHarvest()">Trigger Web Harvester Now</button>
                <button class="btn" onclick="location.reload()" style="margin-left: 8px;">Refresh</button>
            </div>
        </div>

        <div class="status-bar" id="statusBar" style="display: none;"></div>

        <div class="grid">
            <div class="card">
                <div class="card-title">Desktop Overlay App</div>
                <div class="card-value val-blue"><?= number_format($overlay_runs) ?></div>
                <div class="card-sub">Unique matches captured via client app</div>
            </div>
            <div class="card">
                <div class="card-title">PlayFab Matches (Unified DB)</div>
                <div class="card-value val-green" id="harvestCount"><?= number_format($harvest_runs) ?></div>
                <div class="card-sub">Global matches in `playfab_matches`</div>
            </div>
            <div class="card">
                <div class="card-title">Captured by Both</div>
                <div class="card-value val-purple"><?= number_format($common_runs) ?></div>
                <div class="card-sub"><?= $overlay_coverage_pct ?>% overlap coverage</div>
            </div>
            <div class="card">
                <div class="card-title">PlayFab ONLY</div>
                <div class="card-value" style="color: #fb923c;"><?= number_format(max(0, $harvest_runs - $common_runs)) ?></div>
                <div class="card-sub">Global games missed by overlay users</div>
            </div>
        </div>

        <h2 style="font-size: 18px; margin-bottom: 12px;">Latest Automated Harvests</h2>
        <table>
            <thead>
                <tr>
                    <th>Date (UTC)</th>
                    <th>Player</th>
                    <th>Captain</th>
                    <th>Place</th>
                    <th>MMR</th>
                    <th>Endpoint</th>
                    <th>In Overlay?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_matches as $m): ?>
                <tr>
                    <td style="font-family: monospace; font-size: 13px;"><?= htmlspecialchars(substr($m['date'], 0, 19)) ?></td>
                    <td style="font-weight: 600;"><?= htmlspecialchars($m['harvest_player'] ?: 'Unknown') ?></td>
                    <td><?= htmlspecialchars($m['harvest_captain'] ?: 'N/A') ?></td>
                    <td>#<?= htmlspecialchars($m['harvest_placement']) ?></td>
                    <td><?= htmlspecialchars($m['harvest_mmr']) ?></td>
                    <td>
                        <span class="badge <?= ($m['source_tag'] === 'playfab_red') ? 'badge-red' : 'badge-blue' ?>">
                            <?= ($m['source_tag'] === 'playfab_red') ? 'Red' : 'Blue' ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($m['in_overlay'] == 1): ?>
                            <span class="badge badge-yes">YES</span>
                        <?php else: ?>
                            <span class="badge badge-no">NO</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        async function runManualHarvest() {
            const btn = document.getElementById('harvestBtn');
            const bar = document.getElementById('statusBar');
            btn.disabled = true;
            btn.innerText = 'Harvesting PlayFab...';
            bar.style.display = 'block';
            bar.innerText = 'Calling harvest-playfab.php over HTTPS...';

            try {
                const res = await fetch('harvest-playfab.php?key=ouag_harvester_secure_cron_2026');
                const data = await res.json();
                if (data.status === 'success') {
                    bar.innerText = `Success! Inserted ${data.inserted_new} new matches in ${data.elapsed_seconds}s. Reloading...`;
                    setTimeout(() => location.reload(), 1500);
                } else {
                    bar.innerText = `Error: ${data.message || 'Harvest failed'}`;
                    btn.disabled = false;
                    btn.innerText = 'Trigger Web Harvester Now';
                }
            } catch (err) {
                bar.innerText = `Network Error: ${err.message}`;
                btn.disabled = false;
                btn.innerText = 'Trigger Web Harvester Now';
            }
        }
    </script>
</body>
</html>
<?php
    $conn->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
