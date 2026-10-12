/**
 * Helpers for the role editor and the member role dialog. The server makes
 * every decision again; these only give instant feedback while choosing.
 */

/** Pairs (from the separation-of-duties rule) that a set of permissions breaks. */
export function conflictsIn(permissions, pairs) {
    const held = new Set(permissions);
    return (pairs ?? []).filter((pair) => held.has(pair.first) && held.has(pair.second));
}

/** Permissions that may not be held together with this one. */
export function partnersOf(key, pairs) {
    return (pairs ?? []).flatMap((pair) => (pair.first === key ? [pair.second] : pair.second === key ? [pair.first] : []));
}

/** What changed between two permission lists. */
export function diffPermissions(before, after) {
    const old = new Set(before);
    const now = new Set(after);
    return {
        added: [...now].filter((key) => !old.has(key)).sort(),
        removed: [...old].filter((key) => !now.has(key)).sort(),
    };
}

/** key → label, from the grouped /permissions payload. */
export function permissionLabels(groups) {
    return Object.fromEntries((groups ?? []).flatMap((group) => group.permissions.map((permission) => [permission.key, permission.label])));
}

/**
 * Toggle every permission of a group that this person may change. Permissions
 * they cannot grant stay exactly as they are (checked or not).
 */
export function toggleGroup(selected, group) {
    const changeable = group.permissions.filter((permission) => !permission.blocked_by).map((permission) => permission.key);
    const set = new Set(selected);
    const allOn = changeable.length > 0 && changeable.every((key) => set.has(key));
    changeable.forEach((key) => (allOn ? set.delete(key) : set.add(key)));
    return [...set].sort();
}

/** Union of the permissions of the chosen roles. */
export function permissionsOfRoles(roles, ids) {
    const chosen = new Set(ids);
    return [...new Set(roles.filter((role) => chosen.has(role.id)).flatMap((role) => role.permissions))].sort();
}

/** A permission that only lets one look (crm.view, payroll.view_own, …). */
export function isViewPermission(key) {
    return /^view(_[a-z_]+)?$/.test(String(key).split('.').pop());
}

/**
 * Where a role stands on a module: off (none of its permissions), view
 * (only its looking permissions), full (every one), or custom.
 */
export function moduleLevel(selected, group) {
    const held = new Set(selected);
    const keys = group.permissions.map((permission) => permission.key);
    const on = keys.filter((key) => held.has(key));
    if (!on.length) return 'off';
    if (on.length === keys.length) return 'full';
    const views = keys.filter(isViewPermission);
    if (views.length && on.length === views.length && on.every(isViewPermission)) return 'view';
    return 'custom';
}

/**
 * Sets a role's level on a module: off, view (looking only; all of them when
 * the module has no looking permission) or full. Permissions this person
 * cannot change stay exactly as they are.
 */
export function setModuleLevel(selected, group, level) {
    const changeable = group.permissions.filter((permission) => !permission.blocked_by).map((permission) => permission.key);
    const views = changeable.filter(isViewPermission);
    const wanted = level === 'full' ? changeable : level === 'view' ? (views.length ? views : changeable) : [];
    const set = new Set(selected);
    changeable.forEach((key) => set.delete(key));
    wanted.forEach((key) => set.add(key));
    return [...set].sort();
}
