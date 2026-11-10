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
        payLink: (id) => api(`${base}/orders/${id}/pay-link`, { method: 'POST' }),
        // SC-5b: book with a courier account, ask the courier, and the company's accounts.
        book: (id, body) => api(`${base}/orders/${id}/book`, { method: 'POST', body }),
        courierStatus: (id) => api(`${base}/orders/${id}/courier-status`, { method: 'POST' }),
        couriers: () => api(`${base}/couriers`),
        addCourier: (body) => api(`${base}/couriers`, { method: 'POST', body }),
        changeCourier: (id, body) => api(`${base}/couriers/${id}`, { method: 'PATCH', body }),
        // SC-6: what couriers owe and the payouts they send.
        courierPayouts: () => api(`${base}/courier-payouts`),
        courierPayout: (id) => api(`${base}/courier-payouts/${id}`),
        recordPayout: (id, body) => api(`${base}/courier-payouts/${id}`, { method: 'POST', body }),
        // SC-7: parcels coming back from the courier.
        returns: () => api(`${base}/returns`),
        receiveBack: (id, body) => api(`${base}/orders/${id}/receive-back`, { method: 'POST', body }),
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

/** Badge tone per courier status (SC-5b). */
export const COURIER_TONES = {
    booked: 'brand',
    in_transit: 'warn',
    on_hold: 'warn',
    delivered: 'ok',
    partly_delivered: 'warn',
    cancelled: 'bad',
    unknown: 'neutral',
};

/** The next step forward from each status (the main button on an order). */
export const NEXT_STEP = { draft: 'confirm', confirmed: 'pack', packed: 'ship', shipped: 'deliver' };
