import { api } from './http';
import { can } from './session';

/*
 * One rule editor UI for two levels: an organization (group, company, branch,
 * department) and the partner console. Each adapter says what the level supports.
 */

function groupFlat(rules) {
    const modules = new Map();
    rules.forEach((rule) => {
        if (!modules.has(rule.module)) modules.set(rule.module, { module: rule.module, name: rule.module_name, categories: new Map() });
        const group = modules.get(rule.module);
        if (!group.categories.has(rule.category)) group.categories.set(rule.category, { category: rule.category, label: rule.category_label, rules: [] });
        group.categories.get(rule.category).rules.push(rule);
    });
    return [...modules.values()].map((group) => ({ ...group, categories: [...group.categories.values()] }));
}

export function organizationRules(organizationId) {
    const base = `/api/organizations/${organizationId}`;
    const rule = (key) => `${base}/rules/${encodeURIComponent(key)}`;

    return {
        level: 'organization',
        canEdit: () => can('rules.manage'),
        supports: { trace: true, history: true, preview: true, rollback: true },
        list: () => api(`${base}/rules`).then((response) => response.data),
        show: (key) => api(rule(key)).then((response) => response.data),
        set: (key, body) => api(rule(key), { method: 'PUT', body }),
        reset: (key, body) => api(rule(key), { method: 'DELETE', body }),
        preview: (key, body) => api(`${rule(key)}/preview`, { method: 'POST', body }).then((response) => response.data),
        history: (key) => api(`${rule(key)}/history`).then((response) => response.data),
        rollback: (key, body) => api(`${rule(key)}/rollback`, { method: 'POST', body }),
        approve: (valueId, body) => api(`${base}/rule-approvals/${valueId}/approve`, { method: 'POST', body }),
        reject: (valueId, body) => api(`${base}/rule-approvals/${valueId}/reject`, { method: 'POST', body }),
    };
}

export function partnerRules() {
    let latest = [];

    return {
        level: 'partner',
        canEdit: () => can('partner.rules.manage'),
        supports: { trace: false, history: false, preview: false, rollback: false },
        list: async () => {
            latest = (await api('/api/partner/rules')).data;
            return groupFlat(latest);
        },
        show: async (key) => {
            latest = (await api('/api/partner/rules')).data;
            return latest.find((item) => item.key === key) ?? null;
        },
        set: (key, body) => api(`/api/partner/rules/${encodeURIComponent(key)}`, { method: 'PUT', body }),
        reset: (key, body) => api(`/api/partner/rules/${encodeURIComponent(key)}`, { method: 'DELETE', body }),
        approve: (valueId, body) => api(`/api/partner/rule-approvals/${valueId}/approve`, { method: 'POST', body }),
        reject: (valueId, body) => api(`/api/partner/rule-approvals/${valueId}/reject`, { method: 'POST', body }),
    };
}
