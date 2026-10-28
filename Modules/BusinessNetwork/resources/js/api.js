import { api } from '@/lib/http';

/**
 * Calls to an organization's business connections. The server checks every
 * access again (permission, side, module on); these only shape the requests.
 */
export function connectionsApi(organizationId) {
    const base = `/api/organizations/${organizationId}/connections`;

    return {
        list: () => api(base),
        invite: (note) => api(`${base}/invitations`, { method: 'POST', body: { note: note || null } }),
        preview: (code) => api(`${base}/preview`, { method: 'POST', body: { code } }),
        accept: (code, unitIds) => api(`${base}/accept`, { method: 'POST', body: { code, unit_ids: unitIds } }),
        show: (id) => api(`${base}/${id}`),
        messages: (id) => api(`${base}/${id}/messages`),
        units: (id, version, unitIds) => api(`${base}/${id}/units`, { method: 'PUT', body: { base_version: version, unit_ids: unitIds } }),
        step: (id, step, version, reason) => api(`${base}/${id}/${step}`, { method: 'POST', body: { base_version: version, ...(reason ? { reason } : {}) } }),
    };
}

/** Badge tone of a connection status, with its word next to it (never colour alone). */
export function statusTone(status) {
    return { invited: 'warn', active: 'ok', paused: 'neutral', ended: 'outline' }[status] ?? 'neutral';
}

/** Indent of a unit under the client in a list: its depth below the shallowest one. */
export function indentOf(units, unit) {
    const top = Math.min(...units.map((item) => item.depth ?? 0));
    return Math.max(0, (unit.depth ?? 0) - top);
}
