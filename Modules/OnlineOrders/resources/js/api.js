import { api } from '@/lib/http';

/**
 * Calls to the online orders API at a unit. The server checks every access
 * again (permission at the order's branch, modules on); these only shape
 * the requests.
 */
export function onlineOrdersApi(organizationId) {
    const base = `/api/organizations/${organizationId}/online-orders`;

    return {
        options: () => api(`${base}/options`),
        items: (query) => api(`${base}/items`, { query }),
        orders: (query = {}) => api(`${base}/orders`, { query }),
        order: (id) => api(`${base}/orders/${id}`),
        place: (body) => api(`${base}/orders`, { method: 'POST', body }),
        revise: (id, body) => api(`${base}/orders/${id}`, { method: 'PATCH', body }),
        step: (id, step, body) => api(`${base}/orders/${id}/${step}`, { method: 'POST', body }),
        pay: (id, body) => api(`${base}/orders/${id}/payments`, { method: 'POST', body }),
    };
}

/** Badge tone per status (colour plus a dot and the word, never colour alone). */
export const STATUS_TONES = {
    draft: 'neutral',
    confirmed: 'brand',
    packed: 'brand',
    shipped: 'warn',
    delivered: 'ok',
    cancelled: 'bad',
    returned: 'bad',
};

/** The next step forward from each status (the main button on an order). */
export const NEXT_STEP = { draft: 'confirm', confirmed: 'pack', packed: 'ship', shipped: 'deliver' };
