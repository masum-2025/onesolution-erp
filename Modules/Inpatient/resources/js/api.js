import { api } from '@/lib/http';

/**
 * Calls to the inpatient API at a unit. The server checks every access again
 * (permission at the unit, modules on, a doctor's own patients); these only
 * shape the requests.
 */
export function ipdApi(organizationId) {
    const base = `/api/organizations/${organizationId}/inpatient`;

    return {
        setup: () => api(`${base}/setup`),
        wards: () => api(`${base}/wards`, { query: { per_page: 100 } }),
        board: () => api(`${base}/beds/board`),
        beds: () => api(`${base}/beds`),
        saveBed: (id, body) => (id ? api(`${base}/beds/${id}`, { method: 'PATCH', body }) : api(`${base}/beds`, { method: 'POST', body })),
        removeBed: (id, version) => api(`${base}/beds/${id}`, { method: 'DELETE', query: { base_version: version } }),
        bedStatus: (id, body) => api(`${base}/beds/${id}/status`, { method: 'POST', body }),
        admissions: (query = {}) => api(`${base}/admissions`, { query }),
        admission: (id) => api(`${base}/admissions/${id}`),
        options: (patient) => api(`${base}/admissions/options`, { query: patient ? { patient } : {} }),
        patients: (q) => api(`${base}/patients`, { query: { q } }),
        admit: (body) => api(`${base}/admissions`, { method: 'POST', body }),
        move: (id, body) => api(`${base}/admissions/${id}/move`, { method: 'POST', body }),
        cancel: (id, body) => api(`${base}/admissions/${id}/cancel`, { method: 'POST', body }),
    };
}

/**
 * How each bed state looks on the board: colour plus a word and an icon name, so the state never
 * rests on colour alone (colour-blind safe).
 */
export const bedLooks = {
    free: { tone: 'ok', ring: 'ring-ok/40 bg-ok-soft', icon: 'check' },
    occupied: { tone: 'bad', ring: 'ring-bad/40 bg-bad-soft', icon: 'user' },
    cleaning: { tone: 'warn', ring: 'ring-warn/40 bg-warn-soft', icon: 'sparkles' },
    blocked: { tone: 'neutral', ring: 'ring-line bg-subtle', icon: 'ban' },
};
