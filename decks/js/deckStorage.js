/**
 * Once Upon a Galaxy - Client-Side Deck & Collection Storage Manager
 * Uses IndexedDB for rich persistent caching and localStorage for author identity.
 */

const DB_NAME = 'OUAG_DeckBuilder_DB';
const DB_VERSION = 3;

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

            // Defensive: support full parsed object or raw map
            const ownedCards = (collectionData && collectionData.owned_cards)
                ? collectionData.owned_cards
                : (collectionData || {});
            const infCaptains = new Set((collectionData && collectionData.infinity_captains) || []);

            for (const [capStr, cards] of Object.entries(ownedCards)) {
                const capCid = parseInt(capStr, 10);
                store.put({
                    captain_cid: capCid,
                    owned_cids: cards || [],
                    is_infinity: infCaptains.has(capCid)
                });
            }

            // Ensure any infinity captains that had 0 cards in ownedCards are stored with is_infinity = true
            for (const infCid of infCaptains) {
                if (!ownedCards[infCid] && !ownedCards[String(infCid)]) {
                    store.put({
                        captain_cid: infCid,
                        owned_cids: [],
                        is_infinity: true
                    });
                }
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
        if (playfab_id) {
            this.setPlayfabId(playfab_id);
        }
        try {
            const db = await this.getDB();
            if (!db.objectStoreNames.contains('imported_hdtb_decks')) {
                console.warn('Store imported_hdtb_decks does not exist in DB yet');
                return false;
            }
            return new Promise((resolve, reject) => {
                const tx = db.transaction('imported_hdtb_decks', 'readwrite');
                const store = tx.objectStore('imported_hdtb_decks');

                for (const dk of decks) {
                    const capCid = Number(dk.captain_cid);
                    const slotId = String(dk.slot_id);
                    const id = `hdtb_${capCid}_${slotId}`;
                    const name = dk.name || dk.deck_name || 'In-Game Deck';
                    store.put({
                        id,
                        playfab_id: playfab_id || dk.playfab_id || this.getPlayfabId() || '',
                        captain_cid: capCid,
                        slot_id: slotId,
                        name: name,
                        deck_name: name,
                        deck_type: dk.deck_type || 'custom',
                        card_cids: (dk.card_cids || []).map(Number),
                        last_used: Boolean(dk.last_used),
                        synced_at: dk.synced_at || new Date().toISOString()
                    });
                }

                tx.oncomplete = () => resolve(true);
                tx.onerror = () => reject(tx.error);
            });
        } catch (e) {
            console.warn('saveImportedDecks error:', e);
            return false;
        }
    }

    static async syncImportedDecksToCloud(playfab_id, decks) {
        if (!decks || decks.length === 0) return { success: false, message: 'No decks to sync' };
        try {
            const res = await fetch('/api/sync-hdtb.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Author-Token': this.getAuthorToken()
                },
                body: JSON.stringify({
                    playfab_id: playfab_id || this.getPlayfabId() || '',
                    decks: decks
                })
            });
            return await res.json();
        } catch(err) {
            console.warn('syncImportedDecksToCloud network error:', err);
            return { success: false, error: err.message };
        }
    }

    static async getImportedDecks(forceCloud = false) {
        if (!forceCloud) {
            try {
                const db = await this.getDB();
                if (db.objectStoreNames.contains('imported_hdtb_decks')) {
                    const localDecks = await new Promise((resolve, reject) => {
                        const tx = db.transaction('imported_hdtb_decks', 'readonly');
                        const store = tx.objectStore('imported_hdtb_decks');
                        const req = store.getAll();
                        req.onsuccess = () => resolve(req.result || []);
                        req.onerror = () => reject(req.error);
                    });

                    if (localDecks && localDecks.length > 0) {
                        return localDecks;
                    }
                }
            } catch (e) {
                console.warn('IndexedDB getImportedDecks error:', e);
            }
        }

        // Cloud fallback or forced cloud sync
        try {
            const playfabId = this.getPlayfabId();
            const url = playfabId ? `/api/sync-hdtb.php?playfab_id=${encodeURIComponent(playfabId)}` : '/api/sync-hdtb.php';
            const res = await fetch(url);
            const data = await res.json();
            if (data && data.success && Array.isArray(data.decks) && data.decks.length > 0) {
                if (data.playfab_id) {
                    this.setPlayfabId(data.playfab_id);
                }
                const normalized = data.decks.map(d => ({
                    id: d.id || `hdtb_${d.captain_cid}_${d.slot_id}`,
                    playfab_id: d.playfab_id || data.playfab_id || '',
                    captain_cid: Number(d.captain_cid),
                    slot_id: String(d.slot_id),
                    name: d.deck_name || d.name || d.captain_name || 'Deck',
                    deck_name: d.deck_name || d.name || d.captain_name || 'Deck',
                    deck_type: d.deck_type || 'custom',
                    card_cids: (d.card_cids || []).map(Number),
                    last_used: Boolean(d.last_used),
                    synced_at: d.synced_at || new Date().toISOString()
                }));
                // Cache locally for instant next loads
                this.saveImportedDecks(normalized, data.playfab_id || playfabId).catch(() => {});
                return normalized;
            }
        } catch (netErr) {
            console.warn('Failed cloud fallback for in-game decks:', netErr);
        }

        return [];
    }
static async deleteImportedDeck(deckId, capCid, slotId) {
        try {
            const db = await this.getDB();
            if (db.objectStoreNames.contains('imported_hdtb_decks')) {
                const tx = db.transaction('imported_hdtb_decks', 'readwrite');
                const store = tx.objectStore('imported_hdtb_decks');
                store.delete(deckId);
                if (capCid && slotId) {
                    store.delete(`hdtb_${capCid}_${slotId}`);
                }
            }
        } catch (e) {
            console.warn('Local delete imported deck error:', e);
        }

        try {
            const playfabId = this.getPlayfabId();
            await fetch('/api/sync-hdtb.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Author-Token': this.getAuthorToken()
                },
                body: JSON.stringify({
                    action: 'delete',
                    id: deckId,
                    playfab_id: playfabId,
                    captain_cid: capCid,
                    slot_id: slotId
                })
            });
        } catch (netErr) {
            console.warn('Cloud delete imported deck error:', netErr);
        }

        return true;
    }

    static async deleteCommunityDeck(deckId) {
        const res = await fetch('/api/deck.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Author-Token': this.getAuthorToken()
            },
            body: JSON.stringify({
                action: 'delete',
                deck_id: deckId,
                author_id: this.getAuthorToken()
            })
        });
        const data = await res.json();
        if (data && data.success) {
            await this.deleteLocalDeck(deckId).catch(() => {});
            return true;
        }
        throw new Error(data.error || 'Failed to delete deck');
    }
}

if (typeof window !== 'undefined') {
    window.DeckStorage = DeckStorage;
}
if (typeof module !== 'undefined') {
    module.exports = { DeckStorage };
}
