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
    };
}
