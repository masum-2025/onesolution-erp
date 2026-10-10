import { api } from '@/lib/http';

/**
 * Calls to the course registration API at a unit. The server checks every
 * access again (permission at the unit, the module on, records of the unit
 * and below, a teacher's own offerings); these only shape the requests.
 */
export function registrationApi(organizationId) {
    const base = `/api/organizations/${organizationId}/course-registration`;

    return {
        setup: (sessionId) => api(`${base}/setup`, { query: sessionId ? { session_id: sessionId } : {} }),
        saveWindow: (sessionId, body) => api(`${base}/sessions/${sessionId}/window`, { method: 'PUT', body }),
        findStudents: (q) => api(`${base}/students`, { query: { q } }),
        sections: (sessionId) => api(`${base}/sections`, { query: { session_id: sessionId } }),

        offerings: (query) => api(`${base}/offerings`, { query }),
        createOffering: (body) => api(`${base}/offerings`, { method: 'POST', body }),
        updateOffering: (id, body) => api(`${base}/offerings/${id}`, { method: 'PATCH', body }),
        fromCurriculum: (body) => api(`${base}/offerings/from-curriculum`, { method: 'POST', body }),
        roster: (id) => api(`${base}/offerings/${id}/students`),

        registrations: (query) => api(`${base}/registrations`, { query }),
        registration: (id) => api(`${base}/registrations/${id}`),
        open: (body) => api(`${base}/registrations`, { method: 'POST', body }),
        add: (id, body) => api(`${base}/registrations/${id}/items`, { method: 'POST', body }),
        drop: (itemId, body = {}) => api(`${base}/items/${itemId}/drop`, { method: 'POST', body }),
        outcome: (itemId, outcome) => api(`${base}/items/${itemId}/outcome`, { method: 'POST', body: { outcome } }),
        submit: (id) => api(`${base}/registrations/${id}/submit`, { method: 'POST', body: {} }),
        approve: (id, body) => api(`${base}/registrations/${id}/approve`, { method: 'POST', body }),
        sendBack: (id, body) => api(`${base}/registrations/${id}/send-back`, { method: 'POST', body }),
        registerSection: (sectionId) => api(`${base}/sections/${sectionId}/register`, { method: 'POST', body: {} }),
    };
}
