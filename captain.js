
const urlParams = new URLSearchParams(window.location.search);
const captainName = urlParams.get("name") || "Unknown Captain";

document.getElementById("captain-title").innerHTML = `<span>${captainName}</span> Insights`;

let cardDictionary = {};

async function init() {
    try {
        // Fetch official card data mapping
        const cardRes = await fetch("https://guide.galaxy.fun/data/cards.json");
        const cardData = await cardRes.json();
        
        // Build CID -> Name map
        for (const key in cardData) {
            const c = cardData[key];
            if (c.cid !== undefined) {
                cardDictionary[c.cid] = c.name || key;
            }
        }
    } catch(e) {
        console.error("Failed to fetch card dictionary", e);
    }
    
    fetchCaptainData();
}

async function fetchCaptainData() {
    try {
        const response = await fetch(`https://galaxy-overlay.com/api/get-captain-meta.php?name=${encodeURIComponent(captainName)}`);
        const data = await response.json();
        
        if (data.error) {
            throw new Error(data.error);
        }
        
        document.getElementById("total-matches-val").textContent = data.total_matches.toLocaleString();
        
        renderTable("deck-tbody", data.top_deck || []);
        renderTable("board-tbody", data.top_board || []);
        
    } catch (error) {
        console.error("Error fetching captain data:", error);
        document.getElementById("deck-tbody").innerHTML = `<tr><td colspan="3" style="color: #ff4444;">Failed to load data.</td></tr>`;
        document.getElementById("board-tbody").innerHTML = `<tr><td colspan="3" style="color: #ff4444;">Failed to load data.</td></tr>`;
    }
}

function renderTable(tbodyId, rows) {
    const tbody = document.getElementById(tbodyId);
    
    if (rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" style="color: var(--text-muted);">No data available yet.</td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map((r, index) => {
        let placementColor = "#fff";
        if (r.avg_placement < 3.0) placementColor = "#4ade80"; // green
        else if (r.avg_placement > 4.5) placementColor = "#f87171"; // red
        
        const cardName = cardDictionary[r.cid] || `Unknown Card (#${r.cid})`;
        
        return `
            <tr>
                <td class="card-name">
                    <span class="rank-number">#${index + 1}</span>
                    ${cardName}
                </td>
                <td>${r.count.toLocaleString()}</td>
                <td style="color: ${placementColor}">${r.avg_placement.toFixed(2)}</td>
            </tr>
        `;
    }).join("");
}

init();
