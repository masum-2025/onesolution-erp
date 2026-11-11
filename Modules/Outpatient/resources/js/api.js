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
    };
}

/** Badge tone for each serial status. */
export const statusTones = { booked: 'neutral', arrived: 'info', in: 'warn', done: 'ok', absent: 'bad', cancelled: 'neutral' };
