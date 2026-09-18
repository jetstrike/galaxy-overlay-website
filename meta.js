
let captainsData = [];
let currentSort = { column: "avg_placement", asc: true };

async function fetchMetaData() {
    try {
        const response = await fetch("https://galaxy-overlay.com/api/get-meta.php");
        const data = await response.json();
        
        document.getElementById("total-matches-val").textContent = data.total_matches.toLocaleString();
        captainsData = data.captains || [];
        
        // Initial render
        renderTable();
    } catch (error) {
        console.error("Error fetching meta data:", error);
        document.getElementById("captains-tbody").innerHTML = `<tr><td colspan="6" style="color: #ff4444;">Failed to load live meta data.</td></tr>`;
    }
}

function renderTable() {
    const tbody = document.getElementById("captains-tbody");
    
    // Sort logic
    const sorted = [...captainsData].sort((a, b) => {
        let valA, valB;
        if (currentSort.column === "name") {
            valA = a.captain_name.toLowerCase();
            valB = b.captain_name.toLowerCase();
        } else {
            valA = parseFloat(a[currentSort.column]);
            valB = parseFloat(b[currentSort.column]);
        }
        
        if (valA < valB) return currentSort.asc ? -1 : 1;
        if (valA > valB) return currentSort.asc ? 1 : -1;
        return 0;
    });

    tbody.innerHTML = sorted.map((c, index) => {
        // Color code placement
        let placementColor = "#fff";
        if (c.avg_placement < 3.0) placementColor = "#4ade80"; // green
        else if (c.avg_placement > 4.5) placementColor = "#f87171"; // red
        
        return `
            <tr>
                <td class="captain-name">
                    <span class="rank-number">#${index + 1}</span>
                    <a href="captain.html?name=${encodeURIComponent(c.captain_name)}" style="color: inherit; text-decoration: none; font-weight: bold;">${c.captain_name}</a>
                </td>
                <td>${c.games_played.toLocaleString()}</td>
                <td>${c.pick_rate}%</td>
                <td style="color: ${c.win_rate > 15 ? "#4ade80" : "#fff"}">${c.win_rate}%</td>
                <td>${c.top_3_rate}%</td>
                <td style="color: ${placementColor}">${c.avg_placement.toFixed(2)}</td>
            </tr>
        `;
    }).join("");
}

// Setup Sort Listeners
document.querySelectorAll("th[data-sort]").forEach(th => {
    th.addEventListener("click", () => {
        const column = th.getAttribute("data-sort");
        if (currentSort.column === column) {
            currentSort.asc = !currentSort.asc;
        } else {
            currentSort.column = column;
            // Default descending for most stats, but ascending for average placement and name
            currentSort.asc = (column === "avg_placement" || column === "name");
        }
        
        // Update header classes
        document.querySelectorAll("th[data-sort]").forEach(el => {
            el.classList.remove("sort-asc", "sort-desc");
        });
        th.classList.add(currentSort.asc ? "sort-asc" : "sort-desc");
        
        renderTable();
    });
});

// Auto-refresh every 5 minutes
setInterval(fetchMetaData, 5 * 60 * 1000);

// Initial Load
fetchMetaData();
