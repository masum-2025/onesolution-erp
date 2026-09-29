import { beforeEach, describe, expect, it, vi } from 'vitest';
import { IDBFactory } from 'fake-indexeddb';
import { get, openDatabase } from '@/lib/offline/db';
import { OfflineStore } from '@/lib/offline/store';
import { OfflineEngine, OfflineError } from '@/lib/offline/engine';

/*
 * The browser side of offline work (Phase 7-2): the encrypted store and the
 * sync engine, against an in-memory IndexedDB and a fake server.
 */
const NOW = Date.parse('2026-10-01T10:00:00Z');
const FUTURE = '2026-10-04T10:00:00Z';

function leaseData(overrides = {}) {
    return {
        lease: 'signed-lease',
        lease_id: 'L1',
        expires_at: FUTURE,
        kinds: ['crm.note', 'crm.receipt'],
        kind_info: {
            'crm.note': { money: false, permissions: { create: 'crm.create', update: 'crm.update', delete: 'crm.delete' } },
            'crm.receipt': { money: true, permissions: { create: 'crm.create', update: 'crm.update', delete: 'crm.delete' } },
        },
        permissions: ['crm.create', 'crm.update', 'crm.delete'],
        rules: { 'offline_mode.allow_offline_payments': true, 'offline_mode.sync_batch_max': 200 },
        ...overrides,
    };
}

function syncAnswer(results = {}, extra = {}) {
    return { wipe: false, results, changes: {}, cursor: 'c1', ...leaseData(), ...extra };
}

let factory;

async function makeEngine(api, { user = 'U1', org = 'O1', lease = leaseData(), now = NOW } = {}) {
    const store = await new OfflineStore(user, org, factory).open();
    const engine = new OfflineEngine(store, api, { now: () => now });
    await store.setMeta('device', { id: 'D1' });
    await engine.saveLease(lease);

    return engine;
}

beforeEach(() => {
    factory = new IDBFactory();
    localStorage.clear();
});

describe('offline store', () => {
    it('keeps nothing readable at rest and a key that cannot be read out', async () => {
        const store = await new OfflineStore('U1', 'O1', factory).open();
        await store.putRecord('crm.note', { id: 'N1', body: 'Rahim owes 5000' });
        await store.enqueue({ op_id: 'op1', data: { body: 'Karim secret' } });
        await store.setMeta('lease', { lease: 'signed-lease-token' });
        store.close();

        const raw = await openDatabase(OfflineStore.nameFor('U1', 'O1'), factory);
        const record = await get(raw, 'records', 'crm.note:N1');
        const key = (await get(raw, 'meta', 'key')).value;
        const dump = JSON.stringify([record, await get(raw, 'meta', 'm:lease'), await get(raw, 'queue', 1)], (_, value) =>
            ArrayBuffer.isView(value) ? new TextDecoder().decode(value) : value,
        );
        raw.close();

        expect(ArrayBuffer.isView(record.value.iv)).toBe(true);
        expect(dump).not.toContain('Rahim');
        expect(dump).not.toContain('Karim');
        expect(dump).not.toContain('signed-lease-token');
        expect(key.extractable).toBe(false);
        await expect(crypto.subtle.exportKey('raw', key)).rejects.toThrow();
    });

    it('opens again with the same key and reads its data back', async () => {
        const first = await new OfflineStore('U1', 'O1', factory).open();
        await first.putRecord('crm.note', { id: 'N1', body: 'kept' });
        first.close();

        const again = await new OfflineStore('U1', 'O1', factory).open();
        expect(await again.records('crm.note')).toEqual([{ id: 'N1', body: 'kept' }]);
    });

    it('keeps one separate store per person and organization', async () => {
        const mine = await new OfflineStore('U1', 'O1', factory).open();
        const otherOrg = await new OfflineStore('U1', 'O2', factory).open();
        const otherUser = await new OfflineStore('U2', 'O1', factory).open();
        await mine.putRecord('crm.note', { id: 'N1' });

        expect(await otherOrg.records('crm.note')).toEqual([]);
        expect(await otherUser.records('crm.note')).toEqual([]);
        expect(new Set([mine.name, otherOrg.name, otherUser.name]).size).toBe(3);
    });

    it('wipes every store of this browser on sign-out', async () => {
        const a = await new OfflineStore('U1', 'O1', factory).open();
        const b = await new OfflineStore('U1', 'O2', factory).open();
        await a.putRecord('crm.note', { id: 'N1' });
        await b.enqueue({ op_id: 'op1' });
        a.close();
        b.close();

        await OfflineStore.wipeAll(factory);

        expect(JSON.parse(localStorage.getItem('os.offline.stores'))).toEqual([]);
        const names = (await factory.databases()).map((db) => db.name);
        expect(names).not.toContain(a.name);
        expect(names).not.toContain(b.name);
    });
});

describe('offline changes', () => {
    it('queues changes in order with their own op ids', async () => {
        const engine = await makeEngine(vi.fn());
        const first = await engine.enqueue('crm.note', 'create', { body: 'one' });
        const second = await engine.enqueue('crm.note', 'update', { body: 'two' }, 'N1', 3);

        const queue = await engine.store.queue();
        expect(queue.map((row) => row.operation.op_id)).toEqual([first.op_id, second.op_id]);
        expect(first.op_id).not.toBe(second.op_id);
        expect(second).toMatchObject({ record_id: 'N1', base_version: 3, lease_id: 'L1', made_at: '2026-10-01T10:00:00.000Z' });
        expect(await engine.pending()).toBe(2);
    });

    it.each([
        ['an expired lease', { expires_at: '2026-09-30T00:00:00Z' }, 'crm.note', 'create', 'lease_expired'],
        ['a kind the lease leaves out', {}, 'hr.leave', 'create', 'kind_unavailable'],
        ['changing a money record', {}, 'crm.receipt', 'update', 'append_only'],
        ['money while offline payments are off', { rules: { 'offline_mode.allow_offline_payments': false } }, 'crm.receipt', 'create', 'offline_payments_off'],
        ['a missing permission', { permissions: ['crm.create'] }, 'crm.note', 'delete', 'forbidden'],
    ])('refuses early: %s', async (_, lease, kind, action, code) => {
        const engine = await makeEngine(vi.fn(), { lease: leaseData(lease) });

        await expect(engine.enqueue(kind, action, {}, 'R1')).rejects.toMatchObject({ name: 'OfflineError', code });
        expect(await engine.pending()).toBe(0);
    });

    it('accepts a new money record when offline payments are allowed', async () => {
        const engine = await makeEngine(vi.fn());
        await expect(engine.enqueue('crm.receipt', 'create', { amount: 5000, currency: 'BDT' })).resolves.toMatchObject({ kind: 'crm.receipt' });
    });

    it('exposes the error type', () => {
        expect(new OfflineError('lease_expired').code).toBe('lease_expired');
    });
});

describe('sync', () => {
    it('clears applied changes, keeps the others for the person, applies server changes', async () => {
        const api = vi.fn();
        const engine = await makeEngine(api);
        await engine.store.putRecord('crm.note', { id: 'OLD', body: 'gone on the server' });
        const applied = await engine.enqueue('crm.note', 'create', { body: 'fine' });
        const conflict = await engine.enqueue('crm.note', 'update', { body: 'late' }, 'N2', 1);

        api.mockResolvedValueOnce(
            syncAnswer(
                {
                    [applied.op_id]: { status: 'applied', record_id: 'N1', version: 1 },
                    [conflict.op_id]: { status: 'conflict', record_id: 'N2', version: 2, server: { body: 'newer' } },
                },
                { changes: { 'crm.note': { records: [{ id: 'N1', body: 'fine', version: 1 }], deleted: ['OLD'] } }, cursor: 'c9', lease_id: 'L2' },
            ),
        );

        const summary = await engine.sync();

        expect(summary).toMatchObject({ status: 'ok', sent: 2, applied: 1 });
        expect(await engine.pending()).toBe(0);
        const outcomes = await engine.outcomes();
        expect(outcomes).toHaveLength(1);
        expect(outcomes[0]).toMatchObject({ status: 'conflict', operation: { op_id: conflict.op_id } });
        expect(await engine.store.records('crm.note')).toEqual([{ id: 'N1', body: 'fine', version: 1 }]);
        expect(await engine.store.getMeta('cursor')).toBe('c9');
        expect((await engine.lease()).lease_id).toBe('L2');

        const body = api.mock.calls[0][1].body;
        expect(body).toMatchObject({ device_id: 'D1', lease: 'signed-lease', cursor: null });
        expect(body.operations.map((op) => op.op_id)).toEqual([applied.op_id, conflict.op_id]);

        await engine.dismiss(conflict.op_id);
        expect(await engine.outcomes()).toEqual([]);
    });

    it('keeps changes and their op ids when the connection drops, and resends them the same', async () => {
        const api = vi.fn();
        const engine = await makeEngine(api);
        const operation = await engine.enqueue('crm.note', 'create', { body: 'x' });

        api.mockRejectedValueOnce({ status: 0, code: 'network' });
        expect((await engine.sync()).status).toBe('offline');
        expect(await engine.pending()).toBe(1);

        api.mockResolvedValueOnce(syncAnswer({ [operation.op_id]: { status: 'applied', record_id: 'N1', version: 1 } }));
        expect((await engine.sync()).status).toBe('ok');
        expect(api.mock.calls[1][1].body.operations).toEqual([operation]);
        expect(await engine.pending()).toBe(0);
    });

    it('sends in batches of the rule size', async () => {
        const api = vi.fn();
        const engine = await makeEngine(api, { lease: leaseData({ rules: { 'offline_mode.sync_batch_max': 1 } }) });
        const ops = [await engine.enqueue('crm.note', 'create', {}), await engine.enqueue('crm.note', 'create', {})];

        for (const op of ops) {
            api.mockResolvedValueOnce(syncAnswer({ [op.op_id]: { status: 'applied' } }, { rules: { 'offline_mode.sync_batch_max': 1 } }));
        }

        await engine.sync();

        expect(api).toHaveBeenCalledTimes(2);
        expect(api.mock.calls.map((call) => call[1].body.operations.length)).toEqual([1, 1]);
    });

    it('renews the lease once when the server asks, then syncs', async () => {
        const api = vi.fn();
        const engine = await makeEngine(api);

        api.mockRejectedValueOnce({ status: 401, data: { renew_lease: true } });
        api.mockResolvedValueOnce({ data: leaseData({ lease: 'fresh', lease_id: 'L3' }) });
        api.mockResolvedValueOnce(syncAnswer({}, { lease: 'fresh', lease_id: 'L3' }));

        expect((await engine.sync()).status).toBe('ok');
        expect(api.mock.calls[1][0]).toBe('/api/offline/devices/D1/lease');
        expect(api.mock.calls[2][1].body.lease).toBe('fresh');
    });

    it('reports an ended lease when it cannot be renewed', async () => {
        const api = vi.fn().mockRejectedValue({ status: 401, data: {} });
        const engine = await makeEngine(api, { lease: leaseData({ expires_at: '2026-09-30T00:00:00Z' }) });

        expect((await engine.sync()).status).toBe('lease_expired');
    });

    it('wipes everything on a wipe order and tells the server', async () => {
        const api = vi.fn();
        const engine = await makeEngine(api);
        await engine.enqueue('crm.note', 'create', { body: 'x' });
        await engine.store.putRecord('crm.note', { id: 'N1' });

        api.mockRejectedValueOnce({ status: 410, data: { wipe: true, results: {} } });
        api.mockResolvedValueOnce({});

        expect((await engine.sync()).status).toBe('wiped');
        expect(api.mock.calls[1][0]).toBe('/api/offline/devices/D1/wiped');
        expect((await factory.databases()).map((db) => db.name)).not.toContain(engine.store.name);
        expect(JSON.parse(localStorage.getItem('os.offline.stores'))).toEqual([]);
    });

    it('does nothing on a browser that is not set up', async () => {
        const api = vi.fn();
        const store = await new OfflineStore('U1', 'O1', factory).open();

        expect((await new OfflineEngine(store, api).sync()).status).toBe('not_enabled');
        expect(api).not.toHaveBeenCalled();
    });

    it('sets the browser up and keeps the device and lease', async () => {
        const api = vi.fn().mockResolvedValue({ data: { device: { id: 'D9', name: 'Desk' }, ...leaseData() } });
        const store = await new OfflineStore('U1', 'O1', factory).open();
        const engine = new OfflineEngine(store, api, { now: () => NOW });

        await engine.enable('Desk', 'Windows');

        expect(api).toHaveBeenCalledWith('/api/offline/devices', { method: 'POST', body: { name: 'Desk', platform: 'Windows' } });
        expect(await engine.device()).toEqual({ id: 'D9', name: 'Desk' });
        expect(engine.leaseValid(await engine.lease())).toBe(true);
    });
});
