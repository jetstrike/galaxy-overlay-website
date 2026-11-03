/**
 * Once Upon a Galaxy - Client-Side Deck & Collection Storage Manager
 * Uses IndexedDB for rich persistent caching and localStorage for author identity.
 */

const DB_NAME = 'OUAG_DeckBuilder_DB';
const DB_VERSION = 1;

class DeckStorage {
    static getAuthorToken() {
        let token = localStorage.getItem('ouag_author_token');
        if (!token) {
            const arr = new Uint8Array(16);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(arr);
            } else {
                for (let i = 0; i < 16; i++) arr[i] = Math.floor(Math.random() * 256);
            }
            token = Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
            localStorage.setItem('ouag_author_token', token);
        }
        return token;
    }

    static getAuthorName() {
        return localStorage.getItem('ouag_author_name') || 'Anonymous Captain';
    }

    static setAuthorName(name) {
        if (name && typeof name === 'string') {
            localStorage.setItem('ouag_author_name', name.trim());
        }
    }

    static getPlayfabId() {
        return localStorage.getItem('ouag_playfab_id') || '';
    }

    static setPlayfabId(id) {
        if (id) localStorage.setItem('ouag_playfab_id', String(id).toUpperCase().trim());
    }

    static async getDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;

                // 1. Local custom deck drafts & saved creations
                if (!db.objectStoreNames.contains('local_decks')) {
                    const store = db.createObjectStore('local_decks', { keyPath: 'id' });
                    store.createIndex('updated_at', 'updated_at', { unique: false });
                    store.createIndex('captain_cid', 'captain_cid', { unique: false });
                }

                // 2. Imported in-game HDTB decks
                if (!db.objectStoreNames.contains('imported_hdtb_decks')) {
                    const store = db.createObjectStore('imported_hdtb_decks', { keyPath: 'id' });
                    store.createIndex('captain_cid', 'captain_cid', { unique: false });
                }

                // 3. Cached owned collection
                if (!db.objectStoreNames.contains('user_collection')) {
                    db.createObjectStore('user_collection', { keyPath: 'captain_cid' });
                }
            };

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Active Draft (autosaves while typing in builder)
     */
    static saveActiveDraft(draft) {
        try {
            localStorage.setItem('ouag_active_draft', JSON.stringify(draft));
        } catch (e) {
            console.warn('Failed to save draft to localStorage:', e);
        }
    }

    static getActiveDraft() {
        try {
            const item = localStorage.getItem('ouag_active_draft');
            return item ? JSON.parse(item) : null;
        } catch (e) {
            return null;
        }
    }

    static clearActiveDraft() {
        localStorage.removeItem('ouag_active_draft');
    }

    /**
     * Local Decks (IndexedDB)
     */
    static async saveLocalDeck(deck) {
        const db = await this.getDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('local_decks', 'readwrite');
            const store = tx.objectStore('local_decks');
            deck.updated_at = new Date().toISOString();
            if (!deck.id) deck.id = 'local_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5);
            store.put(deck);
            tx.oncomplete = () => resolve(deck);
            tx.onerror = () => reject(tx.error);
        });
    }

    static async getLocalDecks() {
        const db = await this.getDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('local_decks', 'readonly');
            const store = tx.objectStore('local_decks');
            const request = store.getAll();
            request.onsuccess = () => resolve(request.result || []);
            request.onerror = () => reject(request.error);
        });
    }

    static async deleteLocalDeck(id) {
        const db = await this.getDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('local_decks', 'readwrite');
            const store = tx.objectStore('local_decks');
            store.delete(id);
            tx.oncomplete = () => resolve(true);
            tx.onerror = () => reject(tx.error);
        });
    }

    /**
     * User Collection (IndexedDB)
     */
    static async saveUserCollection(collectionData) {
        const db = await this.getDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('user_collection', 'readwrite');
            const store = tx.objectStore('user_collection');
            store.clear();

            const ownedCards = collectionData.owned_cards || {};
            const infCaptains = new Set(collectionData.infinity_captains || []);

            for (const [capStr, cards] of Object.entries(ownedCards)) {
                const capCid = parseInt(capStr, 10);
                store.put({
                    captain_cid: capCid,
                    owned_cids: cards || [],
                    is_infinity: infCaptains.has(capCid)
                });
            }

            if (collectionData.playfab_id) {
                this.setPlayfabId(collectionData.playfab_id);
            }

            tx.oncomplete = () => resolve(true);
            tx.onerror = () => reject(tx.error);
        });
    }

    static async getUserCollection() {
        const db = await this.getDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('user_collection', 'readonly');
            const store = tx.objectStore('user_collection');
            const req = store.getAll();
            req.onsuccess = () => {
                const map = {};
                for (const row of (req.result || [])) {
                    map[row.captain_cid] = {
                        owned_cids: new Set(row.owned_cids || []),
                        is_infinity: Boolean(row.is_infinity)
                    };
                }
                resolve(map);
            };
            req.onerror = () => reject(req.error);
        });
    }

    /**
     * Imported In-Game HDTB Decks (IndexedDB)
     */
    static async saveImportedDecks(decks, playfab_id = '') {
        const db = await this.getDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('imported_hdtb_decks', 'readwrite');
            const store = tx.objectStore('imported_hdtb_decks');

            for (const dk of decks) {
                const id = `hdtb_${dk.captain_cid}_${dk.slot_id}`;
                store.put({
                    id,
                    playfab_id,
                    captain_cid: dk.captain_cid,
                    slot_id: dk.slot_id,
                    name: dk.name,
                    deck_type: dk.deck_type,
                    card_cids: dk.card_cids,
                    last_used: dk.last_used,
                    synced_at: new Date().toISOString()
                });
            }

            tx.oncomplete = () => resolve(true);
            tx.onerror = () => reject(tx.error);
        });
    }

    static async getImportedDecks() {
        const db = await this.getDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('imported_hdtb_decks', 'readonly');
            const store = tx.objectStore('imported_hdtb_decks');
            const req = store.getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
        });
    }
}

if (typeof window !== 'undefined') {
    window.DeckStorage = DeckStorage;
}
if (typeof module !== 'undefined') {
    module.exports = { DeckStorage };
}
