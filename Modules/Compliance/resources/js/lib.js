/**
 * Small helpers of the compliance screens. Days left come from the server
 * (worked out from the expiry day and the company's today); these only show them.
 */

export const STATES = ['expired', 'expiring', 'valid', 'unknown'];

/** Badge tone of a state; its word is always shown next to it (never colour alone). */
export function stateTone(state) {
    return { valid: 'ok', expiring: 'warn', expired: 'bad', unknown: 'neutral' }[state] ?? 'neutral';
}

/** "YYYY-MM-DD" plus whole months (end of month kept: 31 Jan + 1 month = 28/29 Feb), minus one day: a certificate's last day. */
export function expiryFrom(lastAuditOn, months) {
    if (!lastAuditOn || !months) return '';
    const [year, month, day] = lastAuditOn.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + months, 1));
    const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    const result = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth(), Math.min(day, lastDay)));
    result.setUTCDate(result.getUTCDate() - 1);
    return result.toISOString().slice(0, 10);
}

/**
 * The grid of the register: one row per factory (in tree order), one column
 * per service that has a line or is chosen, each cell the line or null.
 */
export function grid(lines, factories, services, keepEmptyRows = true) {
    const used = new Set(lines.map((line) => line.service?.id));
    const columns = services.filter((service) => used.has(service.id));
    const byKey = new Map(lines.map((line) => [`${line.factory.id}|${line.service?.id}`, line]));
    const rows = factories.map((factory) => ({
        factory,
        cells: columns.map((service) => byKey.get(`${factory.id}|${service.id}`) ?? null),
    })).filter((row) => keepEmptyRows || row.cells.some(Boolean));
    return { columns, rows };
}

/** How many of each state, from the register's summary (a missing state is 0). */
export function counts(summary) {
    return Object.fromEntries(STATES.map((state) => [state, summary?.[state] ?? 0]));
}
