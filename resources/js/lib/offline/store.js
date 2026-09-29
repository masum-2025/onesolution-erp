import { add, all, allByKind, count, deleteDatabase, get, openDatabase, put, remove } from './db';
import { createKey, seal, unseal } from './crypto';
import { readPref, writePref } from '../storage';

/*
 * The encrypted offline store of one person in one organization (Phase 7-2).
 * Every value is sealed with AES-GCM before it touches IndexedDB; only the
 * non-extractable key itself is kept as a CryptoKey. Record keys and kinds
 * stay readable (they are ids, not data) so records can be looked up.
 *
 * The names of the stores this browser holds are remembered (a preference, ids only),
 * so signing out can wipe every one of them, whatever organization.
 */
const REGISTRY = 'offline.stores';

function registry() {
    try {
        return JSON.parse(readPref(REGISTRY) ?? '[]');
    } catch {
        return [];
    }
}

// Private mode without storage: the store still works until the tab closes.
function remember(names) {
    writePref(REGISTRY, JSON.stringify([...new Set(names)]));
}

export class OfflineStore {
    static nameFor(userId, organizationId) {
        return `os-offline:${userId}:${organizationId}`;
    }

    /** Wipes every offline store of this browser (signing out, a wipe order). */
    static async wipeAll(factory = globalThis.indexedDB) {
        await Promise.all(registry().map((name) => deleteDatabase(name, factory)));
        remember([]);
    }

    constructor(userId, organizationId, factory = globalThis.indexedDB) {
        this.name = OfflineStore.nameFor(userId, organizationId);
        this.factory = factory;
        this.db = null;
        this.key = null;
    }

    async open() {
        if (this.db) return this;
        this.db = await openDatabase(this.name, this.factory);
        remember([...registry(), this.name]);

        const stored = await get(this.db, 'meta', 'key');
        if (stored) {
            this.key = stored.value;
        } else {
            this.key = await createKey();
            await put(this.db, 'meta', { key: 'key', value: this.key });
        }

        return this;
    }

    close() {
        this.db?.close();
        this.db = null;
    }

    async wipe() {
        this.close();
        await deleteDatabase(this.name, this.factory);
        remember(registry().filter((name) => name !== this.name));
    }

    // ── meta (device, lease, cursor) ──
    async getMeta(name) {
        const row = await get(this.db, 'meta', `m:${name}`);
        return row ? unseal(this.key, row.value) : null;
    }

    async setMeta(name, value) {
        await put(this.db, 'meta', { key: `m:${name}`, value: await seal(this.key, value) });
    }

    // ── records the device keeps ──
    async putRecord(kind, record) {
        await put(this.db, 'records', { key: `${kind}:${record.id}`, kind, value: await seal(this.key, record) });
    }

    async removeRecord(kind, id) {
        await remove(this.db, 'records', `${kind}:${id}`);
    }

    async records(kind) {
        const rows = await allByKind(this.db, kind);
        return Promise.all(rows.map((row) => unseal(this.key, row.value)));
    }

    // ── changes waiting to be synced, in order ──
    async enqueue(operation) {
        return add(this.db, 'queue', { value: await seal(this.key, operation) });
    }

    /** Waiting changes, oldest first: [{ seq, operation }]. */
    async queue() {
        const rows = await all(this.db, 'queue');
        return Promise.all(rows.map(async (row) => ({ seq: row.seq, operation: await unseal(this.key, row.value) })));
    }

    async dequeue(seq) {
        await remove(this.db, 'queue', seq);
    }

    pending() {
        return count(this.db, 'queue');
    }

    // ── changes the server did not apply, for the person to see ──
    async setOutcome(opId, outcome) {
        await put(this.db, 'outcomes', { op_id: opId, value: await seal(this.key, outcome) });
    }

    async outcomes() {
        const rows = await all(this.db, 'outcomes');
        return Promise.all(rows.map((row) => unseal(this.key, row.value)));
    }

    async clearOutcome(opId) {
        await remove(this.db, 'outcomes', opId);
    }
}
