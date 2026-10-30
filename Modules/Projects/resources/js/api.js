import { api } from '@/lib/http';

/**
 * Calls to an organization's projects. The server checks every access again
 * (permission, unit, the people on a task, module on); these only shape the requests.
 */
export function projectsApi(organizationId) {
    const base = `/api/organizations/${organizationId}/projects`;

    return {
        options: () => api(`${base}/options`),
        clients: (q) => api(`${base}/clients`, { query: { q: q || undefined } }),
        list: (query) => api(base, { query }),
        project: (id) => api(`${base}/${id}`),
        open: (body) => api(base, { method: 'POST', body }),
        update: (id, body) => api(`${base}/${id}`, { method: 'PATCH', body }),
        step: (id, step, version, reason) => api(`${base}/${id}/${step}`, { method: 'POST', body: { base_version: version, ...(reason ? { reason } : {}) } }),

        addTask: (projectId, body) => api(`${base}/${projectId}/tasks`, { method: 'POST', body }),
        updateTask: (projectId, id, body) => api(`${base}/${projectId}/tasks/${id}`, { method: 'PATCH', body }),
        moveTask: (projectId, id, status, version) => api(`${base}/${projectId}/tasks/${id}/status`, { method: 'POST', body: { status, base_version: version } }),
        people: (projectId, id, userIds, leadId, version) => api(`${base}/${projectId}/tasks/${id}/people`, { method: 'PUT', body: { user_ids: userIds, lead_id: leadId || null, base_version: version } }),
        order: (projectId, ids) => api(`${base}/${projectId}/tasks/order`, { method: 'POST', body: { ids } }),
        notes: (projectId, id) => api(`${base}/${projectId}/tasks/${id}/notes`),
        addNote: (projectId, id, body) => api(`${base}/${projectId}/tasks/${id}/notes`, { method: 'POST', body: { body } }),
        mine: (done = false) => api(`${base}/my-tasks`, { query: { done: done ? 1 : undefined } }),

        templates: (all = false) => api(`${base}/templates`, { query: { all: all ? 1 : undefined } }),
        template: (id) => api(`${base}/templates/${id}`),
        addTemplate: (body) => api(`${base}/templates`, { method: 'POST', body }),
        updateTemplate: (id, body) => api(`${base}/templates/${id}`, { method: 'PATCH', body }),
    };
}
