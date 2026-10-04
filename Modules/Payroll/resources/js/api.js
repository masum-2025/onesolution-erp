import { api } from '@/lib/http';

/**
 * Calls to the Payroll API at a unit. The server checks every access again
 * (permission at the company or the employee's unit, module on, a recent
 * second step for bank files); these only shape the requests.
 */
export function payrollApi(organizationId) {
    const base = `/api/organizations/${organizationId}/payroll`;

    return {
        components: () => api(`${base}/components`),
        createComponent: (body) => api(`${base}/components`, { method: 'POST', body }),
        updateComponent: (id, body) => api(`${base}/components/${id}`, { method: 'PATCH', body }),
        structures: () => api(`${base}/structures`),
        createStructure: (body) => api(`${base}/structures`, { method: 'POST', body }),
        updateStructure: (id, body) => api(`${base}/structures/${id}`, { method: 'PATCH', body }),

        employees: (search) => api(`${base}/employees`, { query: { search: search || undefined } }),
        employee: (id) => api(`${base}/employees/${id}`),
        setSalary: (id, body) => api(`${base}/employees/${id}/salary`, { method: 'POST', body }),
        setPayment: (id, body) => api(`${base}/employees/${id}/payment`, { method: 'PUT', body }),

        runs: () => api(`${base}/runs`),
        openRun: (period) => api(`${base}/runs`, { method: 'POST', body: { period } }),
        run: (id) => api(`${base}/runs/${id}`),
        slip: (runId, slipId) => api(`${base}/runs/${runId}/slips/${slipId}`),
        step: (id, step, body) => api(`${base}/runs/${id}/${step}`, { method: 'POST', body }),
        deleteRun: (id, version) => api(`${base}/runs/${id}`, { method: 'DELETE', body: { base_version: version } }),
        adjust: (id, body) => api(`${base}/runs/${id}/adjustments`, { method: 'POST', body }),
        removeAdjustment: (id, adjustmentId) => api(`${base}/runs/${id}/adjustments/${adjustmentId}`, { method: 'DELETE' }),
        bankFile: (id) => api(`${base}/runs/${id}/bank-file`),

        mySlips: () => api(`${base}/me/slips`),
        mySlip: (id) => api(`${base}/me/slips/${id}`),
    };
}

/** A portal member's own payslips (B2B2C). */
export const portalPayrollApi = {
    slips: () => api('/api/portal/payroll/slips'),
    slip: (id) => api(`/api/portal/payroll/slips/${id}`),
};
