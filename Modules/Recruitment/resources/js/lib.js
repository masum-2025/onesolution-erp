import { formatMoney } from '@/lib/format';
import { amountToMinor, cleanNumber, minorToText } from '@/lib/numbers';
import { newOpId } from '@/lib/billing';

// Shared helpers, passed on so this module's screens keep importing them from here.
export { amountToMinor, cleanNumber, minorToText, newOpId };

/**
 * Recruitment helpers for the screens: typed salaries as integer minor
 * units (string maths, Bangla digits understood), applications sorted
 * into the company's stages for the board, and what each status looks
 * like. The server checks and works out everything again.
 */

/** "৳25,000.00 – ৳35,000.00", one end alone, or '' when neither. */
export function salaryText(opening) {
    const one = (minor) => (minor === null || minor === undefined ? null : formatMoney({ amount: minor, currency: opening.currency }));
    return [one(opening.salary_min_minor), one(opening.salary_max_minor)].filter(Boolean).join(' – ');
}

/** Applications by stage key, every stage present (in the stages' order). */
export function byStage(applications, stages) {
    const columns = Object.fromEntries(stages.map((stage) => [stage.key, []]));
    for (const application of applications) {
        (columns[application.stage] ??= []).push(application);
    }
    return columns;
}

/** Badge tone per opening status (with its word next to it, never colour alone). */
export function openingTone(status) {
    return { draft: 'neutral', open: 'ok', closed: 'warn', filled: 'brand' }[status] ?? 'neutral';
}

/** Badge tone per application status. */
export function applicationTone(status) {
    return { active: 'brand', hired: 'ok', rejected: 'bad', withdrawn: 'neutral' }[status] ?? 'neutral';
}

/** Badge tone per offer status. */
export function offerTone(status) {
    return { draft: 'neutral', pending_approval: 'warn', sent: 'brand', accepted: 'ok', declined: 'bad', expired: 'neutral', withdrawn: 'neutral' }[status] ?? 'neutral';
}

/** Badge tone per recommendation. */
export function recommendationTone(value) {
    return { yes: 'ok', maybe: 'warn', no: 'bad' }[value] ?? 'neutral';
}

/** The name in the reader's language, else English, else any. */
export function nameIn(texts, locale) {
    if (!texts) return '';
    return texts[locale] || texts.en || Object.values(texts).find(Boolean) || '';
}

/** "YYYY-MM-DD" some days from now (today by default), in the reader's clock, not UTC. */
export function dayOf(days = 0, now = new Date()) {
    const day = new Date(now.getFullYear(), now.getMonth(), now.getDate() + days);
    return `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
}

/** "2026-10-30T10:30" for a datetime input, a day ahead at 10:00 by default. */
export function nextSlot(now = new Date()) {
    return `${dayOf(1, now)}T10:00`;
}

