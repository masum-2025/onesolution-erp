import { api } from '@/lib/http';

/**
 * Calls to the factory API at a unit. The server checks every access again
 * (permission at the unit, the module and CRM on, the order's unit); these
 * only shape the requests.
 */
export function factoryApi(organizationId) {
    const base = `/api/organizations/${organizationId}/factory`;

    return {
        setup: () => api(`${base}/setup`),
        buyers: (q) => api(`${base}/buyers`, { query: q ? { q } : {} }),
        addBuyer: (body) => api(`${base}/buyers`, { method: 'POST', body }),
        fields: () => api(`${base}/fields`),
        createField: (body) => api(`${base}/fields`, { method: 'POST', body }),
        updateField: (id, body) => api(`${base}/fields/${id}`, { method: 'PATCH', body }),
        styles: (query = {}) => api(`${base}/styles`, { query }),
        style: (id) => api(`${base}/styles/${id}`),
        createStyle: (body) => api(`${base}/styles`, { method: 'POST', body }),
        updateStyle: (id, body) => api(`${base}/styles/${id}`, { method: 'PATCH', body }),
        saveBom: (id, body) => api(`${base}/styles/${id}/bom`, { method: 'PUT', body }),
        saveOperations: (id, body) => api(`${base}/styles/${id}/operations`, { method: 'PUT', body }),
        saveLineDay: (body) => api(`${base}/line-days`, { method: 'POST', body }),
        defectTypes: () => api(`${base}/defect-types`),
        createDefectType: (body) => api(`${base}/defect-types`, { method: 'POST', body }),
        updateDefectType: (id, body) => api(`${base}/defect-types/${id}`, { method: 'PATCH', body }),
        inspections: (query = {}) => api(`${base}/inspections`, { query }),
        createInspection: (body) => api(`${base}/inspections`, { method: 'POST', body }),
        voidInspection: (id, body) => api(`${base}/inspections/${id}/void`, { method: 'POST', body }),
        orderQuality: (id) => api(`${base}/orders/${id}/quality`),
        pieceTickets: (query = {}) => api(`${base}/piece-tickets`, { query }),
        shipments: (orderId) => api(`${base}/orders/${orderId}/shipments`),
        shipment: (id) => api(`${base}/shipments/${id}`),
        createShipment: (body) => api(`${base}/shipments`, { method: 'POST', body }),
        updateShipment: (id, body) => api(`${base}/shipments/${id}`, { method: 'PATCH', body }),
        shipShipment: (id, body) => api(`${base}/shipments/${id}/ship`, { method: 'POST', body }),
        createPieceTicket: (body) => api(`${base}/piece-tickets`, { method: 'POST', body }),
        voidPieceTicket: (id, body) => api(`${base}/piece-tickets/${id}/void`, { method: 'POST', body }),
        orders: (query = {}) => api(`${base}/orders`, { query }),
        order: (id) => api(`${base}/orders/${id}`),
        requirements: (id) => api(`${base}/orders/${id}/requirements`),
        createOrder: (body) => api(`${base}/orders`, { method: 'POST', body }),
        updateOrder: (id, body) => api(`${base}/orders/${id}`, { method: 'PATCH', body }),
        step: (id, step, body) => api(`${base}/orders/${id}/${step}`, { method: 'POST', body }),
        issues: (id) => api(`${base}/orders/${id}/issues`),
        issue: (id, body) => api(`${base}/orders/${id}/issues`, { method: 'POST', body }),
        cuttings: (id) => api(`${base}/orders/${id}/cuttings`),
        cut: (id, body) => api(`${base}/orders/${id}/cuttings`, { method: 'POST', body }),
        voidCutting: (id, cuttingId, body) => api(`${base}/orders/${id}/cuttings/${cuttingId}/void`, { method: 'POST', body }),
        progress: (id) => api(`${base}/orders/${id}/progress`),
        receipts: (id) => api(`${base}/orders/${id}/receipts`),
        receive: (id, body) => api(`${base}/orders/${id}/receipts`, { method: 'POST', body }),
        lines: (query = {}) => api(`${base}/lines`, { query }),
        createLine: (body) => api(`${base}/lines`, { method: 'POST', body }),
        updateLine: (id, body) => api(`${base}/lines/${id}`, { method: 'PATCH', body }),
        outputs: (query = {}) => api(`${base}/outputs`, { query }),
        createOutput: (body) => api(`${base}/outputs`, { method: 'POST', body }),
        voidOutput: (id, body) => api(`${base}/outputs/${id}/void`, { method: 'POST', body }),
    };
}
