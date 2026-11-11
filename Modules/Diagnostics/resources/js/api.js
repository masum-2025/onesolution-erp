import { api } from '@/lib/http';

/**
 * Calls to the diagnostics API at a unit. The server checks every access
 * again (permission at the unit, modules on, the order's branch); these only
 * shape the requests.
 */
export function labApi(organizationId) {
    const base = `/api/organizations/${organizationId}/diagnostics`;

    return {
        setup: () => api(`${base}/setup`),
        patients: (q) => api(`${base}/patients`, { query: { q } }),
        orders: (query = {}) => api(`${base}/orders`, { query }),
        order: (id) => api(`${base}/orders/${id}`),
        report: (id) => api(`${base}/orders/${id}/report`),
        place: (body) => api(`${base}/orders`, { method: 'POST', body }),
        collect: (id) => api(`${base}/orders/${id}/collect`, { method: 'POST' }),
        rejectSample: (id, body) => api(`${base}/samples/${id}/reject`, { method: 'POST', body }),
        bySample: (barcode) => api(`${base}/samples/${encodeURIComponent(barcode)}`),
        saveResults: (id, body) => api(`${base}/order-tests/${id}/results`, { method: 'PUT', body }),
        verify: (id, body = {}) => api(`${base}/order-tests/${id}/verify`, { method: 'POST', body }),
        sendBack: (id, body) => api(`${base}/order-tests/${id}/send-back`, { method: 'POST', body }),
        deliver: (id, body) => api(`${base}/orders/${id}/deliver`, { method: 'POST', body }),
        parameters: (testId) => api(`${base}/tests/${testId}/parameters`),
        saveParameters: (testId, body) => api(`${base}/tests/${testId}/parameters`, { method: 'PUT', body }),
        link: (id, body = {}) => api(`${base}/orders/${id}/link`, { method: 'POST', body }),
        commissions: (query = {}) => api(`${base}/commissions`, { query }),
        account: (referrerId, query = {}) => api(`${base}/referrers/${referrerId}/commissions`, { query }),
        pay: (referrerId, body) => api(`${base}/referrers/${referrerId}/payouts`, { method: 'POST', body }),
        reverse: (commissionId, body) => api(`${base}/commissions/${commissionId}/reverse`, { method: 'POST', body }),
    };
}

/** The portal: a patient's own verified reports. */
export const portalReports = () => api('/api/portal/diagnostics/reports');

/** A report opened from its link (no sign-in). */
export const publicReport = (organization, token) => api(`/api/public/diagnostics/reports/${encodeURIComponent(organization)}/${encodeURIComponent(token)}`);

/** The normal range as one short text: "13 – 17", "< 200", "Normal". */
export function rangeText(range) {
    if (!range) return '';
    if (range.normal_text) return range.normal_text;
    if (range.low !== null && range.high !== null) return `${range.low} – ${range.high}`;
    if (range.low !== null) return `≥ ${range.low}`;
    if (range.high !== null) return `≤ ${range.high}`;
    return '';
}

/** Badge tones for result flags (the word is always shown too). */
export const flagTones = { low: 'warn', high: 'warn', critical_low: 'bad', critical_high: 'bad', abnormal: 'warn' };
