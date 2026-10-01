import { api } from '@/lib/http';

/**
 * Calls to the HRM API of an organization. The server checks every access
 * again (permission, unit, module on); these only shape the requests.
 */
export function hrmApi(organizationId) {
    const base = `/api/organizations/${organizationId}/hrm`;

    return {
        formOptions: (unitId) => api(`${base}/form-options`, { query: { unit_id: unitId } }),
        positions: (all = false) => api(`${base}/positions`, { query: { all: all ? 1 : undefined } }),
        createPosition: (body) => api(`${base}/positions`, { method: 'POST', body }),
        updatePosition: (id, body) => api(`${base}/positions/${id}`, { method: 'PATCH', body }),

        employees: (query) => api(`${base}/employees`, { query }),
        employee: (id) => api(`${base}/employees/${id}`),
        hire: (body) => api(`${base}/employees`, { method: 'POST', body }),
        update: (id, body) => api(`${base}/employees/${id}`, { method: 'PATCH', body }),
        sensitive: (id) => api(`${base}/employees/${id}/sensitive`),
        history: (id) => api(`${base}/employees/${id}/history`),
        step: (id, step, body) => api(`${base}/employees/${id}/steps/${step}`, { method: 'POST', body }),

        documents: (id) => api(`${base}/employees/${id}/documents`),
        upload: (id, form) => api(`${base}/employees/${id}/documents`, { method: 'POST', body: form }),
        documentLink: (id, documentId) => api(`${base}/employees/${id}/documents/${documentId}/link`),
        removeDocument: (id, documentId, reason) => api(`${base}/employees/${id}/documents/${documentId}`, { method: 'DELETE', body: { reason } }),
    };
}
