import { api } from '@/lib/http';

/**
 * Calls to the Facebook selling API at a unit. The server checks every
 * access again (permission at the company, modules on); these only shape
 * the requests. No Facebook token ever comes back to the browser.
 */
export function socialApi(organizationId) {
    const base = `/api/organizations/${organizationId}/social`;

    return {
        pages: () => api(`${base}/pages`),
        connect: () => api(`${base}/connect`, { method: 'POST' }),
        candidates: (state) => api(`${base}/connect/candidates`, { query: { state } }),
        savePages: (body) => api(`${base}/connect/pages`, { method: 'POST', body }),
        disconnect: (id, body) => api(`${base}/pages/${id}/disconnect`, { method: 'POST', body }),
        options: () => api(`${base}/options`),
        items: (query) => api(`${base}/items`, { query }),
        posts: (query = {}) => api(`${base}/posts`, { query }),
        post: (id) => api(`${base}/posts/${id}`),
        create: (body) => api(`${base}/posts`, { method: 'POST', body }),
        update: (id, body) => api(`${base}/posts/${id}`, { method: 'PATCH', body }),
        remove: (id, body) => api(`${base}/posts/${id}`, { method: 'DELETE', body }),
        step: (id, step, body) => api(`${base}/posts/${id}/${step}`, { method: 'POST', body }),
        addMedia: (id, version, file) => {
            const form = new FormData();
            form.append('base_version', String(version));
            form.append('file', file);
            return api(`${base}/posts/${id}/media`, { method: 'POST', body: form });
        },
        removeMedia: (id, mediaId, body) => api(`${base}/posts/${id}/media/${mediaId}`, { method: 'DELETE', body }),
        orderMedia: (id, body) => api(`${base}/posts/${id}/media-order`, { method: 'PUT', body }),
        switches: (id, body) => api(`${base}/pages/${id}`, { method: 'PATCH', body }),
        threads: (query = {}) => api(`${base}/inbox`, { query }),
        thread: (id) => api(`${base}/inbox/${id}`),
        reply: (id, body) => api(`${base}/inbox/${id}/reply`, { method: 'POST', body }),
        mark: (id, step, body) => api(`${base}/inbox/${id}/${step}`, { method: 'POST', body }),
        replies: () => api(`${base}/replies`),
        saveReply: (intent, body) => api(`${base}/replies/${intent}`, { method: 'PUT', body }),
        tryReply: (body) => api(`${base}/replies/try`, { method: 'POST', body }),
    };
}

/** Badge tone per conversation status. */
export const THREAD_TONES = { open: 'brand', waiting: 'warn', done: 'neutral' };

/** Badge tone per post status (colour plus a dot and the word, never colour alone). */
export const POST_TONES = {
    draft: 'neutral',
    pending: 'warn',
    scheduled: 'brand',
    publishing: 'brand',
    published: 'ok',
    rejected: 'bad',
    failed: 'bad',
};

export const PAGE_TONES = { connected: 'ok', needs_reconnect: 'warn', disconnected: 'neutral' };

export const POST_STATUSES = ['draft', 'pending', 'scheduled', 'published', 'rejected', 'failed'];

/** An ISO time as the value of a datetime-local field (the viewer's own clock). */
export function toLocalInput(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** A datetime-local value as an ISO time (UTC), or null when empty. */
export function fromLocalInput(value) {
    if (!value) return null;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date.toISOString();
}
