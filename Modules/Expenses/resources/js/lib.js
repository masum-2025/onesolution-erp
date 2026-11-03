import { i18n, t } from '@/lib/i18n';
import { formatMoney, formatNumber, localeTag } from '@/lib/format';
import { amountToMinor, cleanNumber, minorToText } from '@/lib/numbers';
import { newOpId } from '@/lib/billing';

// Shared helpers, passed on so this module's screens keep importing them from here.
export { amountToMinor, cleanNumber, minorToText, newOpId };

/**
 * Expenses helpers for the screens: typed amounts as integer minor units
 * and kilometres as tenths (string maths, Bangla digits understood), the
 * mileage worked out exactly as the server does (to preview; the server
 * works it out again), and what each status looks like.
 */

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


/** Export columns of a list of claims (what ClaimRows shows). */
export function claimColumns() {
    return [
        { key: 'number', label: t('expenses.export.number') },
        { key: 'code', label: t('expenses.export.code'), value: (item) => item.employee?.code ?? '' },
        { key: 'employee', label: t('expenses.export.employee'), value: (item) => item.employee?.name ?? '' },
        { key: 'title', label: t('expenses.export.title') },
        { key: 'submitted_at', label: t('expenses.export.submitted_on'), type: 'date', value: (item) => (item.submitted_at ? dayOf(new Date(item.submitted_at)) : null) },
        { key: 'line_count', label: t('expenses.export.lines'), type: 'number' },
        { key: 'total_minor', label: t('expenses.export.total'), type: 'money' },
        { key: 'status', label: t('expenses.export.status'), value: (item) => t(`expenses.status.${item.status}`) },
    ];
}

/** Export columns of a list of advances. */
export function advanceColumns() {
    return [
        { key: 'number', label: t('expenses.export.number') },
        { key: 'code', label: t('expenses.export.code'), value: (item) => item.employee?.code ?? '' },
        { key: 'employee', label: t('expenses.export.employee'), value: (item) => item.employee?.name ?? '' },
        { key: 'purpose', label: t('expenses.advance.purpose') },
        { key: 'amount_minor', label: t('expenses.export.total'), type: 'money' },
        { key: 'outstanding_minor', label: t('expenses.export.left'), type: 'money' },
        { key: 'settle_by', label: t('expenses.advance.settle_by'), type: 'date' },
        { key: 'status', label: t('expenses.export.status'), value: (item) => t(`expenses.advance_status.${item.status}`) },
    ];
}
