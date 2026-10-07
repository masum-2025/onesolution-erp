import { i18n } from './i18n';
import { session } from './session';

/**
 * Locale-aware display of numbers, dates and money for the active
 * organization (language + country; the person's timezone, else the organization's). Money arrives
 * as integer minor units and is converted with string/BigInt math, never floats.
 */
export function localeTag() {
    const country = session.me?.context?.settings?.country_code;
    return country ? `${i18n.locale}-${country}` : i18n.locale;
}

/** The person's own timezone, else the organization's (own, inherited or its country's). */
function timeZone() {
    return session.me?.user?.timezone || session.me?.context?.settings?.timezone || undefined;
}

export function formatNumber(value, options = {}) {
    if (value === null || value === undefined || value === '') return '—';
    return new Intl.NumberFormat(localeTag(), options).format(value);
}

export function formatDate(iso, options = { dateStyle: 'medium' }) {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    try {
        return new Intl.DateTimeFormat(localeTag(), { timeZone: timeZone(), ...options }).format(date);
    } catch {
        return new Intl.DateTimeFormat(localeTag(), options).format(date);
    }
}

export function formatDateTime(iso) {
    return formatDate(iso, { dateStyle: 'medium', timeStyle: 'short' });
}

const RELATIVE_STEPS = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
];

export function formatRelative(iso, now = Date.now()) {
    if (!iso) return '—';
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const rtf = new Intl.RelativeTimeFormat(localeTag(), { numeric: 'auto' });
    for (const [unit, size] of RELATIVE_STEPS) {
        if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
    }
    return rtf.format(0, 'second');
}

export function currencyDigits(currency) {
    try {
        return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits;
    } catch {
        return 2;
    }
}

/** 35000000 (minor) with 2 digits -> "350000.00" */
export function minorToDecimalString(amount, digits) {
    const value = BigInt(amount);
    const negative = value < 0n;
    const abs = negative ? -value : value;
    if (digits === 0) return (negative ? '-' : '') + abs.toString();
    const scale = 10n ** BigInt(digits);
    const whole = abs / scale;
    const fraction = (abs % scale).toString().padStart(digits, '0');
    return `${negative ? '-' : ''}${whole}.${fraction}`;
}

/** "350000.5" with 2 digits -> 35000050; null when the text is not a valid amount. */
export function decimalStringToMinor(text, digits) {
    const match = String(text ?? '')
        .trim()
        .match(/^(-)?(\d+)(?:\.(\d+))?$/);
    if (!match) return null;
    const [, sign, whole, fraction = ''] = match;
    if (fraction.length > digits) return null;
    const minor = BigInt(whole) * 10n ** BigInt(digits) + BigInt(fraction.padEnd(digits, '0') || '0');
    const signed = sign ? -minor : minor;
    if (signed > BigInt(Number.MAX_SAFE_INTEGER) || signed < BigInt(Number.MIN_SAFE_INTEGER)) return null;
    return Number(signed);
}

export function formatMoney(money) {
    if (!money || money.amount === null || money.amount === undefined) return '—';
    const digits = currencyDigits(money.currency);
    // No currency known yet (still loading, or the module is off): the number alone, never "undefined".
    if (!money.currency) return formatDecimal(minorToDecimalString(money.amount, digits));
    try {
        return new Intl.NumberFormat(localeTag(), { style: 'currency', currency: money.currency }).format(
            minorToDecimalString(money.amount, digits),
        );
    } catch {
        return `${minorToDecimalString(money.amount, digits)} ${money.currency}`;
    }
}

/** Decimal strings ("1.5") are shown with locale digits, keeping their precision. */
export function formatDecimal(text) {
    if (text === null || text === undefined || text === '') return '—';
    const fraction = String(text).split('.')[1]?.length ?? 0;
    return new Intl.NumberFormat(localeTag(), {
        minimumFractionDigits: fraction,
        maximumFractionDigits: fraction,
    }).format(text);
}

const DURATION = /^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/;

export function parseDuration(iso) {
    const match = String(iso ?? '').match(DURATION);
    if (!match) return null;
    const [, day = 0, hour = 0, minute = 0, second = 0] = match;
    return { day: Number(day), hour: Number(hour), minute: Number(minute), second: Number(second) };
}

export function formatDuration(iso) {
    const parts = parseDuration(iso);
    if (!parts) return iso ?? '—';
    const tag = localeTag();
    const unit = (name, value) => new Intl.NumberFormat(tag, { style: 'unit', unit: name, unitDisplay: 'long' }).format(value);
    const pieces = Object.entries(parts)
        .filter(([, value]) => value > 0)
        .map(([name, value]) => unit(name, value));
    if (pieces.length === 0) return unit('minute', 0);
    return new Intl.ListFormat(tag, { style: 'narrow', type: 'unit' }).format(pieces);
}
