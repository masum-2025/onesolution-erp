import { api } from '@/lib/http';

/**
 * Calls to an organization's audit register. The server checks every access
 * again (permission, factory, module on); these only shape the requests.
 */
export function complianceApi(organizationId) {
    const base = `/api/organizations/${organizationId}/compliance`;

    return {
        options: () => api(`${base}/options`),
        register: (query) => api(`${base}/register`, { query }),
        audit: (id) => api(`${base}/audits/${id}`),
        add: (body) => api(`${base}/audits`, { method: 'POST', body }),
        update: (id, body) => api(`${base}/audits/${id}`, { method: 'PATCH', body }),
        dates: (id, body) => api(`${base}/audits/${id}/dates`, { method: 'POST', body }),

        list: (kind, all = false) => api(`${base}/lists/${kind}`, { query: { all: all ? 1 : undefined } }),
        addItem: (kind, body) => api(`${base}/lists/${kind}`, { method: 'POST', body }),
        updateItem: (kind, id, body) => api(`${base}/lists/${kind}/${id}`, { method: 'PATCH', body }),
        addDefaults: (kind) => api(`${base}/lists/${kind}/defaults`, { method: 'POST' }),

        // Checked (dry run) or imported; the file goes again each time, nothing is kept in between.
        importFile: (file, dryRun, skipInvalid = false) => {
            const body = new FormData();
            body.append('file', file);
            body.append('dry_run', dryRun ? '1' : '0');
            body.append('skip_invalid', skipInvalid ? '1' : '0');
            return api(`${base}/import`, { method: 'POST', body });
        },
        templateUrl: `${base}/import/template`,
    };
}
