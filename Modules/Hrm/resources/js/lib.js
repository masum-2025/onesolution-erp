/**
 * Small HRM helpers for the screens (tested in tests/js/hrm.test.js). The
 * server decides again; these give instant feedback while typing.
 */

/** Badge tone of an employment status. */
export function statusTone(status) {
    return { probation: 'warn', active: 'ok', on_notice: 'brand', exited: 'neutral' }[status] ?? 'neutral';
}

/** Initials for the avatar: first letters of the first two words. */
export function initials(name) {
    return (name ?? '')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => Array.from(word)[0] ?? '')
        .join('')
        .toUpperCase();
}

/** Details the rules require that are still empty in the form. */
export function missingRequired(form, required) {
    return (required ?? []).filter((field) => {
        const value = form[field];
        if (value === null || value === undefined) return true;
        if (typeof value === 'string') return value.trim() === '';
        if (typeof value === 'object') return Object.values(value).every((part) => !part || String(part).trim() === '');
        return false;
    });
}

/** "2026-10-01" + 90 days -> "2026-12-30" (calendar days, in UTC like the server). */
export function addDays(date, days) {
    if (!date || !Number.isFinite(days)) return null;
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);
    return value.toISOString().slice(0, 10);
}

/**
 * The hire request body: empty optional details are left out, empty
 * address / emergency contact parts too.
 */
export function hirePayload(form) {
    const body = {};
    for (const [key, value] of Object.entries(form)) {
        if (value === null || value === undefined) continue;
        if (typeof value === 'string' && value.trim() === '') continue;
        if (typeof value === 'object') {
            const parts = Object.fromEntries(Object.entries(value).filter(([, part]) => part && String(part).trim() !== ''));
            if (Object.keys(parts).length) body[key] = parts;
            continue;
        }
        body[key] = typeof value === 'string' ? value.trim() : value;
    }
    return body;
}

/** Only the details that changed, for a PATCH with the version seen. */
export function changedDetails(original, form) {
    const changes = {};
    for (const [key, value] of Object.entries(form)) {
        const before = original[key] ?? null;
        const after = typeof value === 'string' ? value.trim() || null : value;
        if (JSON.stringify(before) !== JSON.stringify(after)) changes[key] = after;
    }
    return changes;
}

/** Which steps can be taken now, and which permission each needs. */
export function availableSteps(employee, can) {
    if (!employee) return [];
    const status = employee.status;
    const steps = [];
    if (can.manage && status === 'probation') steps.push('confirm');
    if (can.manage && status !== 'exited') steps.push('transfer', 'promote');
    if (can.exit && ['probation', 'active'].includes(status)) steps.push('notice');
    if (can.exit && status !== 'exited') steps.push('exit');
    if (can.manage && status === 'exited') steps.push('rehire');
    return steps;
}
