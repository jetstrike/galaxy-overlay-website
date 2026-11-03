/**
 * Once Upon a Galaxy - Collection Matcher & Buildability Evaluator
 * Evaluates deck buildability against uploaded _collection and recommends budget replacements.
 */

class CollectionMatcher {
    /**
     * Evaluates a deck against the player's collection
     * @param {Object} deck - Deck object containing captain_cid and card_cids
     * @param {Object} collectionMap - Map of captain_cid => { owned_cids: Set, is_infinity: bool }
     * @param {Array} annotations - Array of { card_cid, alternate_cids, substitution_reason }
     */
    static evaluateDeck(deck, collectionMap = {}, annotations = []) {
        if (!deck || !Array.isArray(deck.card_cids)) {
            return { status: 'unknown', owned_count: 0, missing_count: 12, missing_cids: [], substitutions: [] };
        }

        const capCid = deck.captain_cid;
        const capData = collectionMap[capCid] || { owned_cids: new Set(), is_infinity: false };

        // Infinity unlock for this captain
        if (capData.is_infinity) {
            return {
                status: 'ready',
                label: '100% Ready to Play',
                badge_class: 'badge-ready',
                owned_count: 12,
                missing_count: 0,
                is_infinity: true,
                missing_cards: [],
                substitutions: []
            };
        }

        const ownedSet = capData.owned_cids || new Set();
        const altMap = new Map();
        for (const ann of annotations) {
            if (ann.card_cid && Array.isArray(ann.alternate_cids) && ann.alternate_cids.length > 0) {
                altMap.set(ann.card_cid, ann);
            }
        }

        let ownedCount = 0;
        const missingCards = [];
        const substitutionsApplied = [];

        for (const cid of deck.card_cids) {
            if (ownedSet.has(cid)) {
                ownedCount++;
            } else {
                // Check if an alternate card is owned
                const ann = altMap.get(cid);
                let subFound = null;
                if (ann) {
                    for (const altCid of ann.alternate_cids) {
                        if (ownedSet.has(altCid)) {
                            subFound = {
                                original_cid: cid,
                                replacement_cid: altCid,
                                reason: ann.substitution_reason || 'Author Recommended Alternative'
                            };
                            break;
                        }
                    }
                }

                if (subFound) {
                    substitutionsApplied.push(subFound);
                } else {
                    missingCards.push(cid);
                }
            }
        }

        const missingCount = missingCards.length;
        const totalEffectiveOwned = ownedCount + substitutionsApplied.length;

        let status = 'incomplete';
        let label = `Missing ${missingCount} Cards`;
        let badge_class = 'badge-missing';

        if (totalEffectiveOwned === 12) {
            if (substitutionsApplied.length === 0) {
                status = 'ready';
                label = '✓ 12/12 Owned';
                badge_class = 'badge-ready';
            } else {
                status = 'substitutable';
                label = `⚡ Buildable (${substitutionsApplied.length} Substitutions)`;
                badge_class = 'badge-substitutable';
            }
        } else if (missingCount <= 2) {
            status = 'missing_few';
            label = `⚠️ ${totalEffectiveOwned}/12 Owned (Missing ${missingCount})`;
            badge_class = 'badge-warning';
        } else {
            status = 'incomplete';
            label = `${totalEffectiveOwned}/12 Owned`;
            badge_class = 'badge-incomplete';
        }

        return {
            status,
            label,
            badge_class,
            owned_count: ownedCount,
            substitutable_count: substitutionsApplied.length,
            missing_count: missingCount,
            missing_cards: missingCards,
            substitutions: substitutionsApplied
        };
    }

    /**
     * Renders a badge element for a deck card
     */
    static renderBadgeHtml(evalResult) {
        if (!evalResult || evalResult.status === 'unknown') return '';
        return `<span class="coll-badge ${evalResult.badge_class}">${evalResult.label}</span>`;
    }
}

if (typeof window !== 'undefined') {
    window.CollectionMatcher = CollectionMatcher;
}
if (typeof module !== 'undefined') {
    module.exports = { CollectionMatcher };
}
