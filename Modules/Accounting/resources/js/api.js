import { api } from '@/lib/http';

/**
 * Calls to the Accounting API of a company. The server checks every access
 * again (permission, company, module on); these only shape the requests.
 */
export function accountingApi(organizationId) {
    const base = `/api/organizations/${organizationId}/accounting`;

    return {
        setup: () => api(`${base}/setup`),
        setUp: (body) => api(`${base}/setup`, { method: 'POST', body }),

        accounts: (archived = false) => api(`${base}/accounts`, { query: { archived: archived ? 1 : undefined } }),
        createAccount: (body) => api(`${base}/accounts`, { method: 'POST', body }),
        updateAccount: (id, body) => api(`${base}/accounts/${id}`, { method: 'PATCH', body }),

        fiscalYears: () => api(`${base}/fiscal-years`),
        addFiscalYear: (body = {}) => api(`${base}/fiscal-years`, { method: 'POST', body }),
        periodStep: (id, step, body = {}) => api(`${base}/periods/${id}/${step}`, { method: 'POST', body }),
        yearStep: (id, step, body = {}) => api(`${base}/fiscal-years/${id}/${step}`, { method: 'POST', body }),
        reopenStep: (id, step, body = {}) => api(`${base}/reopen-requests/${id}/${step}`, { method: 'POST', body }),

        opening: () => api(`${base}/opening`),
        saveOpening: (body) => api(`${base}/opening`, { method: 'PUT', body }),
        deleteOpening: (version) => api(`${base}/opening`, { method: 'DELETE', body: { base_version: version } }),
        openingStep: (step, body) => api(`${base}/opening/${step}`, { method: 'POST', body }),

        bankAccounts: () => api(`${base}/bank/accounts`),
        bankDesk: (accountId, query) => api(`${base}/bank/accounts/${accountId}`, { query }),
        importStatement: (accountId, body) => api(`${base}/bank/accounts/${accountId}/import`, { method: 'POST', body }),
        bankAutoMatch: (accountId) => api(`${base}/bank/accounts/${accountId}/auto-match`, { method: 'POST' }),
        bankLineStep: (id, step, body = {}) => api(`${base}/bank-lines/${id}/${step}`, { method: 'POST', body }),
        deleteBankLine: (id) => api(`${base}/bank-lines/${id}`, { method: 'DELETE' }),
        bankReconciliationPreview: (accountId, query) => api(`${base}/bank/accounts/${accountId}/reconciliation`, { query }),
        finishReconciliation: (accountId, body) => api(`${base}/bank/accounts/${accountId}/reconciliations`, { method: 'POST', body }),
        reopenReconciliation: (id, body) => api(`${base}/reconciliations/${id}/reopen`, { method: 'POST', body }),

        postingAccounts: () => api(`${base}/posting-accounts`),
        setPostingAccount: (key, body) => api(`${base}/posting-accounts/${encodeURIComponent(key)}`, { method: 'PUT', body }),

        journals: (query) => api(`${base}/journals`, { query }),
        journal: (id) => api(`${base}/journals/${id}`),
        createJournal: (body) => api(`${base}/journals`, { method: 'POST', body }),
        updateJournal: (id, body) => api(`${base}/journals/${id}`, { method: 'PATCH', body }),
        deleteJournal: (id, version) => api(`${base}/journals/${id}`, { method: 'DELETE', body: { base_version: version } }),
        step: (id, step, body) => api(`${base}/journals/${id}/${step}`, { method: 'POST', body }),

        report: (name, query) => api(`${base}/reports/${name}`, { query }),

        taxCodes: () => api(`${base}/tax-codes`),
        createTaxCode: (body) => api(`${base}/tax-codes`, { method: 'POST', body }),
        updateTaxCode: (id, body) => api(`${base}/tax-codes/${id}`, { method: 'PATCH', body }),

        parties: (query) => api(`${base}/parties`, { query }),
        party: (id) => api(`${base}/parties/${id}`),
        createParty: (body) => api(`${base}/parties`, { method: 'POST', body }),
        updateParty: (id, body) => api(`${base}/parties/${id}`, { method: 'PATCH', body }),

        documents: (query) => api(`${base}/documents`, { query }),
        document: (id) => api(`${base}/documents/${id}`),
        createDocument: (body) => api(`${base}/documents`, { method: 'POST', body }),
        updateDocument: (id, body) => api(`${base}/documents/${id}`, { method: 'PATCH', body }),
        deleteDocument: (id, version) => api(`${base}/documents/${id}`, { method: 'DELETE', body: { base_version: version } }),
        documentStep: (id, step, body) => api(`${base}/documents/${id}/${step}`, { method: 'POST', body }),

        settlements: (query) => api(`${base}/settlements`, { query }),
        settlement: (id) => api(`${base}/settlements/${id}`),
        createSettlement: (body) => api(`${base}/settlements`, { method: 'POST', body }),
        settlementStep: (id, step, body) => api(`${base}/settlements/${id}/${step}`, { method: 'POST', body }),
    };
}
