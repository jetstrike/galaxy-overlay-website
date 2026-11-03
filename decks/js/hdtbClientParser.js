/**
 * Once Upon a Galaxy - Client-Side Defold HDTB Binary Parser
 * High performance, zero-dependency browser parser for _deck_library and _collection.
 */

class HdtbClientParser {
    static CAPTAIN_NAMES = {
    "1001": "Wizard Headmaster",
    "1002": "Athena",
    "1005": "Chief Evil Officer",
    "1006": "CHUK-E",
    "1007": "Dorothy",
    "1008": "Dr. Agon",
    "1009": "Dungeon Master",
    "1010": "Hades",
    "1011": "Indiana Clones",
    "1012": "Jay Sparrow",
    "1013": "King Arthur",
    "1014": "King Midas",
    "1015": "Lil' Genie",
    "1016": "Professor Who's Who",
    "1017": "Melody",
    "1019": "Princess Animall",
    "1020": "Loki",
    "1022": "Skip, the Time Skipper",
    "1023": "The Collector",
    "1024": "This Little Piggie",
    "1025": "Thing in the Ice",
    "1027": "Geppetto",
    "1028": "Trash Panda",
    "1029": "Witches, III",
    "1327": "Elec-Drake",
    "1362": "Maximus",
    "1363": "Jason of the Argonauts",
    "1364": "Mr. Lamppost",
    "1365": "Sir Galahad",
    "1367": "Dragonmother",
    "1386": "Zheng Yi Sao",
    "1430": "Artemis",
    "1431": "Merryweather",
    "1432": "Silversmith",
    "1434": "Charon",
    "1436": "Lightning Thief",
    "1437": "Merrily",
    "1964": "Hannibal",
    "1968": "We Are Blorg",
    "1997": "Boy who Cried Werewolf",
    "1998": "Zagreus",
    "2063": "Sweet Tooth",
    "2064": "Romeo",
    "2065": "Tajima, Patron of Sweets",
    "2066": "Sorcerous Serena",
    "2067": "Candy Pandy",
    "2070": "Faker, the Caker Baker",
    "2084": "Charlie Pots",
    "2094": "Lady of the Lake",
    "2097": "Queen Harpolia",
    "2098": "Nathaniel Drake",
    "2107": "The Candyman",
    "2120": "Sensei",
    "2125": "Preeminent Prodigy",
    "2128": "Double Dealer",
    "2129": "Goat T",
    "2131": "Frowny the Clown",
    "2204": "Gloria Clementine",
    "2205": "Dotty Dawdles",
    "2206": "Luna Girl",
    "2208": "Mira",
    "2209": "The Beanstalk",
    "2210": "Gokatoo",
    "2211": "Old MacDonald",
    "2214": "Cupcup",
    "2218": "Sugar Pops",
    "2219": "Omniverse Omnivore",
    "2221": "Lucky Leprechaun",
    "2232": "Legend of Loxley",
    "2233": "Rumpelstiltskin",
    "2234": "Sapphire",
    "2236": "Toucan Samuel",
    "2238": "Vena Cava",
    "2241": "Baba Yaga",
    "2247": "Con Artist",
    "2249": "Itty Aerie",
    "2271": "Roulette",
    "3001": "Anagolay",
    "3002": "Catkiss Everburn",
    "3003": "Wayfinder",
    "3004": "Ares",
    "3006": "Baldr",
    "3018": "Nuada, Silver Vengence",
    "3023": "Izanami",
    "3028": "Set",
    "3038": "Iron Monkey",
    "3048": "Chicken Bob",
    "3073": "Galileo Galilei",
    "3074": "Nemesis",
    "3076": "Hecate",
    "3080": "Mictili",
    "3081": "Moonsong",
    "3082": "Mount Mimic",
    "3100": "Thraxus",
    "3104": "Yakety Yak Yak",
    "3105": "Dinonysus",
    "3106": "Mercury",
    "3110": "Runefeather",
    "3129": "Styx",
    "3130": "Thanatos",
    "3136": "Quetzalcoatl",
    "3216": "Ammit",
    "3217": "Apollo",
    "3218": "Mami Wata",
    "3219": "Spider Queen",
    "3221": "Osiris",
    "3222": "Bo Peep",
    "3223": "Dracusmagus",
    "3224": "Raging Bull",
    "4008": "Chromarian",
    "4020": "Mr. Monet",
    "4028": "Remi",
    "4034": "Swishbuckler",
    "4052": "Bing Wu",
    "4054": "Happy Accidental Painter",
    "4056": "Luna"
};

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
    // Instance & alias methods
    parseDeckLibrary(arrayBuffer, filename = '') {
        return HdtbClientParser.parseDeckLibraryFile(arrayBuffer, filename);
    }

    parseDeckLibraryFile(arrayBuffer, filename = '') {
        return HdtbClientParser.parseDeckLibraryFile(arrayBuffer, filename);
    }

    parseCollection(arrayBuffer, filename = '') {
        return HdtbClientParser.parseCollectionFile(arrayBuffer, filename);
    }

    parseCollectionFile(arrayBuffer, filename = '') {
        return HdtbClientParser.parseCollectionFile(arrayBuffer, filename);
    }

    parseHdtb(arrayBuffer) {
        return HdtbClientParser.parseHdtb(arrayBuffer);
    }

    static parseDeckLibrary(arrayBuffer, filename = '') {
        return this.parseDeckLibraryFile(arrayBuffer, filename);
    }

    static parseCollection(arrayBuffer, filename = '') {
        return this.parseCollectionFile(arrayBuffer, filename);
    }

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
                    name: (() => {
                    const capName = HdtbClientParser.CAPTAIN_NAMES[capCid] || ('Captain #' + capCid);
                    let raw = (dk.name || '').trim();
                    if (!raw || raw === 'Custom Deck' || raw === 'custom_deck' || raw === 'My Custom Deck') {
                        return (slotKey && String(slotKey) !== '1') ? (capName + ' (Deck ' + slotKey + ')') : capName;
                    }
                    return raw;
                })(),
                    deck_type: dk.deck_type || 'custom',
                    card_cids: cardCids,
                    last_used: Boolean(dk.last_used)
                };

                if (dk.deck_type === 'custom' || dk.deck_type === 'user' || dk.id === 'user') {
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
