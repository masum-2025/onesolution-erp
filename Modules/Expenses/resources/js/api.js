import { api } from '@/lib/http';

/**
 * Calls to the Expenses API at a unit (company, branch or department). The
 * server checks every access again (permission at the employee's unit,
 * module on, never deciding one's own); these only shape the requests.
 */
export function expensesApi(organizationId) {
    const base = `/api/organizations/${organizationId}/expenses`;

    return {
        // { employee, claims, summary, categories, limits, payroll }
        me: () => api(`${base}/me`),
        myClaim: (id) => api(`${base}/me/claims/${id}`),
        // { title, lines: [{ id?, spent_on, category_id, description, amount_minor?, distance_tenths? }], op_id }
        draft: (body) => api(`${base}/me/claims`, { method: 'POST', body }),
        updateDraft: (id, body) => api(`${base}/me/claims/${id}`, { method: 'PATCH', body }),
        discard: (id, body) => api(`${base}/me/claims/${id}`, { method: 'DELETE', body }),
        // step: submit | withdraw; body: { base_version }
        mine: (id, step, body) => api(`${base}/me/claims/${id}/${step}`, { method: 'POST', body }),
        addReceipt: (id, file, lineId) => {
            const body = new FormData();
            body.append('file', file);
            if (lineId) body.append('line_id', lineId);
            return api(`${base}/me/claims/${id}/receipts`, { method: 'POST', body });
        },
        removeReceipt: (id, receiptId) => api(`${base}/me/claims/${id}/receipts/${receiptId}`, { method: 'DELETE' }),

        // query: { status, waiting, period, q }
        claims: (query) => api(`${base}/claims`, { query }),
        claim: (id) => api(`${base}/claims/${id}`),
        // step: approve | reject; body: { base_version, note }
        decide: (id, step, body) => api(`${base}/claims/${id}/${step}`, { method: 'POST', body }),
        // { base_version, method: cash | bank | payroll, period }
        pay: (id, body) => api(`${base}/claims/${id}/pay`, { method: 'POST', body }),

        // Advances: one's own, and those of the unit's people.
        // { amount_minor, purpose, settle_by, op_id }
        askAdvance: (body) => api(`${base}/me/advances`, { method: 'POST', body }),
        myAdvance: (id) => api(`${base}/me/advances/${id}`),
        withdrawAdvance: (id, body) => api(`${base}/me/advances/${id}/withdraw`, { method: 'POST', body }),
        // status: submitted | approved | paid | settled | rejected | withdrawn | waiting (for me) | overdue
        advances: (status) => api(`${base}/advances`, { query: { status } }),
        advance: (id) => api(`${base}/advances/${id}`),
        // step: approve | reject; body: { base_version, note }
        decideAdvance: (id, step, body) => api(`${base}/advances/${id}/${step}`, { method: 'POST', body }),
        // { base_version, method: cash | bank }
        payAdvance: (id, body) => api(`${base}/advances/${id}/pay`, { method: 'POST', body }),
        // what is left: { base_version, method: cash | bank | payroll, period }
        settleAdvance: (id, body) => api(`${base}/advances/${id}/settle`, { method: 'POST', body }),

        categories: () => api(`${base}/categories`),
        createCategory: (body) => api(`${base}/categories`, { method: 'POST', body }),
        updateCategory: (id, body) => api(`${base}/categories/${id}`, { method: 'PATCH', body }),
        addDefaults: () => api(`${base}/categories/defaults`, { method: 'POST' }),
    };
}

/** A portal member's own claims (B2B2C). */
export const portalExpensesApi = {
    show: () => api('/api/portal/expenses'),
};
