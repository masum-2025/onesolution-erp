/**
 * Small helpers of the projects screens. Overdue and counts come from the
 * server (the company's today); these only show them.
 */

export const TASK_STATUSES = ['todo', 'doing', 'blocked', 'done'];

/** Badge tone of a project's status; its word is always shown next to it (never colour alone). */
export function projectTone(status) {
    return { planned: 'neutral', active: 'ok', on_hold: 'warn', done: 'outline', cancelled: 'outline' }[status] ?? 'neutral';
}

export function taskTone(status) {
    return { todo: 'neutral', doing: 'ok', blocked: 'warn', done: 'outline' }[status] ?? 'neutral';
}

/** Whole per cent of tasks done (0 when there are none). */
export function progress(tasks) {
    if (!tasks?.total) return 0;
    return Math.floor((tasks.done * 100) / tasks.total);
}

/** Tasks by status, each column in the project's order. */
export function columns(tasks) {
    const byStatus = Object.fromEntries(TASK_STATUSES.map((status) => [status, []]));
    for (const task of [...tasks].sort((a, b) => a.sort_order - b.sort_order)) (byStatus[task.status] ??= []).push(task);
    return byStatus;
}

/** Up to two letters of a person's name, for the small round marks. */
export function initials(name) {
    const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts.length === 1 ? parts[0].slice(0, 2) : parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/**
 * My tasks in groups: overdue, due within a week of today, later, and with no due day.
 * Days are plain "YYYY-MM-DD" (the company's), compared as text.
 */
export function groupMine(tasks, today) {
    const week = new Date(`${today}T00:00:00Z`);
    week.setUTCDate(week.getUTCDate() + 7);
    const weekEnd = week.toISOString().slice(0, 10);
    const groups = { overdue: [], week: [], later: [], none: [] };
    for (const task of tasks) {
        if (!task.due_on) groups.none.push(task);
        else if (task.due_on < today && task.status !== 'done') groups.overdue.push(task);
        else if (task.due_on <= weekEnd) groups.week.push(task);
        else groups.later.push(task);
    }
    return groups;
}
