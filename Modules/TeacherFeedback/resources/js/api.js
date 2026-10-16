import { api } from '@/lib/http';

/**
 * Calls to the teacher feedback API at a unit. The server checks every
 * access again (permission at the unit, the module on, the round's campus);
 * these only shape the requests.
 */
export function feedbackApi(organizationId) {
    const base = `/api/organizations/${organizationId}/teacher-feedback`;

    return {
        setup: () => api(`${base}/setup`),
        rounds: () => api(`${base}/rounds`),
        round: (id) => api(`${base}/rounds/${id}`),
        create: (body) => api(`${base}/rounds`, { method: 'POST', body }),
        update: (id, body) => api(`${base}/rounds/${id}`, { method: 'PATCH', body }),
        open: (id, version) => api(`${base}/rounds/${id}/open`, { method: 'POST', body: { base_version: version } }),
        close: (id, version) => api(`${base}/rounds/${id}/close`, { method: 'POST', body: { base_version: version } }),
        decide: (id, commentId, decision) => api(`${base}/rounds/${id}/comments/${commentId}/${decision}`, { method: 'POST', body: {} }),
        mine: () => api(`${base}/mine`),
    };
}

/** The portal: rate the teachers of the student (or, with a record, of a child). */
export const portalFeedbackApi = {
    show: (record) => api('/api/portal/teacher-feedback', { query: record ? { record } : {} }),
    rate: (body) => api('/api/portal/teacher-feedback', { method: 'POST', body }),
};
