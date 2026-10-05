import { api } from '@/lib/http';

/**
 * Calls to the Inventory API at a unit. The server checks every access again
 * (permission at the company or the warehouse's unit, module on); these only
 * shape the requests.
 */
export function inventoryApi(organizationId) {
    const base = `/api/organizations/${organizationId}/inventory`;

    return {
        list: (kind, query = {}) => api(`${base}/${kind}`, { query }),
        create: (kind, body) => api(`${base}/${kind}`, { method: 'POST', body }),
        update: (kind, id, body) => api(`${base}/${kind}/${id}`, { method: 'PATCH', body }),
        item: (id) => api(`${base}/items/${id}`),
        lookup: (code) => api(`${base}/lookup`, { query: { code } }),
        stock: (query = {}) => api(`${base}/stock`, { query }),
        moves: (query = {}) => api(`${base}/moves`, { query }),
        expiring: () => api(`${base}/expiring`),

        documents: (query = {}) => api(`${base}/documents`, { query }),
        document: (id) => api(`${base}/documents/${id}`),
        createDocument: (body) => api(`${base}/documents`, { method: 'POST', body }),
        updateDocument: (id, body) => api(`${base}/documents/${id}`, { method: 'PATCH', body }),
        deleteDocument: (id, version) => api(`${base}/documents/${id}`, { method: 'DELETE', body: { base_version: version } }),
        documentStep: (id, step, body) => api(`${base}/documents/${id}/${step}`, { method: 'POST', body }),

        counts: () => api(`${base}/counts`),
        count: (id) => api(`${base}/counts/${id}`),
        openCount: (body) => api(`${base}/counts`, { method: 'POST', body }),
        recordCount: (id, body) => api(`${base}/counts/${id}`, { method: 'PATCH', body }),
        countStep: (id, step, body) => api(`${base}/counts/${id}/${step}`, { method: 'POST', body }),
    };
}
