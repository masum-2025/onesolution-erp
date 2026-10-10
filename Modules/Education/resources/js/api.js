import { api } from '@/lib/http';

/**
 * Calls to the Education API at a unit. The server checks every access again
 * (permission at the unit, Education on, records of the unit and below,
 * a teacher's own sections); these only shape the requests.
 */
export function educationApi(organizationId) {
    const base = `/api/organizations/${organizationId}/education`;

    return {
        setup: () => api(`${base}/setup`),
        overview: (query = {}) => api(`${base}/overview`, { query }),
        applyPreset: (key) => api(`${base}/presets/${key}/apply`, { method: 'POST', body: {} }),

        list: (kind, query = {}) => api(`${base}/structure/${kind}`, { query }),
        create: (kind, body) => api(`${base}/structure/${kind}`, { method: 'POST', body }),
        update: (kind, id, body) => api(`${base}/structure/${kind}/${id}`, { method: 'PATCH', body }),
        remove: (kind, id) => api(`${base}/structure/${kind}/${id}`, { method: 'DELETE' }),

        fields: (query = {}) => api(`${base}/fields`, { query }),
        createField: (body) => api(`${base}/fields`, { method: 'POST', body }),
        updateField: (id, body) => api(`${base}/fields/${id}`, { method: 'PATCH', body }),

        students: (query = {}) => api(`${base}/students`, { query }),
        student: (id) => api(`${base}/students/${id}`),
        createStudent: (body) => api(`${base}/students`, { method: 'POST', body }),
        updateStudent: (id, body) => api(`${base}/students/${id}`, { method: 'PATCH', body }),
        leave: (id, body) => api(`${base}/students/${id}/leave`, { method: 'POST', body }),
        photo: (id, file) => {
            const form = new FormData();
            form.append('photo', file);
            return api(`${base}/students/${id}/photo`, { method: 'POST', body: form });
        },
        linkGuardian: (id, body) => api(`${base}/students/${id}/guardians`, { method: 'POST', body }),
        updateGuardian: (id, guardian, body) => api(`${base}/students/${id}/guardians/${guardian}`, { method: 'PATCH', body }),
        unlinkGuardian: (id, guardian) => api(`${base}/students/${id}/guardians/${guardian}`, { method: 'DELETE' }),

        roster: (section) => api(`${base}/sections/${section}/students`),
        place: (enrollment, body) => api(`${base}/enrollments/${enrollment}/place`, { method: 'POST', body }),
        rolls: (section, body = {}) => api(`${base}/sections/${section}/rolls`, { method: 'POST', body }),
    };
}
