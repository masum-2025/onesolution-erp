import { api } from '@/lib/http';

/**
 * Calls to the ZKTeco API at a unit. The server checks every access again
 * (permission, the modules on, the device's branch); these only shape the requests.
 */
export function zktecoApi(organizationId) {
    const base = `/api/organizations/${organizationId}/zkteco`;

    return {
        setup: () => api(`${base}/setup`),
        devices: () => api(`${base}/devices`),
        add: (body) => api(`${base}/devices`, { method: 'POST', body }),
        change: (id, body) => api(`${base}/devices/${id}`, { method: 'PATCH', body }),
        remove: (id, version) => api(`${base}/devices/${id}`, { method: 'DELETE', query: { base_version: version } }),
        test: (id) => api(`${base}/devices/${id}/test`, { method: 'POST' }),
        sync: (id) => api(`${base}/devices/${id}/sync`, { method: 'POST' }),
        users: (id) => api(`${base}/devices/${id}/users`),
        match: (id, userId, employeeId) => api(`${base}/devices/${id}/users/${userId}`, { method: 'PATCH', body: { employee_id: employeeId } }),
        matchMany: (id, matches) => api(`${base}/devices/${id}/users`, { method: 'PATCH', body: { matches } }),
        bridges: () => api(`${base}/bridges`),
        addBridge: (body) => api(`${base}/bridges`, { method: 'POST', body }),
        revokeBridge: (id) => api(`${base}/bridges/${id}/revoke`, { method: 'POST' }),
        // A plain link: the browser downloads it with the signed-in session.
        programUrl: `${base}/bridges/program`,
    };
}
