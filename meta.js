let rawData = {};
let currentQuery = "captains";
let currentSort = { column: "total_picks", asc: false };

let isDrilldown = false;
let drilldownType = null;
let drilldownValue = null;

async function fetchMetaData() {
    const filter = document.getElementById("mmr-filter").value.split("-");
    const minMmr = filter[0];
    const maxMmr = filter[1];
    
    currentQuery = document.getElementById("query-filter").value;
    
    // Update title
    const select = document.getElementById("query-filter");
    let titleText = select.options[select.selectedIndex].text;
    
    if (isDrilldown) {
        if (drilldownType === 'captain') {
            titleText = `Cards played with ${drilldownValue}`;
        } else {
            titleText = `Captains that played ${drilldownValue}`;
        }
        document.getElementById("back-btn").style.display = "inline-block";
        document.getElementById("query-filter").style.display = "none";
    } else {
        document.getElementById("back-btn").style.display = "none";
        document.getElementById("query-filter").style.display = "inline-block";
    }
    document.getElementById("dynamic-table-title").textContent = titleText;
    
    // Show loading states
    const loadingHtml = `<tr><td colspan="10"><div class="loading"><div class="spinner"></div><div>Crunching numbers from the database...</div></div></td></tr>`;
    document.getElementById("dynamic-tbody").innerHTML = loadingHtml;

    // Show/hide extra filters based on query
    if (currentQuery === 'cards' || (isDrilldown && drilldownType === 'captain')) {
        document.getElementById("rarity-filter").style.display = "inline-block";
        document.getElementById("collectible-filter").style.display = "inline-block";
    } else {
        document.getElementById("rarity-filter").style.display = "none";
        document.getElementById("collectible-filter").style.display = "none";
    }

    // Reset sort when switching queries
    if (isDrilldown) {
        currentSort = { column: "games_played", asc: false };
    } else if (currentQuery === 'captains') {
        currentSort = { column: "total_picks", asc: false };
    } else if (currentQuery === 'cards') {
        currentSort = { column: "games_played", asc: false };
    }

    renderHeaders();

    try {
        let url = `https://galaxy-overlay.com/api/get-analytics.php?query_type=${currentQuery}&min_mmr=${minMmr}&max_mmr=${maxMmr}&_t=${Date.now()}`;
        if (isDrilldown) {
            if (drilldownType === 'captain') {
                url = `https://galaxy-overlay.com/api/get-captain-drilldown.php?captain_name=${encodeURIComponent(drilldownValue)}&min_mmr=${minMmr}&max_mmr=${maxMmr}&_t=${Date.now()}`;
            } else {
                url = `https://galaxy-overlay.com/api/get-card-drilldown.php?card_name=${encodeURIComponent(drilldownValue)}&min_mmr=${minMmr}&max_mmr=${maxMmr}&_t=${Date.now()}`;
            }
        }
        
        const response = await fetch(url);
        const data = await response.json();
        
        document.getElementById("total-matches-val").textContent = (data.total_matches || 0).toLocaleString();
        
        rawData = data.data || [];
        
        renderTable();
    } catch (error) {
        console.error("Error fetching meta data:", error);
        document.getElementById("dynamic-tbody").innerHTML = `<tr><td colspan="10" style="color: #ff4444;">Failed to load live meta data.</td></tr>`;
    }
}

const tableConfigs = {
    captains: [
        { id: "captain_name", label: "Captain", align: "left" },
        { id: "total_picks", label: "Games Played", align: "center" },
        { id: "pick_rate", label: "% Played", align: "center", format: val => parseFloat(val).toFixed(1) + "%" },
        { id: "avg_placement", label: "Avg Placement", align: "center", format: val => parseFloat(val).toFixed(2) },
        { id: "avg_turns", label: "Avg Turns", align: "center", format: val => parseFloat(val).toFixed(1) },
        { id: "win_rate_1st", label: "1st Place Rate", align: "center", format: val => parseFloat(val).toFixed(1) + "%" },
        { id: "win_rate_top3", label: "Top 3 Rate", align: "center", format: val => parseFloat(val).toFixed(1) + "%" }
    ],
    cards: [
        { id: "card_name", label: "Card", align: "left" },
        { id: "games_played", label: "Games Played", align: "center" },
        { id: "avg_turns_on_board", label: "Avg Turns on Board", align: "center", format: val => parseFloat(val).toFixed(1) },
        { id: "avg_first_appearance", label: "Avg First Appearance", align: "center", format: val => parseFloat(val).toFixed(1) },
        { id: "win_rate_1st", label: "1st Place Rate", align: "center", format: val => parseFloat(val).toFixed(1) + "%" },
        { id: "win_rate_top3", label: "Top 3 Rate", align: "center", format: val => parseFloat(val).toFixed(1) + "%" }
    ],
    'drilldown-captain': [
        { id: "card_name", label: "Card", align: "left" },
        { id: "games_played", label: "Games Played", align: "center" },
        { id: "win_rate_top3", label: "Top 3 Rate", align: "center", format: val => parseFloat(val).toFixed(1) + "%" }
    ],
    'drilldown-card': [
        { id: "captain_name", label: "Captain", align: "left" },
        { id: "games_played", label: "Games Played", align: "center" },
        { id: "avg_placement", label: "Avg Placement", align: "center", format: val => parseFloat(val).toFixed(2) },
        { id: "win_rate_top3", label: "Top 3 Rate", align: "center", format: val => parseFloat(val).toFixed(1) + "%" }
    ]
};

function renderHeaders() {
    const thead = document.getElementById("dynamic-thead");
    let configKey = currentQuery;
    if (isDrilldown) {
        configKey = drilldownType === 'captain' ? 'drilldown-captain' : 'drilldown-card';
    }
    const config = tableConfigs[configKey];
    
    let html = "<tr>";
    for (const col of config) {
        let sortClass = "";
        if (currentSort.column === col.id) {
            sortClass = currentSort.asc ? "sort-asc" : "sort-desc";
        }
        html += `<th data-sort="${col.id}" class="${sortClass}" style="text-align: ${col.align};">${col.label}</th>`;
    }
    html += "</tr>";
    thead.innerHTML = html;

    // Rebind events
    const headers = thead.querySelectorAll("th[data-sort]");
    headers.forEach(th => {
        th.addEventListener("click", () => {
            const column = th.getAttribute("data-sort");
            if (currentSort.column === column) {
                currentSort.asc = !currentSort.asc;
            } else {
                currentSort.column = column;
                currentSort.asc = false;
            }
            renderHeaders();
            renderTable();
        });
    });
}

function renderTable() {
    const tbody = document.getElementById("dynamic-tbody");
    let configKey = currentQuery;
    if (isDrilldown) {
        configKey = drilldownType === 'captain' ? 'drilldown-captain' : 'drilldown-card';
    }
    const config = tableConfigs[configKey];
    
    if (rawData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${config.length}" style="color: var(--text-muted);">No data available for this selection.</td></tr>`;
        return;
    }

    let filteredData = rawData;

    // Apply client-side filters if we are viewing cards
    if (currentQuery === 'cards' || (isDrilldown && drilldownType === 'captain')) {
        const rarityFilter = document.getElementById("rarity-filter").value;
        const colFilter = document.getElementById("collectible-filter").value;

        filteredData = filteredData.filter(item => {
            if (rarityFilter !== 'all') {
                if (!item.rarity || item.rarity.toLowerCase() !== rarityFilter) return false;
            }
            if (colFilter !== 'all') {
                const isCollectible = String(item.is_collectible) === '1' || item.is_collectible === true || item.is_collectible === 'true';
                if (colFilter === 'collectible' && !isCollectible) return false;
                if (colFilter === 'uncollectible' && isCollectible) return false;
            }
            return true;
        });
    }

    if (filteredData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${config.length}" style="color: var(--text-muted);">No data matches the selected filters.</td></tr>`;
        return;
    }

    // Sort data
    const sortedData = [...filteredData].sort((a, b) => {
        let valA = a[currentSort.column];
        let valB = b[currentSort.column];
        
        if (!isNaN(parseFloat(valA)) && !isNaN(parseFloat(valB))) {
            valA = parseFloat(valA);
            valB = parseFloat(valB);
        } else {
            valA = String(valA).toLowerCase();
            valB = String(valB).toLowerCase();
        }

        if (valA < valB) return currentSort.asc ? -1 : 1;
        if (valA > valB) return currentSort.asc ? 1 : -1;
        return 0;
    });

    let html = "";
    sortedData.forEach((row, index) => {
        html += "<tr>";
        for (const col of config) {
            let val = row[col.id];
            if (col.format && val !== null && val !== undefined) {
                val = col.format(val);
            }
            if (val === null || val === undefined) val = "-";
            
            if (col.align === "left") {
                let clickableName = `<span style="cursor: pointer; color: var(--accent-primary); font-weight: bold;" onclick="triggerDrilldown('${col.id === 'captain_name' ? 'captain' : 'card'}', '${val.replace(/'/g, "\'")}')">${val}</span>`;
                html += `<td class="item-name"><span class="rank-number">#${index + 1}</span> ${clickableName}</td>`;
            } else {
                html += `<td>${val}</td>`;
            }
        }
        html += "</tr>";
    });

    tbody.innerHTML = html;
}

document.getElementById("mmr-filter").addEventListener("change", fetchMetaData);
document.getElementById("query-filter").addEventListener("change", fetchMetaData);
document.getElementById("rarity-filter").addEventListener("change", renderTable);
document.getElementById("collectible-filter").addEventListener("change", renderTable);

// Auto-refresh every 10 minutes
setInterval(fetchMetaData, 10 * 60 * 1000);

// Initial Load
fetchMetaData();


window.triggerDrilldown = function(type, value) {
    isDrilldown = true;
    drilldownType = type;
    drilldownValue = value;
    fetchMetaData();
};

document.getElementById("back-btn").addEventListener("click", () => {
    isDrilldown = false;
    drilldownType = null;
    drilldownValue = null;
    fetchMetaData();
});
