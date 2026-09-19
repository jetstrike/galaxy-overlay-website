let rawData = {};
let currentSort = {
    captains: { column: "avg_placement", asc: true },
    decks: { column: "deck_inclusion_rate", asc: false },
    final: { column: "final_board_rate", asc: false }
};

async function fetchMetaData() {
    const filter = document.getElementById("mmr-filter").value.split("-");
    const minMmr = filter[0];
    const maxMmr = filter[1];
    
    // Show loading states
    const loadingHtml = \<tr><td colspan="6"><div class="loading"><div class="spinner"></div><div>Crunching numbers from the database...</div></div></td></tr>\;
    document.getElementById("captains-tbody").innerHTML = loadingHtml;
    document.getElementById("decks-tbody").innerHTML = loadingHtml;
    document.getElementById("final-tbody").innerHTML = loadingHtml;

    try {
        const response = await fetch(\https://galaxy-overlay.com/api/get-analytics.php?min_mmr=\&max_mmr=\\);
        const data = await response.json();
        
        document.getElementById("total-matches-val").textContent = (data.total_matches || 0).toLocaleString();
        
        rawData = data;
        
        renderTable("captains");
        renderTable("decks");
        renderTable("final");
    } catch (error) {
        console.error("Error fetching meta data:", error);
        document.getElementById("captains-tbody").innerHTML = \<tr><td colspan="6" style="color: #ff4444;">Failed to load live meta data.</td></tr>\;
    }
}

function renderTable(tableId) {
    const tbody = document.getElementById(tableId + "-tbody");
    let dataList = [];
    let nameKey = "";
    
    if (tableId === "captains") {
        dataList = rawData.captain_stats || [];
        nameKey = "captain_name";
    } else if (tableId === "decks") {
        dataList = rawData.deck_card_stats || [];
        nameKey = "card_name";
    } else if (tableId === "final") {
        dataList = rawData.final_board_stats || [];
        nameKey = "card_name";
    }

    if (dataList.length === 0) {
        tbody.innerHTML = \<tr><td colspan="6" style="color: var(--text-muted);">No data available for this MMR range.</td></tr>\;
        return;
    }

    const sortConfig = currentSort[tableId];

    const sorted = [...dataList].sort((a, b) => {
        let valA, valB;
        if (sortConfig.column === "name") {
            valA = (a[nameKey] || "Unknown").toLowerCase();
            valB = (b[nameKey] || "Unknown").toLowerCase();
        } else {
            valA = parseFloat(a[sortConfig.column]) || 0;
            valB = parseFloat(b[sortConfig.column]) || 0;
        }
        
        if (valA < valB) return sortConfig.asc ? -1 : 1;
        if (valA > valB) return sortConfig.asc ? 1 : -1;
        return 0;
    });

    tbody.innerHTML = sorted.map((row, index) => {
        let placementColor = "#fff";
        const avg_placement = parseFloat(row.avg_placement) || 0;
        if (avg_placement < 3.0 && avg_placement > 0) placementColor = "#4ade80";
        else if (avg_placement > 4.5) placementColor = "#f87171";
        
        const itemName = row[nameKey] || "Unknown";
        let html = \<tr><td class="item-name"><span class="rank-number">#\</span><a href="\\" style="color: inherit; text-decoration: none; font-weight: bold;">\</a></td>\;
        
        if (tableId === "captains") {
            const wr1st = parseFloat(row.win_rate_1st) || 0;
            html += \
                <td>\</td>
                <td>\%</td>
                <td style="color: \">\%</td>
                <td>\%</td>
                <td style="color: \">\</td>
            \;
        } else if (tableId === "decks") {
            html += \
                <td>\</td>
                <td>\%</td>
                <td>\%</td>
                <td style="color: \">\</td>
            \;
        } else if (tableId === "final") {
            html += \
                <td>\</td>
                <td>\%</td>
                <td style="color: \">\</td>
            \;
        }
        html += \</tr>\;
        return html;
    }).join("");
}

// Setup Sort Listeners
document.querySelectorAll("th[data-sort]").forEach(th => {
    th.addEventListener("click", () => {
        const column = th.getAttribute("data-sort");
        const tableId = th.getAttribute("data-table");
        
        if (currentSort[tableId].column === column) {
            currentSort[tableId].asc = !currentSort[tableId].asc;
        } else {
            currentSort[tableId].column = column;
            currentSort[tableId].asc = (column === "avg_placement" || column === "name");
        }
        
        // Update header classes for this table
        const thead = th.closest("thead");
        thead.querySelectorAll("th[data-sort]").forEach(el => {
            el.classList.remove("sort-asc", "sort-desc");
        });
        th.classList.add(currentSort[tableId].asc ? "sort-asc" : "sort-desc");
        
        renderTable(tableId);
    });
});

document.getElementById("mmr-filter").addEventListener("change", fetchMetaData);

// Auto-refresh every 10 minutes (queries are heavy now)
setInterval(fetchMetaData, 10 * 60 * 1000);

// Initial Load
fetchMetaData();
