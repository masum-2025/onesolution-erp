import { api } from './http';
import { cached } from './cache';

/**
 * Organizations visible in the current context, shared by the tree,
 * pickers and the command palette. The server decides visibility.
 */
export async function visibleOrganizations() {
    return cached('organizations', async () => {
        const all = [];
        let page = 1;
        let last = 1;

        do {
            const response = await api('/api/organizations', { query: { per_page: 100, page } });
            all.push(...response.data);
            last = response.meta?.last_page ?? 1;
            page += 1;
        } while (page <= last && page <= 20);

        return all;
    });
}

/**
 * Which unit type may sit under which, from the rule tenancy.allowed_parents
 * (never hardcoded in the app). Shape: { company: ['group'], branch: ['company'], ... }.
 */
export async function allowedParents(organizationId) {
    return cached('allowed-parents', async () => {
        const { data } = await api(`/api/organizations/${organizationId}/rules/tenancy.allowed_parents`);
        return data.value ?? {};
    });
}

/** Unit types that may be created under a parent of the given type (groups come from the partner). */
export function childTypesFor(parentType, rules) {
    return Object.entries(rules)
        .filter(([type, parents]) => type !== 'group' && Array.isArray(parents) && parents.includes(parentType))
        .map(([type]) => type);
}

/**
 * Nest a flat list into a tree. Nodes whose parent is not visible become roots.
 */
export function buildTree(list) {
    const byId = new Map(list.map((node) => [node.id, { ...node, children: [] }]));
    const roots = [];

    byId.forEach((node) => {
        const parent = node.parent_id ? byId.get(node.parent_id) : null;
        (parent ? parent.children : roots).push(node);
    });

    const sort = (nodes) => {
        nodes.sort((a, b) => a.display_name.localeCompare(b.display_name));
        nodes.forEach((node) => sort(node.children));
    };
    sort(roots);

    return roots;
}

/** Ancestors of a node inside the visible list, root first. */
export function ancestorsOf(list, id) {
    const byId = new Map(list.map((node) => [node.id, node]));
    const chain = [];
    let current = byId.get(id);

    while (current?.parent_id && byId.has(current.parent_id)) {
        current = byId.get(current.parent_id);
        chain.unshift(current);
    }

    return chain;
}

/** A node and everything under it. */
export function subtreeIds(list, id) {
    const ids = new Set([id]);
    let grew = true;

    while (grew) {
        grew = false;
        list.forEach((node) => {
            if (node.parent_id && ids.has(node.parent_id) && !ids.has(node.id)) {
                ids.add(node.id);
                grew = true;
            }
        });
    }

    return ids;
}
