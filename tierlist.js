let currentData = [];

let cidToKey = {};

async function fetchCards() {
    if (Object.keys(cidToKey).length > 0) return;
    try {
        const response = await fetch('https://galaxy-overlay.com/api/cards.json');
        const cardsData = await response.json();
        for (const key in cardsData) {
            const card = cardsData[key];
            if (card && card.cid) {
                cidToKey[card.cid] = card.card_key || key;
            }
        }
    } catch (err) {
        console.error('Failed to load cards.json', err);
    }
}

async function fetchSeasons() {
    try {
        const res = await fetch('https://galaxy-overlay.com/api/get-seasons.php?_t=' + Date.now());
        const seasons = await res.json();
        const select = document.getElementById('season-filter');
        select.innerHTML = '<option value="all">All Seasons</option>';
        seasons.forEach(s => {
            if (s !== 'all') {
                const opt = document.createElement('option');
                opt.value = s;
                opt.textContent = 'Season ' + s;
                select.appendChild(opt);
            }
        });
    } catch (e) { console.error('Failed to load seasons', e); }
}

async function buildTierList() {
    await fetchCards();
    const filter = document.getElementById("mmr-filter").value.split("-");
    const minMmr = filter[0];
    const maxMmr = filter[1];
    const queryType = document.getElementById("query-filter").value;
    const season = document.getElementById("season-filter").value;

    const select = document.getElementById("mmr-filter");
    const mmrText = select.options[select.selectedIndex].text;
    
    document.getElementById("tierlist-title").textContent = queryType === 'captains' ? `Captain Tier List (${mmrText})` : `Card Tier List (${mmrText})`;
    
    document.getElementById("tierlist-content").innerHTML = `<div class="loading"><div class="spinner"></div><div>Crunching numbers from the database...</div></div>`;

    try {
        let url = `https://galaxy-overlay.com/api/get-analytics.php?query_type=${queryType}&min_mmr=${minMmr}&max_mmr=${maxMmr}&_t=${Date.now()}`;
        const response = await fetch(url);
        const json = await response.json();
        
        const data = json.data || [];
        if (data.length === 0) {
            document.getElementById("tierlist-content").innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--text-muted);">No data available for this selection.</div>`;
            return;
        }

        // Filter out low sample size items (e.g. less than 1% pick rate or < 50 games)
        const totalMatches = json.total_matches || 1;
        const validData = data.filter(item => {
            const picks = parseInt(item.total_picks || item.games_played);
            return picks >= Math.max(5, totalMatches * 0.001); 
        });

        if (validData.length === 0) {
            document.getElementById("tierlist-content").innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--text-muted);">Not enough data for this bracket yet.</div>`;
            return;
        }

        const tiers = calculateTiers(validData, queryType);
        renderTiers(tiers, queryType);

    } catch (error) {
        console.error(error);
        document.getElementById("tierlist-content").innerHTML = `<div style="padding: 2rem; text-align: center; color: #ff4444;">Failed to load data.</div>`;
    }
}

function calculateTiers(data, type) {
    // Determine the stat to grade on
    const isLowerBetter = type === 'captains';
    const statKey = type === 'captains' ? 'avg_placement' : 'win_rate_top3';

    const values = data.map(d => parseFloat(d[statKey]));
    const sum = values.reduce((a, b) => a + b, 0);
    const mean = sum / values.length;

    const squareDiffs = values.map(val => Math.pow(val - mean, 2));
    const avgSquareDiff = squareDiffs.reduce((a, b) => a + b, 0) / squareDiffs.length;
    const stdDev = Math.sqrt(avgSquareDiff);

    const tiers = { S: [], A: [], B: [], C: [], D: [], F: [] };

    data.forEach(item => {
        const val = parseFloat(item[statKey]);
        let zScore = (val - mean) / stdDev;
        if (isLowerBetter) {
            zScore = -zScore; // Flip it so positive zScore is always "good"
        }

        if (zScore >= 1.2) {
            tiers.S.push(item);
        } else if (zScore >= 0.5) {
            tiers.A.push(item);
        } else if (zScore >= -0.2) {
            tiers.B.push(item);
        } else if (zScore >= -0.8) {
            tiers.C.push(item);
        } else {
            tiers.D.push(item);
        }
    });

    // Sort items within tiers
    Object.keys(tiers).forEach(k => {
        tiers[k].sort((a, b) => {
            const valA = parseFloat(a[statKey]);
            const valB = parseFloat(b[statKey]);
            if (isLowerBetter) return valA - valB;
            return valB - valA;
        });
    });

    return tiers;
}

function renderTiers(tiers, type) {
    const statKey = type === 'captains' ? 'avg_placement' : 'win_rate_top3';
    const statLabel = type === 'captains' ? 'Avg Place' : 'Top 3 %';
    let html = '';

    const tierLabels = ['S', 'A', 'B', 'C', 'D', 'F'];
    
    tierLabels.forEach(label => {
        const items = tiers[label];
        if (items.length === 0) return; // Skip empty tiers if any

        html += `<div class="tier-row">
            <div class="tier-label tier-${label}">${label}</div>
            <div class="tier-items">`;
            
        items.forEach(item => {
            const name = item.captain_name || item.card_name;
            const cid = item.captain_cid || item.card_cid;
            const cardKey = cidToKey[cid];
            let imgHtml = '';
            if (cardKey) {
                const imgSrc = `https://galaxy-overlay.com/api/get-image.php?file=${cardKey}__default__120.webp`;
                imgHtml = `<img src="${imgSrc}" class="tier-item-img" alt="${name}" onerror="this.style.display='none'" />`;
            }
            let val = parseFloat(item[statKey]).toFixed(2);
            if (type !== 'captains') val += '%';
            
            html += `
                <div class="tier-item" title="${name} - ${val}">
                    ${imgHtml}
                    <div class="item-name">${name}</div>
                    <div class="item-stat">${statLabel}: ${val}</div>
                </div>
            `;
        });
            
        html += `</div></div>`;
    });

    document.getElementById("tierlist-content").innerHTML = html;
}

// Export feature using html2canvas
document.getElementById("export-btn").addEventListener("click", () => {
    const captureArea = document.getElementById("capture-area");
    
    html2canvas(captureArea, {
        backgroundColor: "#0c0f14", 
        scale: 2 // High resolution
    }).then(canvas => {
        const link = document.createElement('a');
        const filter = document.getElementById("mmr-filter").value;
        const type = document.getElementById("query-filter").value;
        link.download = `GalaxyTracker_${type}_TierList_${filter}.png`;
        link.href = canvas.toDataURL("image/png");
        link.click();
    });
});

document.getElementById("mmr-filter").addEventListener("change", buildTierList);
document.getElementById("season-filter").addEventListener("change", buildTierList);
document.getElementById("query-filter").addEventListener("change", buildTierList);

// Initial Load
fetchSeasons().then(buildTierList);









