import { api } from '@/lib/http';

/**
 * Calls to the patient billing API at a unit. The server checks every access
 * again (permission at the unit, modules on, the bill's branch); these only
 * shape the requests. Money calls always carry an op id.
 */
export function billingApi(organizationId) {
    const base = `/api/organizations/${organizationId}/patient-billing`;

    return {
        setup: () => api(`${base}/setup`),
        patients: (q) => api(`${base}/patients`, { query: { q } }),
        bills: (query = {}) => api(`${base}/bills`, { query }),
        bill: (id) => api(`${base}/bills/${id}`),
        receipt: (id) => api(`${base}/bills/${id}/receipt`),
        open: (body) => api(`${base}/bills`, { method: 'POST', body }),
        addLine: (id, body) => api(`${base}/bills/${id}/lines`, { method: 'POST', body }),
        voidLine: (id, lineId) => api(`${base}/bills/${id}/lines/${lineId}`, { method: 'DELETE' }),
        setPanel: (id, body) => api(`${base}/bills/${id}/panel`, { method: 'PUT', body }),
        discount: (id, body) => api(`${base}/bills/${id}/discount`, { method: 'POST', body }),
        finalize: (id, body) => api(`${base}/bills/${id}/finalize`, { method: 'POST', body }),
        voidBill: (id, body) => api(`${base}/bills/${id}/void`, { method: 'POST', body }),
        pay: (body) => api(`${base}/payments`, { method: 'POST', body }),
        refund: (id, body) => api(`${base}/bills/${id}/refund`, { method: 'POST', body }),
        approvals: (query = {}) => api(`${base}/approvals`, { query }),
        decide: (id, body) => api(`${base}/discount-requests/${id}/decide`, { method: 'POST', body }),
        cashDays: () => api(`${base}/cash-days`),
        openDay: (body) => api(`${base}/cash-days/open`, { method: 'POST', body }),
        closeDay: (id, body) => api(`${base}/cash-days/${id}/close`, { method: 'POST', body }),
        reviewDay: (id, body) => api(`${base}/cash-days/${id}/review`, { method: 'POST', body }),
    };
}

/** The portal: a patient's own final bills. */
export const portalBills = () => api('/api/portal/patient-billing/bills');

/** Money as formatMoney wants it. */
export const money = (amount, currency) => ({ amount, currency });
