import { api } from '@/lib/http';

/**
 * Calls to the pharmacy API at a unit (and the POS calls the counter needs:
 * counters, shifts). The server checks every access again (permission at the
 * counter's branch, modules on); these only shape the requests.
 */
export function pharmacyApi(organizationId) {
    const base = `/api/organizations/${organizationId}/pharmacy`;
    const pos = `/api/organizations/${organizationId}/pos`;

    return {
        setup: () => api(`${base}/setup`),
        medicines: (query = {}) => api(`${base}/medicines`, { query }),
        medicine: (id) => api(`${base}/medicines/${id}`),
        substitutes: (id) => api(`${base}/medicines/${id}/substitutes`),
        createMedicine: (body) => api(`${base}/medicines`, { method: 'POST', body }),
        updateMedicine: (id, body) => api(`${base}/medicines/${id}`, { method: 'PATCH', body }),

        counter: (registerId) => api(`${base}/counter/${registerId}`),
        sell: (body) => api(`${base}/sales`, { method: 'POST', body }),
        sales: (query = {}) => api(`${base}/sales`, { query }),

        stock: (query = {}) => api(`${base}/stock`, { query }),
        purchases: (query = {}) => api(`${base}/purchases`, { query }),
        buy: (body) => api(`${base}/purchases`, { method: 'POST', body }),
        returns: (query = {}) => api(`${base}/supplier-returns`, { query }),
        sendReturn: (body) => api(`${base}/supplier-returns`, { method: 'POST', body }),
        approveReturn: (id, body) => api(`${base}/supplier-returns/${id}/approve`, { method: 'POST', body }),
        rejectReturn: (id, body) => api(`${base}/supplier-returns/${id}/reject`, { method: 'POST', body }),
        controlled: (query = {}) => api(`${base}/controlled-register`, { query }),
        report: (query = {}) => api(`${base}/reports`, { query }),

        registers: () => api(`${pos}/registers`),
        catalogue: (registerId) => api(`${pos}/registers/${registerId}/catalogue`),
        openShift: (registerId, float) => api(`${pos}/registers/${registerId}/open`, { method: 'POST', body: { opening_float_minor: float } }),
    };
}

/** Pieces in a pack of a medicine (null when it has no such pack). */
export function piecesIn(medicine, pack) {
    if (pack === 'piece') return 1;
    if (pack === 'strip') return medicine.pieces_per_strip || null;
    if (pack === 'box') return medicine.strips_per_box ? (medicine.pieces_per_strip || 1) * medicine.strips_per_box : null;
    return null;
}

/** The packs a medicine is sold in, biggest last. */
export function packsOf(medicine, loosePieces = true) {
    return ['piece', 'strip', 'box'].filter((pack) => piecesIn(medicine, pack) !== null && !(pack === 'piece' && !loosePieces && medicine.pieces_per_strip));
}

/** Whole pieces from a quantity in thousandths. */
export const pieces = (milli) => Math.trunc((milli ?? 0) / 1000);
