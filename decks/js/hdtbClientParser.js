/**
 * Once Upon a Galaxy - Client-Side Defold HDTB Binary Parser
 * High performance, zero-dependency browser parser for _deck_library and _collection.
 */

class HdtbClientParser {
    /**
     * Parses a standard Defold HDTB table from an ArrayBuffer
     */
    static parseHdtb(arrayBuffer) {
        const view = new DataView(arrayBuffer);
        const decoder = new TextDecoder('utf-8');

        // Check Magic
        const magic = decoder.decode(new Uint8Array(arrayBuffer, 0, 4));
        if (magic !== 'HDTB') {
            throw new Error('Invalid magic bytes. Expected HDTB.');
        }

        const version = view.getUint32(4, true);
        let offset = 8;

        function readUint32() {
            const val = view.getUint32(offset, true);
            offset += 4;
            return val;
        }

        function alignBuffer() {
            const current = offset;
            const aligned = (current + 3) & ~3;
            offset += (aligned - current);
        }

        function readValue(typeTag) {
            if (typeTag === 0) { // Nil
                return null;
            } else if (typeTag === 1) { // Boolean
                const val = view.getUint8(offset);
                offset += 1;
                return val !== 0;
            } else if (typeTag === 3) { // Number (Double LE)
                alignBuffer();
                const val = view.getFloat64(offset, true);
                offset += 8;
                return val;
            } else if (typeTag === 4) { // String
                const length = readUint32();
                const strBytes = new Uint8Array(arrayBuffer, offset, length);
                const str = decoder.decode(strBytes);
                offset += length;
                return str;
            } else if (typeTag === 5) { // Table
                return readTable();
            } else {
                throw new Error(`Unknown type tag: ${typeTag} at offset ${offset - 1}`);
            }
        }

        function readKey(typeTag) {
            if (typeTag === 3) {
                return readUint32();
            } else if (typeTag === 4) {
                const length = readUint32();
                const strBytes = new Uint8Array(arrayBuffer, offset, length);
                const str = decoder.decode(strBytes);
                offset += length;
                return str;
            } else {
                throw new Error(`Unknown key type tag: ${typeTag} at offset ${offset - 1}`);
            }
        }

        function readTable() {
            const count = readUint32();
            const res = {};
            for (let i = 0; i < count; i++) {
                const keyType = view.getUint8(offset);
                offset += 1;
                const valType = view.getUint8(offset);
                offset += 1;
                const key = readKey(keyType);
                const val = readValue(valType);
                res[key] = val;
            }
            return res;
        }

        const root = readTable();
        return root.data ? root.data : root;
    }

    /**
     * Extracts decks from an uploaded _deck_library file
     * Returns { playfab_id, custom_decks, starter_decks, store_decks }
     */
    static parseDeckLibraryFile(arrayBuffer, filename = '') {
        const raw = this.parseHdtb(arrayBuffer);
        const decksByCaptain = raw.decks_by_captain || {};

        let playfab_id = '';
        const match = filename.match(/([a-fA-F0-9]{16})_deck_library/);
        if (match) {
            playfab_id = match[1].toUpperCase();
        }

        const custom_decks = [];
        const starter_decks = [];
        const store_decks = [];

        for (const [captainId, slotMap] of Object.entries(decksByCaptain)) {
            const capCid = parseInt(captainId, 10);
            if (!slotMap || typeof slotMap !== 'object') continue;

            for (const [slotKey, dk] of Object.entries(slotMap)) {
                if (!dk || typeof dk !== 'object') continue;

                // Extract 12 cards
                const cardCids = [];
                const cardsObj = dk.cards || {};
                for (let i = 1; i <= 12; i++) {
                    const cid = cardsObj[i] || cardsObj[String(i)];
                    if (cid != null) cardCids.push(parseInt(cid, 10));
                }

                const deckObj = {
                    captain_cid: capCid,
                    slot_id: String(slotKey),
                    name: dk.name || `Deck ${slotKey}`,
                    deck_type: dk.deck_type || 'custom',
                    card_cids: cardCids,
                    last_used: Boolean(dk.last_used)
                };

                if (dk.deck_type === 'custom') {
                    custom_decks.push(deckObj);
                } else if (dk.deck_type === 'starter') {
                    starter_decks.push(deckObj);
                } else {
                    store_decks.push(deckObj);
                }
            }
        }

        return {
            playfab_id,
            custom_decks,
            starter_decks,
            store_decks,
            total_custom: custom_decks.length
        };
    }

    /**
     * Fast binary parser for Once Upon a Galaxy _collection files
     * Returns { playfab_id, owned_cards_by_captain, infinity_captains }
     */
    static parseCollectionFile(arrayBuffer, filename = '') {
        const u8 = new Uint8Array(arrayBuffer);
        const view = new DataView(arrayBuffer);
        const decoder = new TextDecoder('utf-8');

        let playfab_id = '';
        const match = filename.match(/([a-fA-F0-9]{16})_collection/);
        if (match) {
            playfab_id = match[1].toUpperCase();
        }

        // Marker for "cards" table: 0x04 0x05 0x05 0x00 0x00 0x00 "cards"
        const marker = [0x04, 0x05, 0x05, 0x00, 0x00, 0x00, 0x63, 0x61, 0x72, 0x64, 0x73];
        let pos = -1;

        for (let i = 0; i <= u8.length - marker.length; i++) {
            let matchMarker = true;
            for (let j = 0; j < marker.length; j++) {
                if (u8[i + j] !== marker[j]) {
                    matchMarker = false;
                    break;
                }
            }
            if (matchMarker) {
                pos = i;
                break;
            }
        }

        if (pos < 0) {
            // Fallback: try standard HDTB table
            try {
                const fullHdtb = this.parseHdtb(arrayBuffer);
                const cards = fullHdtb.cards || {};
                const owned = {};
                const inf = new Set();
                for (const [capStr, cardList] of Object.entries(cards)) {
                    const cap = parseInt(capStr, 10);
                    owned[cap] = [];
                    if (cardList && typeof cardList === 'object') {
                        for (const [cStr, val] of Object.entries(cardList)) {
                            if (val === 'INF') inf.add(cap);
                            owned[cap].push(parseInt(cStr, 10));
                        }
                    }
                }
                return { playfab_id, owned_cards: owned, infinity_captains: Array.from(inf) };
            } catch (err) {
                throw new Error('Valid cards table not found in collection file.');
            }
        }

        pos += marker.length;
        if (pos + 4 > u8.length) throw new Error('Collection file incomplete.');

        const outerCount = view.getUint32(pos, true);
        pos += 4;

        const ownedCardsByCaptain = {};
        const infinityCaptains = new Set();

        for (let i = 0; i < outerCount; i++) {
            if (pos + 2 > u8.length) break;

            const markerA = u8[pos];
            const markerB = u8[pos + 1];

            if (markerA === 0x03 && markerB === 0x05) {
                pos += 2;
                if (pos + 8 > u8.length) break;

                const captainCid = view.getUint32(pos, true);
                pos += 4;
                const unlockedCount = view.getUint32(pos, true);
                pos += 4;

                const owned = [];
                for (let c = 0; c < unlockedCount; c++) {
                    if (pos + 10 > u8.length) break;
                    if (u8[pos] !== 0x03 || u8[pos + 1] !== 0x04) break;

                    pos += 2;
                    const unlockedCardCid = view.getUint32(pos, true);
                    pos += 4;
                    owned.push(unlockedCardCid);

                    const valLen = view.getUint32(pos, true);
                    pos += 4 + valLen;
                }
                ownedCardsByCaptain[captainCid] = owned;

            } else if (markerA === 0x03 && markerB === 0x04) {
                pos += 2;
                if (pos + 8 > u8.length) break;

                const captainCid = view.getUint32(pos, true);
                pos += 4;
                const valLen = view.getUint32(pos, true);
                pos += 4;

                if (pos + valLen <= u8.length) {
                    const strBytes = new Uint8Array(arrayBuffer, pos, valLen);
                    const val = decoder.decode(strBytes);
                    pos += valLen;
                    if (val === 'INF') {
                        infinityCaptains.add(captainCid);
                        ownedCardsByCaptain[captainCid] = [];
                    }
                }
            } else {
                break;
            }
        }

        return {
            playfab_id,
            owned_cards: ownedCardsByCaptain,
            infinity_captains: Array.from(infinityCaptains)
        };
    }
}

if (typeof window !== 'undefined') {
    window.HdtbClientParser = HdtbClientParser;
}
if (typeof module !== 'undefined') {
    module.exports = { HdtbClientParser };
}
