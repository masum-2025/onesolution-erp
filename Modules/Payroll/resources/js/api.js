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
        myLoans: () => api(`${base}/me/loans`),
        myBonuses: () => api(`${base}/me/bonuses`),
        myBonus: (id) => api(`${base}/me/bonuses/${id}`),

        loans: (query = {}) => api(`${base}/loans`, { query }),
        loan: (id) => api(`${base}/loans/${id}`),
        requestLoan: (body) => api(`${base}/loans`, { method: 'POST', body }),
        loanStep: (id, step, body) => api(`${base}/loans/${id}/${step}`, { method: 'POST', body }),
        skipMonth: (id, body) => api(`${base}/loans/${id}/skips`, { method: 'POST', body }),
        unskipMonth: (id, period) => api(`${base}/loans/${id}/skips/${period}`, { method: 'DELETE' }),

        bonuses: () => api(`${base}/bonuses`),
        bonus: (id) => api(`${base}/bonuses/${id}`),
        openBonus: (body) => api(`${base}/bonuses`, { method: 'POST', body }),
        bonusStep: (id, step, body) => api(`${base}/bonuses/${id}/${step}`, { method: 'POST', body }),
        changeBonusLine: (id, lineId, body) => api(`${base}/bonuses/${id}/lines/${lineId}`, { method: 'PATCH', body }),
        deleteBonus: (id, version) => api(`${base}/bonuses/${id}`, { method: 'DELETE', body: { base_version: version } }),
        bonusBankFile: (id) => api(`${base}/bonuses/${id}/bank-file`),
    };
}

/** A portal member's own payslips (B2B2C). */
export const portalPayrollApi = {
    slips: () => api('/api/portal/payroll/slips'),
    slip: (id) => api(`/api/portal/payroll/slips/${id}`),
    loans: () => api('/api/portal/payroll/loans'),
    bonuses: () => api('/api/portal/payroll/bonuses'),
    bonus: (id) => api(`/api/portal/payroll/bonuses/${id}`),
};
