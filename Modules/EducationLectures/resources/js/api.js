import { api } from '@/lib/http';

/**
 * Calls to the class lectures API at a unit. The server checks every access
 * again (permission at the unit, the module on, the subject being one the
 * person teaches, the section's campus); these only shape the requests.
 */
export function lecturesApi(organizationId) {
    const base = `/api/organizations/${organizationId}/lectures`;

    return {
        setup: () => api(`${base}/setup`),
        list: (query) => api(base, { query }),
        get: (id) => api(`${base}/${id}`),
        create: (body) => api(base, { method: 'POST', body }),
        update: (id, body) => api(`${base}/${id}`, { method: 'PATCH', body }),
        remove: (id) => api(`${base}/${id}`, { method: 'DELETE' }),
        publish: (id, version) => api(`${base}/${id}/publish`, { method: 'POST', body: { base_version: version } }),
        approve: (id, version) => api(`${base}/${id}/approve`, { method: 'POST', body: { base_version: version } }),
        withdraw: (id, version, reason) => api(`${base}/${id}/withdraw`, { method: 'POST', body: { base_version: version, reason } }),
        addFile: (id, file) => {
            const form = new FormData();
            form.append('file', file);
            return api(`${base}/${id}/files`, { method: 'POST', body: form });
        },
        removeFile: (id, fileId) => api(`${base}/${id}/files/${fileId}`, { method: 'DELETE' }),
    };
}

/** The portal: a student's own lectures, or (with a record) a child's. */
export const portalLecturesApi = {
    list: (record, query = {}) => api('/api/portal/lectures', { query: { ...query, ...(record ? { record } : {}) } }),
};
