/**
 * Small helpers of the course registration screens, kept free of
 * components so they can be tested. Credits are hundredths (300 = 3).
 */

/** Hundredths of a credit as text ("300" -> "3", "150" -> "1.5", "1525" -> "15.25"). */
export function credits(centi) {
    const value = Math.abs(Number(centi) || 0);
    const whole = Math.trunc(value / 100);
    const rest = String(value % 100).padStart(2, '0').replace(/0+$/, '');
    return `${centi < 0 ? '-' : ''}${whole}${rest ? `.${rest}` : ''}`;
}

/**
 * Where a registration's credits stand against the rules (all hundredths;
 * 0 means no limit): under the minimum, fine, an overload, or over what
 * is allowed at all. The bar's percent is of the most allowed.
 */
export function creditState(centi, rules) {
    const min = rules?.min_credits_centi ?? 0;
    const max = rules?.max_credits_centi ?? 0;
    const limit = max > 0 ? max + (rules?.overload_credits_centi ?? 0) : 0;
    const state = max > 0 && centi > limit ? 'over' : max > 0 && centi > max ? 'overload' : min > 0 && centi < min ? 'under' : 'ok';
    const tone = { over: 'bad', overload: 'warn', under: 'warn', ok: 'ok' }[state];
    const scale = limit || Math.max(centi, min, 1);
    return { state, tone, min, max, limit, percent: Math.min(100, Math.round((centi * 100) / scale)), maxPercent: limit ? Math.round((max * 100) / limit) : null };
}

/**
 * The registration window of a session on a day: none set, not open yet,
 * open, open only for adding and dropping, or closed.
 */
export function windowState(window, today) {
    if (!window) return 'none';
    if (today < window.opens_on) return 'upcoming';
    if (today <= window.closes_on) return 'open';
    if (today <= window.add_drop_until) return 'add_drop';
    return 'closed';
}

/** Seats of an offering: taken, waiting, left and a tone with a word (never colour alone). */
export function seats(offering) {
    const taken = offering.taken ?? 0;
    const capacity = offering.capacity ?? 0;
    const left = Math.max(0, capacity - taken);
    const state = left === 0 ? 'full' : left <= Math.max(1, Math.round(capacity * 0.1)) ? 'few' : 'open';
    return { taken, capacity, left, waiting: offering.waiting ?? 0, state, tone: { full: 'bad', few: 'warn', open: 'ok' }[state], percent: capacity ? Math.min(100, Math.round((taken * 100) / capacity)) : 0 };
}

/** Status of a registration -> badge tone. */
export function registrationTone(status) {
    return { draft: 'neutral', submitted: 'warn', approved: 'ok', returned: 'bad' }[status] ?? 'neutral';
}

/** Status of a subject on a registration -> badge tone. */
export function itemTone(status) {
    return { registered: 'ok', waitlisted: 'warn', dropped: 'neutral', withdrawn: 'outline' }[status] ?? 'neutral';
}

/** Outcome of a subject -> badge tone. */
export function outcomeTone(outcome) {
    return { completed: 'ok', failed: 'bad', incomplete: 'warn', withdrawn: 'outline' }[outcome] ?? 'neutral';
}

/**
 * Offerings grouped by class (no class: "other"), each group's subjects in
 * code order and groups (A, B…) together.
 */
export function byLevel(offerings, rank = () => 0) {
    const groups = new Map();
    for (const offering of offerings) {
        const key = offering.level_id ?? '';
        if (!groups.has(key)) groups.set(key, { level_id: offering.level_id ?? null, offerings: [] });
        groups.get(key).offerings.push(offering);
    }
    for (const group of groups.values()) {
        group.offerings.sort((a, b) => (a.subject?.code ?? '').localeCompare(b.subject?.code ?? '') || a.group_name.localeCompare(b.group_name));
    }
    return [...groups.values()].sort((a, b) => (a.level_id === null) - (b.level_id === null) || rank(a.level_id) - rank(b.level_id));
}

/** A fresh id for a write that is safe to send twice. */
export function newOpId() {
    return globalThis.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

/**
 * Why a student cannot change their registration in the portal now (null:
 * they can): a parent only looks, self-registration is off, no window, not
 * open yet, or closed (dropping may still be allowed).
 */
export function portalBlock(view) {
    if (!view) return null;
    if (!view.own) return 'look_only';
    if (view.can?.add) return null;
    if (!view.rules?.self_registration) return 'self_off';
    const state = windowState(view.window, view.today);
    return state === 'open' ? 'closed' : state;
}

/** What tapping an offered subject does in the portal: add, join the waiting list, or nothing (with why). */
export function offeringAction(offering, canAdd) {
    if (offering.reason) return { kind: 'blocked', reason: offering.reason };
    if (!canAdd) return { kind: 'blocked', reason: null };
    return { kind: offering.waitlist ? 'waitlist' : 'add', reason: null };
}
