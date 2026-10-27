import { i18n } from '@/lib/i18n';
import { currencyDigits, decimalStringToMinor, formatMoney, formatNumber, localeTag, minorToDecimalString } from '@/lib/format';

/**
 * Expenses helpers for the screens: typed amounts as integer minor units
 * and kilometres as tenths (string maths, Bangla digits understood), the
 * mileage worked out exactly as the server does (to preview; the server
 * works it out again), and what each status looks like.
 */

const BANGLA = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' };

/** "১,২৫০.৫" -> "1250.5" */
export function cleanNumber(text) {
    return String(text ?? '').trim().replace(/[০-৯]/g, (digit) => BANGLA[digit]).replace(/[,\s]/g, '');
}

/** Typed money -> minor units; null when not an amount at or above zero. */
export function amountToMinor(text, currency) {
    const clean = cleanNumber(text);
    if (clean === '' || !/^\d+(\.\d+)?$/.test(clean)) return null;
    return decimalStringToMinor(clean, currencyDigits(currency));
}

export function minorToText(minor, currency) {
    return minor === null || minor === undefined ? '' : minorToDecimalString(minor, currencyDigits(currency));
}

/** 64000 BDT -> "৳640.00" in the reader's language. */
export function amountText(minor, currency) {
    return formatMoney({ amount: minor ?? 0, currency });
}

/** "12.5" km -> 125 tenths; null when not a distance above zero with at most one decimal. */
export function kmToTenths(text) {
    const clean = cleanNumber(text);
    const match = /^(\d{1,6})(?:\.(\d))?$/.exec(clean);
    if (!match) return null;
    const tenths = Number(match[1]) * 10 + Number(match[2] ?? 0);
    return tenths > 0 ? tenths : null;
}

/** 125 -> "12.5" (for an input). */
export function tenthsToKm(tenths) {
    return tenths === null || tenths === undefined ? '' : String(tenths / 10);
}

/** 125 -> "12.5" in the reader's digits. */
export function kmText(tenths) {
    return formatNumber((Number(tenths) || 0) / 10);
}

/** Kilometres (tenths) at a rate per km (minor units), rounded half up, as the server does. */
export function mileageAmount(tenths, rate) {
    if (!tenths || !rate) return 0;
    return Math.floor((tenths * rate + 5) / 10);
}

/** Whether a line needs a receipt before the claim can be sent (mileage never does). */
export function needsReceipt(line, limitMinor) {
    return !line.mileage && (line.amount_minor ?? 0) > limitMinor;
}

/** Badge tone per claim status (with its word next to it, never colour alone). */
export function statusTone(status) {
    return { draft: 'neutral', submitted: 'warn', approved: 'brand', rejected: 'bad', paid: 'ok', withdrawn: 'neutral' }[status] ?? 'neutral';
}

/** "YYYY-MM" of a date (today by default), in the reader's clock. */
export function monthOf(date = new Date()) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

/** "YYYY-MM-DD" of a date (today by default), in the reader's clock: not UTC, which is yesterday before 6 am in Dhaka. */
export function dayOf(date = new Date()) {
    return `${monthOf(date)}-${String(date.getDate()).padStart(2, '0')}`;
}

/** "2026-10", 3 -> ["2026-10", "2026-11", "2026-12"] */
export function monthsFrom(month, count) {
    const [year, number] = month.split('-').map(Number);
    return Array.from({ length: count }, (_, index) => {
        const date = new Date(Date.UTC(year, number - 1 + index, 1));
        return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
    });
}

/** "2026-10" -> "October 2026" in the reader's language. */
export function monthText(month) {
    return new Intl.DateTimeFormat(localeTag(), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${month}-01T00:00:00Z`));
}

/** A name from the rules ({ en, bn }) in the reader's language, else English. */
export function nameIn(texts, locale = i18n.locale) {
    if (!texts) return '';
    return texts[locale] || texts.en || Object.values(texts).find(Boolean) || '';
}

/** Badge tone per advance status. */
export function advanceTone(status) {
    return { submitted: 'warn', approved: 'brand', rejected: 'bad', paid: 'brand', settled: 'ok', withdrawn: 'neutral' }[status] ?? 'neutral';
}

/** A new id for one sending of a claim (the same id again is the same claim). */
export function newOpId() {
    return `expenses-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
