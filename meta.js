let rawData = {};
let currentQuery = "captains";
let currentSort = { column: "total_picks", asc: false };

async function fetchMetaData() {
    const filter = document.getElementById("mmr-filter").value.split("-");
    const minMmr = filter[0];
    const maxMmr = filter[1];
    
    currentQuery = document.getElementById("query-filter").value;
    
    // Update title
    const select = document.getElementById("query-filter");
    document.getElementById("dynamic-table-title").textContent = select.options[select.selectedIndex].text;
    
    // Show loading states
    const loadingHtml = `<tr><td colspan="10"><div class="loading"><div class="spinner"></div><div>Crunching numbers from the database...</div></div></td></tr>`;
    document.getElementById("dynamic-tbody").innerHTML = loadingHtml;

    // Reset sort when switching queries
    if (currentQuery === 'captains') {
        currentSort = { column: "total_picks", asc: false };
    }

    renderHeaders();

    try {
        const response = await fetch(`https://galaxy-overlay.com/api/get-analytics.php?query_type=${currentQuery}&min_mmr=${minMmr}&max_mmr=${maxMmr}&_t=${Date.now()}`);
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
    ]
};

function renderHeaders() {
    const thead = document.getElementById("dynamic-thead");
    const config = tableConfigs[currentQuery];
    
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
    const config = tableConfigs[currentQuery];
    
    if (rawData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${config.length}" style="color: var(--text-muted);">No data available for this selection.</td></tr>`;
        return;
    }

    // Sort data
    const sortedData = [...rawData].sort((a, b) => {
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
                html += `<td class="item-name"><span class="rank-number">#${index + 1}</span> <span>${val}</span></td>`;
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

// Auto-refresh every 10 minutes
setInterval(fetchMetaData, 10 * 60 * 1000);

// Initial Load
fetchMetaData();
