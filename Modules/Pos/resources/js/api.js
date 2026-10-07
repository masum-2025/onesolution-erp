import { api } from '@/lib/http';

/**
 * Calls to the point of sale API at a unit. The server checks every access
 * again (permission at the counter's branch, modules on); these only shape
 * the requests.
 */
export function posApi(organizationId) {
    const base = `/api/organizations/${organizationId}/pos`;

    return {
        registers: () => api(`${base}/registers`),
        createRegister: (body) => api(`${base}/registers`, { method: 'POST', body }),
        updateRegister: (id, body) => api(`${base}/registers/${id}`, { method: 'PATCH', body }),
        catalogue: (registerId) => api(`${base}/registers/${registerId}/catalogue`),
        openShift: (registerId, float) => api(`${base}/registers/${registerId}/open`, { method: 'POST', body: { opening_float_minor: float } }),

        shifts: (query = {}) => api(`${base}/sessions`, { query }),
        shift: (id) => api(`${base}/sessions/${id}`),
        closeShift: (id, body) => api(`${base}/sessions/${id}/close`, { method: 'POST', body }),
        reviewShift: (id, body) => api(`${base}/sessions/${id}/review`, { method: 'POST', body }),

        sales: (query = {}) => api(`${base}/sales`, { query }),
        customer: (phone) => api(`${base}/customers`, { query: { phone } }),
        sale: (id) => api(`${base}/sales/${id}`),
        report: (query = {}) => api(`${base}/reports`, { query }),
        sell: (body) => api(`${base}/sales`, { method: 'POST', body }),
        giveBack: (id, body) => api(`${base}/sales/${id}/return`, { method: 'POST', body }),
    };
}
