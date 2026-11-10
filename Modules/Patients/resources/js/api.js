import { api } from '@/lib/http';

/**
 * Calls to the patients API at a unit. The server checks every access again
 * (permission, module on, which patients the person may see); these only
 * shape the requests.
 */
export function patientsApi(organizationId) {
    const base = `/api/organizations/${organizationId}/patients`;

    return {
        setup: () => api(`${base}/setup`),
        search: (query = {}) => api(`${base}/patients`, { query }),
        patient: (id) => api(`${base}/patients/${id}`),
        register: (body) => api(`${base}/patients`, { method: 'POST', body }),
        update: (id, body) => api(`${base}/patients/${id}`, { method: 'PATCH', body }),
        timeline: (id) => api(`${base}/patients/${id}/timeline`),
        card: (id) => api(`${base}/patients/${id}/card`),
        views: (id, query = {}) => api(`${base}/patients/${id}/views`, { query }),
        askMerge: (id, body) => api(`${base}/patients/${id}/merge`, { method: 'POST', body }),
        merges: (query = {}) => api(`${base}/merges`, { query }),
        decideMerge: (id, body) => api(`${base}/merges/${id}/decide`, { method: 'POST', body }),
    };
}

/** The portal: a patient's own record and history. */
export const portalPatients = () => api('/api/portal/patients');
