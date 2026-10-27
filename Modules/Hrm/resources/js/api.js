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
        // Departments per branch with head counts; they are units of the organization tree,
        // made, renamed and closed by the platform's organization endpoints (organizations.manage).
        departments: () => api(`${base}/departments`),
        createDepartment: (parentId, name) => api('/api/organizations', { method: 'POST', body: { parent_id: parentId, type: 'department', name } }),
        updateDepartment: (id, body) => api(`/api/organizations/${id}`, { method: 'PATCH', body }),

        employees: (query) => api(`${base}/employees`, { query }),
        // One level of the org chart: the top (no manager) or one person's direct reports.
        orgChart: (managerId = null) => api(`${base}/org-chart`, { query: { manager_id: managerId ?? undefined } }),
        employee: (id) => api(`${base}/employees/${id}`),
        hire: (body) => api(`${base}/employees`, { method: 'POST', body }),
        update: (id, body) => api(`${base}/employees/${id}`, { method: 'PATCH', body }),
        sensitive: (id) => api(`${base}/employees/${id}/sensitive`),
        history: (id) => api(`${base}/employees/${id}/history`),
        // Logins that can be linked to the employee, and linking one (null unlinks).
        logins: (id, search) => api(`${base}/employees/${id}/logins`, { query: { search: search || undefined } }),
        linkLogin: (id, body) => api(`${base}/employees/${id}/login`, { method: 'PUT', body }),
        // A login in one step: an invitation by email (no sign-up), roles, linked.
        inviteLogin: (id, body) => api(`${base}/employees/${id}/invite-login`, { method: 'POST', body }),
        resendLogin: (id) => api(`${base}/employees/${id}/resend-login`, { method: 'POST' }),
        roles: () => api(`/api/organizations/${organizationId}/roles`),
        step: (id, step, body) => api(`${base}/employees/${id}/steps/${step}`, { method: 'POST', body }),

        documents: (id) => api(`${base}/employees/${id}/documents`),
        upload: (id, form) => api(`${base}/employees/${id}/documents`, { method: 'POST', body: form }),
        documentLink: (id, documentId) => api(`${base}/employees/${id}/documents/${documentId}/link`),
        removeDocument: (id, documentId, reason) => api(`${base}/employees/${id}/documents/${documentId}`, { method: 'DELETE', body: { reason } }),

        customFields: (unitId, all = false) => api(`${base}/custom-fields`, { query: { unit_id: unitId, all: all ? 1 : undefined } }),
        createField: (body) => api(`${base}/custom-fields`, { method: 'POST', body }),
        updateField: (id, body) => api(`${base}/custom-fields/${id}`, { method: 'PATCH', body }),

        importColumns: (unitId) => api(`${base}/imports/columns`, { query: { unit_id: unitId } }),
        imports: () => api(`${base}/imports`),
        importFile: (form) => api(`${base}/imports`, { method: 'POST', body: form }),
        importStatus: (id) => api(`${base}/imports/${id}`),
        startImport: (id, skipInvalid) => api(`${base}/imports/${id}/start`, { method: 'POST', body: { skip_invalid: skipInvalid } }),
        cancelImport: (id) => api(`${base}/imports/${id}`, { method: 'DELETE' }),
    };
}
