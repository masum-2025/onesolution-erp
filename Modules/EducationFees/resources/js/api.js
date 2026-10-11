import { api } from '@/lib/http';

/**
 * Calls to the fees API at a unit. The server checks every access again
 * (permission at the unit, the module on, records of the unit and below);
 * these only shape the requests. Money is always integer minor units.
 */
export function feesApi(organizationId) {
    const base = `/api/organizations/${organizationId}/fees`;

    return {
        setup: () => api(`${base}/setup`),
        findStudents: (q) => api(`${base}/students`, { query: { q } }),
        student: (id) => api(`${base}/students/${id}`),

        bills: (query) => api(`${base}/bills`, { query }),
        bill: (id) => api(`${base}/bills/${id}`),
        cancelBill: (id, body) => api(`${base}/bills/${id}/cancel`, { method: 'POST', body }),

        receipts: (query) => api(`${base}/receipts`, { query }),
        receipt: (id) => api(`${base}/receipts/${id}`),
        collect: (body) => api(`${base}/receipts`, { method: 'POST', body }),
        requestVoid: (id, reason) => api(`${base}/receipts/${id}/void`, { method: 'POST', body: { reason } }),
        voids: (status) => api(`${base}/voids`, { query: status ? { status } : {} }),
        decideVoid: (id, approve, body) => api(`${base}/voids/${id}/${approve ? 'approve' : 'reject'}`, { method: 'POST', body }),

        refunds: (query) => api(`${base}/refunds`, { query }),
        requestRefund: (body) => api(`${base}/refunds`, { method: 'POST', body }),
        decideRefund: (id, approve, body) => api(`${base}/refunds/${id}/${approve ? 'approve' : 'reject'}`, { method: 'POST', body }),

        concessions: (query) => api(`${base}/concessions`, { query }),
        giveConcession: (body) => api(`${base}/concessions`, { method: 'POST', body }),
        decideConcession: (id, approve, body) => api(`${base}/concessions/${id}/${approve ? 'approve' : 'reject'}`, { method: 'POST', body }),
        endConcession: (id, body) => api(`${base}/concessions/${id}/end`, { method: 'POST', body }),

        takings: (query) => api(`${base}/takings`, { query }),

        runs: (query) => api(`${base}/runs`, { query }),
        run: (id) => api(`${base}/runs/${id}`),
        createRun: (body) => api(`${base}/runs`, { method: 'POST', body }),
        refreshRun: (id, version) => api(`${base}/runs/${id}/refresh`, { method: 'POST', body: { base_version: version } }),
        finalizeRun: (id, version) => api(`${base}/runs/${id}/finalize`, { method: 'POST', body: { base_version: version } }),
        cancelRun: (id, version) => api(`${base}/runs/${id}/cancel`, { method: 'POST', body: { base_version: version } }),
        waiveFine: (id, body) => api(`${base}/bills/${id}/waive-fine`, { method: 'POST', body }),
        dues: (query) => api(`${base}/dues`, { query }),
        postings: (status) => api(`${base}/postings`, { query: status ? { status } : {} }),
        retryPostings: () => api(`${base}/postings/retry`, { method: 'POST', body: {} }),

        createHead: (body) => api(`${base}/heads`, { method: 'POST', body }),
        updateHead: (id, body) => api(`${base}/heads/${id}`, { method: 'PATCH', body }),
        structures: (query) => api(`${base}/structures`, { query }),
        createStructure: (body) => api(`${base}/structures`, { method: 'POST', body }),
        updateStructure: (id, body) => api(`${base}/structures/${id}`, { method: 'PATCH', body }),
        structureStatus: (id, status, version) => api(`${base}/structures/${id}/${status === 'active' ? 'activate' : 'archive'}`, { method: 'POST', body: { base_version: version } }),
    };
}

/**
 * A family's fees in the client's portal (B2B2C), for the student of a
 * portal record. Paying online starts at the gateway; the amount is the bill's.
 */
export const portalFeesApi = {
    show: (record) => api('/api/portal/fees', { query: { record } }),
    bill: (record, id) => api(`/api/portal/fees/bills/${id}`, { query: { record } }),
    receipt: (record, id) => api(`/api/portal/fees/receipts/${id}`, { query: { record } }),
    pay: (record, id, opId) => api(`/api/portal/fees/bills/${id}/pay`, { method: 'POST', query: { record }, body: { op_id: opId } }),
};
