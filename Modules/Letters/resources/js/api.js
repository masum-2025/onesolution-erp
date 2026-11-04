import { api } from '@/lib/http';

/**
 * Calls to the Letters API at a unit. The server checks every access again
 * (permission at the unit or company, salary in letters, module on); these
 * only shape the requests.
 */
export function lettersApi(organizationId) {
    const base = `/api/organizations/${organizationId}/letters`;

    return {
        options: (locale) => api(`${base}/options`, { query: { locale: locale || undefined } }),
        templates: () => api(`${base}/templates`),
        template: (id) => api(`${base}/templates/${id}`),
        createTemplate: (body) => api(`${base}/templates`, { method: 'POST', body }),
        updateTemplate: (id, body) => api(`${base}/templates/${id}`, { method: 'PATCH', body }),
        addPreset: (key) => api(`${base}/templates/presets/${key}`, { method: 'POST' }),
        preview: (id, body) => api(`${base}/templates/${id}/preview`, { method: 'POST', body }),

        assets: () => api(`${base}/assets`),
        addAsset: (form) => api(`${base}/assets`, { method: 'POST', body: form }),
        updateAsset: (id, body) => api(`${base}/assets/${id}`, { method: 'PATCH', body }),

        employees: (q) => api(`${base}/employees`, { query: { q: q || undefined } }),
        source: (type, id) => api(`${base}/sources/${encodeURIComponent(type)}/${id}`),
        letters: (query = {}) => api(`${base}/issued`, { query }),
        letter: (id) => api(`${base}/issued/${id}`),
        issue: (body) => api(`${base}/issued`, { method: 'POST', body }),
        revoke: (id, reason) => api(`${base}/issued/${id}/revoke`, { method: 'POST', body: { reason } }),

        mine: () => api(`${base}/me`),
        myLetter: (id) => api(`${base}/me/${id}`),
    };
}

/** The portal member's own letters (no unit in the address: the portal context decides). */
export const portalLettersApi = {
    letters: () => api('/api/portal/letters'),
    letter: (id) => api(`/api/portal/letters/${id}`),
};
