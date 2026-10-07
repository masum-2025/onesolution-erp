import { api } from '@/lib/http';

/**
 * Calls to the CRM API at a unit. The server checks every access again
 * (permission at the unit, CRM on, records of the unit and below); these
 * only shape the requests.
 */
export function crmApi(organizationId) {
    const base = `/api/organizations/${organizationId}/crm`;

    return {
        setup: () => api(`${base}/setup`),

        contacts: (query = {}) => api(`${base}/contacts`, { query }),
        contact: (id) => api(`${base}/contacts/${id}`),
        createContact: (body) => api(`${base}/contacts`, { method: 'POST', body }),
        updateContact: (id, body) => api(`${base}/contacts/${id}`, { method: 'PATCH', body }),
        anonymize: (id, body) => api(`${base}/contacts/${id}/anonymize`, { method: 'POST', body }),
        makeCustomer: (id) => api(`${base}/contacts/${id}/customer`, { method: 'POST', body: {} }),
        importContacts: (body) => api(`${base}/contacts/import`, { method: 'POST', body }),
        exportUrl: (query = {}) => `${base}/contacts/export?${new URLSearchParams(Object.entries(query).filter(([, value]) => value !== '' && value !== null && value !== undefined && value !== false))}`,

        deals: (query = {}) => api(`${base}/deals`, { query }),
        createDeal: (body) => api(`${base}/deals`, { method: 'POST', body }),
        updateDeal: (id, body) => api(`${base}/deals/${id}`, { method: 'PATCH', body }),
        moveDeal: (id, body) => api(`${base}/deals/${id}/move`, { method: 'POST', body }),

        activities: (query = {}) => api(`${base}/activities`, { query }),
        createActivity: (body) => api(`${base}/activities`, { method: 'POST', body }),
        updateActivity: (id, body) => api(`${base}/activities/${id}`, { method: 'PATCH', body }),

        quotes: (query = {}) => api(`${base}/quotes`, { query }),
        quote: (id) => api(`${base}/quotes/${id}`),
        createQuote: (body) => api(`${base}/quotes`, { method: 'POST', body }),
        updateQuote: (id, body) => api(`${base}/quotes/${id}`, { method: 'PATCH', body }),
        deleteQuote: (id, version) => api(`${base}/quotes/${id}`, { method: 'DELETE', body: { base_version: version } }),
        quoteStep: (id, step, body) => api(`${base}/quotes/${id}/${step}`, { method: 'POST', body }),

        fields: () => api(`${base}/fields`),
        createField: (body) => api(`${base}/fields`, { method: 'POST', body }),
        updateField: (id, body) => api(`${base}/fields/${id}`, { method: 'PATCH', body }),
        createPipeline: (body) => api(`${base}/pipelines`, { method: 'POST', body }),
        updatePipeline: (id, body) => api(`${base}/pipelines/${id}`, { method: 'PATCH', body }),
        createStage: (pipeline, body) => api(`${base}/pipelines/${pipeline}/stages`, { method: 'POST', body }),
        updateStage: (pipeline, id, body) => api(`${base}/pipelines/${pipeline}/stages/${id}`, { method: 'PATCH', body }),
    };
}
