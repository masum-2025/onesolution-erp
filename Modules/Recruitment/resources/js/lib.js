import { currencyDigits, decimalStringToMinor, formatMoney, minorToDecimalString } from '@/lib/format';

/**
 * Recruitment helpers for the screens: typed salaries as integer minor
 * units (string maths, Bangla digits understood), applications sorted
 * into the company's stages for the board, and what each status looks
 * like. The server checks and works out everything again.
 */

const BANGLA = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' };

/** "১,২৫০.৫" -> "1250.5" */
export function cleanNumber(text) {
    return String(text ?? '').trim().replace(/[০-৯]/g, (digit) => BANGLA[digit]).replace(/[,\s]/g, '');
}

/** Typed money -> minor units; null when empty or not an amount at or above zero. */
export function amountToMinor(text, currency) {
    const clean = cleanNumber(text);
    if (clean === '' || !/^\d+(\.\d+)?$/.test(clean)) return null;
    return decimalStringToMinor(clean, currencyDigits(currency));
}

export function minorToText(minor, currency) {
    return minor === null || minor === undefined ? '' : minorToDecimalString(minor, currencyDigits(currency));
}

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

/** Badge tone per recommendation. */
export function recommendationTone(value) {
    return { yes: 'ok', maybe: 'warn', no: 'bad' }[value] ?? 'neutral';
}

/** The name in the reader's language, else English, else any. */
export function nameIn(texts, locale) {
    if (!texts) return '';
    return texts[locale] || texts.en || Object.values(texts).find(Boolean) || '';
}

/** "2026-10-30T10:30" for a datetime input, a day ahead at 10:00 by default. */
export function nextSlot(now = new Date()) {
    const day = new Date(now.getTime() + 24 * 3600 * 1000);
    return `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}T10:00`;
}

/** A new id for one sending (the same id again is the same application). */
export function newOpId() {
    return `recruitment-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
