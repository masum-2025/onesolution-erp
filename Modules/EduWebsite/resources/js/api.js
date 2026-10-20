import { api } from '@/lib/http';

/**
 * Calls to the website API of a company (or one of its branches: the
 * server finds the company). The server checks every access again
 * (permission at the company, module on); these only shape the requests.
 */
export function websiteApi(organizationId) {
    const base = `/api/organizations/${organizationId}/website`;

    return {
        // { data: site | null, meta: { templates, template_choices, max_pages, max_media_mb, page_count, has_changes, languages, can_* } }
        show: () => api(base),
        start: (body) => api(base, { method: 'POST', body }),
        update: (body) => api(base, { method: 'PATCH', body }),
        publish: (version) => api(`${base}/publish`, { method: 'POST', body: { base_version: version } }),
        offline: (version) => api(`${base}/offline`, { method: 'POST', body: { base_version: version } }),
        // A signed link to the draft (a page by its address, else the home page).
        preview: (page) => api(`${base}/preview`, { query: page ? { page } : undefined }),
        // The own domain: set (waits for its DNS proof), prove, remove.
        setDomain: (host) => api(`${base}/domain`, { method: 'PUT', body: { host } }),
        verifyDomain: () => api(`${base}/domain/verify`, { method: 'POST' }),
        removeDomain: () => api(`${base}/domain`, { method: 'DELETE' }),
        addressCheck: (slug, signal) => api(`${base}/address-check`, { query: { slug }, signal }),

        pages: () => api(`${base}/pages`),
        createPage: (body) => api(`${base}/pages`, { method: 'POST', body }),
        updatePage: (id, body) => api(`${base}/pages/${id}`, { method: 'PATCH', body }),
        removePage: (id, version) => api(`${base}/pages/${id}`, { method: 'DELETE', body: { base_version: version } }),
        orderPages: (ids) => api(`${base}/pages/order`, { method: 'POST', body: { ids } }),

        addBlock: (pageId, type, data) => api(`${base}/pages/${pageId}/blocks`, { method: 'POST', body: { type, data } }),
        updateBlock: (pageId, id, data, version) => api(`${base}/pages/${pageId}/blocks/${id}`, { method: 'PATCH', body: { data, base_version: version } }),
        removeBlock: (pageId, id, version) => api(`${base}/pages/${pageId}/blocks/${id}`, { method: 'DELETE', body: { base_version: version } }),
        orderBlocks: (pageId, ids) => api(`${base}/pages/${pageId}/blocks/order`, { method: 'POST', body: { ids } }),

        notices: () => api(`${base}/notices`),
        createNotice: (body) => api(`${base}/notices`, { method: 'POST', body }),
        updateNotice: (id, body) => api(`${base}/notices/${id}`, { method: 'PATCH', body }),
        removeNotice: (id, version) => api(`${base}/notices/${id}`, { method: 'DELETE', body: { base_version: version } }),

        media: (kind) => api(`${base}/media`, { query: kind ? { kind } : undefined }),
        upload: (file) => {
            const form = new FormData();
            form.append('file', file);
            return api(`${base}/media`, { method: 'POST', body: form });
        },
        removeMedia: (id) => api(`${base}/media/${id}`, { method: 'DELETE' }),
    };
}
