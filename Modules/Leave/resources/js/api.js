import { api } from '@/lib/http';

/**
 * Calls to the Leave API at a unit (company, branch or department). The
 * server checks every access again (permission at the employee's unit,
 * module on, never deciding one's own); these only shape the requests.
 */
export function leaveApi(organizationId) {
    const base = `/api/organizations/${organizationId}/leave`;

    return {
        me: () => api(`${base}/me`),
        // { type_id, from_date, to_date, from_half, to_half, reason, op_id }
        askMine: (body) => api(`${base}/me/requests`, { method: 'POST', body }),
        cancelMine: (id, body) => api(`${base}/me/requests/${id}/cancel`, { method: 'POST', body }),

        types: (query) => api(`${base}/types`, { query }),
        createType: (body) => api(`${base}/types`, { method: 'POST', body }),
        updateType: (id, body) => api(`${base}/types/${id}`, { method: 'PATCH', body }),
        addDefaults: () => api(`${base}/types/defaults`, { method: 'POST' }),

        requests: (query) => api(`${base}/requests`, { query }),
        askFor: (body) => api(`${base}/requests`, { method: 'POST', body }),
        // step: approve | reject | cancel; body: { base_version, note }
        decide: (id, step, body) => api(`${base}/requests/${id}/${step}`, { method: 'POST', body }),

        people: (q) => api(`${base}/people`, { query: { q } }),
        balances: (employeeId, year) => api(`${base}/balances`, { query: { employee_id: employeeId, year } }),
        adjust: (body) => api(`${base}/balances/adjust`, { method: 'POST', body }),
    };
}

/** A portal member's own leave (B2B2C). */
export const portalLeaveApi = {
    show: () => api('/api/portal/leave'),
};
