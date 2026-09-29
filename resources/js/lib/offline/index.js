import { reactive } from 'vue';
import { api } from '../http';
import { session } from '../session';
import { OfflineEngine, OfflineError } from './engine';
import { OfflineStore } from './store';

/*
 * Offline work in the app (Phase 7-2): one engine for the person and the
 * organization they are working in, and a small reactive state for the
 * screens (pending count, last sync, lease end, problems to look at). It
 * syncs when the connection comes back and every few minutes while open.
 */
export { OfflineError };

export const offline = reactive({
    ready: false,
    enabled: false,
    online: globalThis.navigator?.onLine ?? true,
    syncing: false,
    pending: 0,
    outcomes: [],
    expiresAt: null,
    lastSync: null,
    lastStatus: null,
});

const SYNC_EVERY_MS = 5 * 60 * 1000;

let engine = null;
let engineFor = null;
let timer = null;

function currentKey() {
    const context = session.me?.context;
    return context?.type === 'organization' && session.me?.user ? `${session.me.user.id}:${context.id}` : null;
}

async function current() {
    const key = currentKey();
    if (!key) return null;
    if (engine && engineFor === key) return engine;

    engine?.store.close();
    const [userId, organizationId] = key.split(':');
    engine = new OfflineEngine(await new OfflineStore(userId, organizationId).open(), api);
    engineFor = key;

    return engine;
}

async function refresh() {
    const active = await current();
    if (!active) {
        Object.assign(offline, { ready: true, enabled: false, pending: 0, outcomes: [], expiresAt: null });
        return;
    }

    const lease = await active.lease();
    Object.assign(offline, {
        ready: true,
        enabled: (await active.device()) !== null,
        pending: await active.pending(),
        outcomes: await active.outcomes(),
        expiresAt: lease?.expires_at ?? null,
    });
}

/** Sets this browser up for offline work in the current organization. */
export async function enableOffline(name) {
    const active = await current();
    await active.enable(name, globalThis.navigator?.userAgentData?.platform ?? null);
    await refresh();
    startAutoSync();
}

export async function syncNow() {
    const active = await current();
    if (!active || offline.syncing) return null;

    offline.syncing = true;
    try {
        const summary = await active.sync();
        offline.lastStatus = summary.status;
        if (summary.status === 'ok') offline.lastSync = new Date().toISOString();
        if (summary.status === 'wiped') {
            engine = null;
            engineFor = null;
        }

        return summary;
    } finally {
        offline.syncing = false;
        await refresh();
    }
}

/** For module screens: record a change made offline (it syncs later). */
export async function enqueueOffline(kind, action, data = {}, recordId = null, baseVersion = null) {
    const active = await current();
    const operation = await active.enqueue(kind, action, data, recordId, baseVersion);
    offline.pending = await active.pending();

    return operation;
}

export async function offlineRecords(kind) {
    const active = await current();
    return active ? active.store.records(kind) : [];
}

export async function dismissOutcome(opId) {
    await (await current())?.dismiss(opId);
    await refresh();
}

/** Signing out: every offline store of this browser is wiped. */
export async function wipeAllOffline() {
    engine?.store.close();
    engine = null;
    engineFor = null;
    await OfflineStore.wipeAll();
    Object.assign(offline, { enabled: false, pending: 0, outcomes: [], expiresAt: null, lastSync: null });
}

/** Changes that would be lost by wiping now (all stores of this browser count only the current one). */
export async function unsyncedCount() {
    const active = await current().catch(() => null);
    return active ? active.pending() : 0;
}

export function startAutoSync() {
    if (timer) return;
    timer = setInterval(() => offline.enabled && offline.online && syncNow(), SYNC_EVERY_MS);
}

let listening = false;

/** Safe to call again (sign-in, switching organization): listeners are added once. */
export async function initOffline() {
    if (!listening) {
        listening = true;
        globalThis.addEventListener?.('online', () => {
            offline.online = true;
            if (offline.enabled) syncNow();
        });
        globalThis.addEventListener?.('offline', () => (offline.online = false));
    }

    await refresh();
    if (offline.enabled) {
        startAutoSync();
        if (offline.online) syncNow();
    }
}
