/**
 * Once Upon a Galaxy - Hybrid Smart Card Search & Regex Filter Engine
 * Full JavaScript RegExp support, Scryfall-style syntax tokens, and multi-select facet chips.
 */

class CardSearchEngine {
    constructor() {
        this.lastRegexError = null;
    }

    /**
     * Parses search text to extract syntax tokens like f:olympus, t:spell, c<=3, r:epic
     * Returns { rawText, parsedRegex, tokens, isRegexValid }
     */
    parseQuery(queryString) {
        if (!queryString || typeof queryString !== 'string') {
            return { cleanText: '', tokens: {}, regex: null, isRegexValid: true };
        }

        const tokens = {
            factions: [],
            types: [],
            rarities: [],
            tierMin: null,
            tierMax: null,
            costMin: null,
            costMax: null,
            keywords: []
        };

        // Extract shorthand tokens
        let cleanText = queryString.replace(/(?:^|\s)([ftrck]|tier|cost)([:<>=]+)([^\s]+)/gi, (match, field, op, val) => {
            field = field.toLowerCase();
            op = op.toLowerCase();
            val = val.toLowerCase().replace(/['"]/g, '');

            if (field === 'f') {
                tokens.factions.push(val);
            } else if (field === 't') {
                tokens.types.push(val);
            } else if (field === 'r') {
                tokens.rarities.push(val);
            } else if (field === 'k') {
                tokens.keywords.push(val);
            } else if (field === 'tier') {
                const num = parseInt(val, 10);
                if (!isNaN(num)) {
                    if (op === '<=' || op === '<') tokens.tierMax = num;
                    else if (op === '>=' || op === '>') tokens.tierMin = num;
                    else { tokens.tierMin = num; tokens.tierMax = num; }
                }
            } else if (field === 'c' || field === 'cost') {
                const num = parseInt(val, 10);
                if (!isNaN(num)) {
                    if (op === '<=' || op === '<') tokens.costMax = num;
                    else if (op === '>=' || op === '>') tokens.costMin = num;
                    else { tokens.costMin = num; tokens.costMax = num; }
                }
            }
            return '';
        }).trim();

        // Compile cleanText as RegExp
        let regex = null;
        let isRegexValid = true;

        if (cleanText) {
            try {
                regex = new RegExp(cleanText, 'i');
                this.lastRegexError = null;
            } catch (err) {
                isRegexValid = false;
                this.lastRegexError = err.message;
                // Graceful fallback to escaped substring regex
                const escaped = cleanText.replace(/[.*+?^$\{}()|[\]\\]/g, '\\$&');
                regex = new RegExp(escaped, 'i');
            }
        }

        return {
            cleanText,
            tokens,
            regex,
            isRegexValid,
            error: this.lastRegexError
        };
    }

    /**
     * Filters a list of cards according to query string and active facet chips
     * @param {Array} cards - Catalog cards list
     * @param {string} queryString - Raw search bar input
     * @param {Object} facets - { types: Set, tiers: Set, rarities: Set, factions: Set, costs: Set }
     */
    filterCards(cards, queryString = '', facets = {}) {
        if (!Array.isArray(cards)) return [];

        const parsed = this.parseQuery(queryString);
        const { tokens, regex } = parsed;

        const fTypes = facets.types || new Set();
        const fTiers = facets.tiers || new Set();
        const fRarities = facets.rarities || new Set();
        const fFactions = facets.factions || new Set();
        const fCosts = facets.costs || new Set();

        const results = cards.filter(card => {
            if (!card || !card.cid) return false;

            const cName = card.name || '';
            const cType = (card.type || '').toLowerCase();
            const cTier = parseInt(card.tier || 1, 10);
            const cCost = parseInt(card.cost || 0, 10);
            const cRarity = (card.rarity || '').toLowerCase();
            const cDesc = card.description || card.text || card.ability || '';

            // Card Factions (can be array or string)
            let cFactions = [];
            if (Array.isArray(card.factions)) {
                cFactions = card.factions.map(f => String(f).toLowerCase());
            } else if (card.faction) {
                cFactions = [String(card.faction).toLowerCase()];
            }

            // 1. Facet Chips Filters (OR within group, AND across groups)
            if (fTypes.size > 0 && !fTypes.has(cType)) return false;
            if (fTiers.size > 0 && !fTiers.has(cTier)) return false;
            if (fRarities.size > 0 && !fRarities.has(cRarity)) return false;
            if (fCosts.size > 0) {
                const costMatch = Array.from(fCosts).some(fc => {
                    if (fc === 7 && cCost >= 7) return true;
                    return fc === cCost;
                });
                if (!costMatch) return false;
            }
            if (fFactions.size > 0) {
                const hasFacetFaction = Array.from(fFactions).some(f => cFactions.includes(f));
                if (!hasFacetFaction) return false;
            }

            // 2. Syntax Shorthand Tokens
            if (tokens.types.length > 0 && !tokens.types.includes(cType)) return false;
            if (tokens.rarities.length > 0 && !tokens.rarities.includes(cRarity)) return false;
            if (tokens.tierMin !== null && cTier < tokens.tierMin) return false;
            if (tokens.tierMax !== null && cTier > tokens.tierMax) return false;
            if (tokens.costMin !== null && cCost < tokens.costMin) return false;
            if (tokens.costMax !== null && cCost > tokens.costMax) return false;

            if (tokens.factions.length > 0) {
                const matchFac = tokens.factions.some(tf => cFactions.some(cf => cf.includes(tf)));
                if (!matchFac) return false;
            }

            if (tokens.keywords.length > 0) {
                const matchKw = tokens.keywords.every(kw => cDesc.toLowerCase().includes(kw));
                if (!matchKw) return false;
            }

            // 3. Regular Expression Query (across Name, Description, and Faction)
            if (regex) {
                const matchesName = regex.test(cName);
                const matchesDesc = regex.test(cDesc);
                const matchesFaction = cFactions.some(f => regex.test(f));
                if (!matchesName && !matchesDesc && !matchesFaction) return false;
            }

            return true;
        });

        // Sort by Tier ascending, then Cost ascending, then Name alphabetical
        return results.sort((a, b) => {
            const tA = a.tier || 1;
            const tB = b.tier || 1;
            if (tA !== tB) return tA - tB;

            const cA = a.cost || 0;
            const cB = b.cost || 0;
            if (cA !== cB) return cA - cB;

            return (a.name || '').localeCompare(b.name || '');
        });
    }
}

if (typeof window !== 'undefined') {
    window.CardSearchEngine = CardSearchEngine;
}
if (typeof module !== 'undefined') {
    module.exports = { CardSearchEngine };
}
