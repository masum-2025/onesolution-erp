import { api } from '@/lib/http';

/**
 * Calls to the outpatient API at a unit. The server checks every access again
 * (permission at the unit, modules on, the doctor's own patients); these only
 * shape the requests.
 */
export function opdApi(organizationId) {
    const base = `/api/organizations/${organizationId}/outpatient`;

    return {
        setup: () => api(`${base}/setup`),
        doctors: (query = {}) => api(`${base}/doctors`, { query: { per_page: 100, ...query } }),
        schedule: (doctorId) => api(`${base}/doctors/${doctorId}/schedule`),
        saveSchedule: (doctorId, body) => api(`${base}/doctors/${doctorId}/schedule`, { method: 'PUT', body }),
        serials: (query = {}) => api(`${base}/serials`, { query }),
        serial: (id) => api(`${base}/serials/${id}`),
        sessions: (query = {}) => api(`${base}/sessions`, { query }),
        quote: (query) => api(`${base}/quote`, { query }),
        book: (body) => api(`${base}/serials`, { method: 'POST', body }),
        move: (id, body) => api(`${base}/serials/${id}/status`, { method: 'POST', body }),
        patients: (q) => api(`${base}/patients`, { query: { q } }),
        queue: (chamberId) => api(`${base}/queue/${chamberId}`),
        mine: (query = {}) => api(`${base}/my-patients`, { query }),
        consultation: (serialId) => api(`${base}/serials/${serialId}/consultation`),
        saveConsultation: (serialId, body) => api(`${base}/serials/${serialId}/consultation`, { method: 'PUT', body }),
        finish: (serialId, body) => api(`${base}/serials/${serialId}/consultation/finish`, { method: 'POST', body }),
        amend: (serialId, body) => api(`${base}/serials/${serialId}/consultation/amend`, { method: 'POST', body }),
        sendTests: (serialId) => api(`${base}/serials/${serialId}/consultation/send-tests`, { method: 'POST' }),
        prescription: (serialId) => api(`${base}/serials/${serialId}/prescription`),
        medicines: (q) => api(`${base}/medicines`, { query: { q } }),
        templates: () => api(`${base}/templates`),
        saveTemplate: (id, body) => (id ? api(`${base}/templates/${id}`, { method: 'PATCH', body }) : api(`${base}/templates`, { method: 'POST', body })),
        removeTemplate: (id, version) => api(`${base}/templates/${id}`, { method: 'DELETE', query: { base_version: version } }),
    };
}

/** The portal: a patient's own prescriptions. */
export const portalPrescriptions = () => api('/api/portal/outpatient/prescriptions');

/** Badge tone for each serial status. */
export const statusTones = { booked: 'neutral', arrived: 'info', in: 'warn', done: 'ok', absent: 'bad', cancelled: 'neutral' };
