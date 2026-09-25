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
