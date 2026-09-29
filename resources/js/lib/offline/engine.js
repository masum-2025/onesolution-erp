import { newOpId } from '../billing';

/*
 * Offline work for one person in one organization (Phase 7-2), on top of
 * the encrypted store. The server decides everything again when a change
 * arrives; the device refuses early what the server would refuse anyway:
 *
 *  - no lease, or an expired one: no new offline changes (go online first);
 *  - a kind the lease does not include, or a permission the person lacks;
 *  - money: only new records, and only when offline payments are allowed.
 *
 * sync() sends waiting changes in batches, keeps what the server applied
 * out of the queue, keeps conflicts and rejections for the person to see,
 * applies what changed on the server, and stores the new lease and cursor.
 * A wipe order (410) or signing out clears everything.
 */
export class OfflineError extends Error {
    constructor(code) {
        super(code);
        this.name = 'OfflineError';
        this.code = code;
    }
}

const MAX_ROUNDS = 20;

export class OfflineEngine {
    /**
     * @param {import('./store').OfflineStore} store
     * @param {(path: string, options?: object) => Promise<any>} api  The app's JSON client.
     */
    constructor(store, api, { now = () => Date.now() } = {}) {
        this.store = store;
        this.api = api;
        this.now = now;
    }

    /** Sets this browser up for offline work (online, in the organization). */
    async enable(name, platform = null) {
        const response = await this.api('/api/offline/devices', { method: 'POST', body: { name, platform } });
        await this.store.setMeta('device', response.data.device);
        await this.saveLease(response.data);
        await globalThis.navigator?.storage?.persist?.();

        return response.data;
    }

    async device() {
        return this.store.getMeta('device');
    }

    async lease() {
        return this.store.getMeta('lease');
    }

    leaseValid(lease) {
        return lease !== null && Date.parse(lease.expires_at) > this.now();
    }

    async renewLease() {
        const device = await this.device();
        const response = await this.api(`/api/offline/devices/${device.id}/lease`, { method: 'POST' });
        await this.saveLease(response.data);

        return response.data;
    }

    /** The reason a change cannot be made offline now, or null. */
    async refusal(kind, action) {
        const lease = await this.lease();
        if (!this.leaseValid(lease)) return 'lease_expired';
        if (!lease.kinds.includes(kind)) return 'kind_unavailable';

        const info = lease.kind_info?.[kind];
        if (info?.money && action !== 'create') return 'append_only';
        if (info?.money && !lease.rules['offline_mode.allow_offline_payments']) return 'offline_payments_off';
        if (info && !lease.permissions.includes(info.permissions[action])) return 'forbidden';

        return null;
    }

    /** Records a change made offline; it waits in the queue until the next sync. */
    async enqueue(kind, action, data = {}, recordId = null, baseVersion = null) {
        const refusal = await this.refusal(kind, action);
        if (refusal) throw new OfflineError(refusal);

        const lease = await this.lease();
        const operation = {
            op_id: newOpId(),
            kind,
            action,
            ...(recordId ? { record_id: recordId } : {}),
            ...(baseVersion !== null ? { base_version: baseVersion } : {}),
            data,
            made_at: new Date(this.now()).toISOString(),
            lease_id: lease.lease_id,
        };
        await this.store.enqueue(operation);

        return operation;
    }

    pending() {
        return this.store.pending();
    }

    outcomes() {
        return this.store.outcomes();
    }

    dismiss(opId) {
        return this.store.clearOutcome(opId);
    }

    /**
     * Sends waiting changes and catches up. Returns { status, sent, applied, held }
     * where status is ok | offline | wiped | lease_expired.
     */
    async sync() {
        const summary = { status: 'ok', sent: 0, applied: 0, held: 0 };
        const device = await this.device();
        if (!device) return { ...summary, status: 'not_enabled' };

        let lease = await this.lease();
        if (!this.leaseValid(lease)) {
            try {
                lease = await this.renewLease();
            } catch (error) {
                return this.failure(error, summary);
            }
        }

        for (let round = 0; round < MAX_ROUNDS; round++) {
            const batchSize = Number((await this.lease()).rules?.['offline_mode.sync_batch_max'] ?? 200);
            const waiting = (await this.store.queue()).slice(0, batchSize);
            let response;

            try {
                response = await this.api('/api/sync', {
                    method: 'POST',
                    body: {
                        device_id: device.id,
                        lease: (await this.lease()).lease,
                        cursor: await this.store.getMeta('cursor'),
                        operations: waiting.map((row) => row.operation),
                    },
                });
            } catch (error) {
                if (error?.status === 401 && error?.data?.renew_lease && round === 0) {
                    try {
                        await this.renewLease();
                        continue;
                    } catch (renewError) {
                        return this.failure(renewError, summary);
                    }
                }

                return this.failure(error, summary);
            }

            summary.sent += waiting.length;
            for (const row of waiting) {
                const result = response.results[row.operation.op_id];
                if (!result) continue;
                if (result.status === 'applied') summary.applied++;
                else await this.store.setOutcome(row.operation.op_id, { ...result, operation: row.operation });
                await this.store.dequeue(row.seq);
            }

            await this.applyChanges(response.changes ?? {});
            await this.store.setMeta('cursor', response.cursor);
            await this.saveLease(response);

            if ((await this.store.pending()) === 0) break;
        }

        return summary;
    }

    async applyChanges(changes) {
        for (const [kind, change] of Object.entries(changes)) {
            for (const record of change.records ?? []) await this.store.putRecord(kind, record);
            for (const id of change.deleted ?? []) await this.store.removeRecord(kind, id);
        }
    }

    /** Clears this organization's offline data and tells the server it is done. */
    async wipe() {
        const device = await this.device().catch(() => null);
        await this.store.wipe();
        if (device) await this.api(`/api/offline/devices/${device.id}/wiped`, { method: 'POST' }).catch(() => null);
    }

    async failure(error, summary) {
        if (error?.status === 410 && error?.data?.wipe) {
            await this.wipe();
            return { ...summary, status: 'wiped', held: Object.keys(error.data.results ?? {}).length };
        }
        if (error?.status === 401) return { ...summary, status: 'lease_expired' };
        if (error?.status === 0 || error?.code === 'network') return { ...summary, status: 'offline' };

        throw error;
    }

    async saveLease(data) {
        await this.store.setMeta('lease', {
            lease: data.lease,
            lease_id: data.lease_id,
            expires_at: data.expires_at,
            kinds: data.kinds ?? [],
            kind_info: data.kind_info ?? {},
            permissions: data.permissions ?? [],
            rules: data.rules ?? {},
        });
    }
}
