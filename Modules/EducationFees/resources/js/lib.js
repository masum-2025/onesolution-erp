import { currencyDigits, decimalStringToMinor, minorToDecimalString } from '@/lib/format';

/**
 * Small helpers of the fee screens, kept free of components so they can be
 * tested. Money is integer minor units; typed amounts are read as text
 * (never floats). The server checks everything again.
 */

const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯';

/** "১,২৫০.৫০" or "৳ 1,250.50" -> "1250.50": Bangla digits to ASCII, separators, spaces and the sign removed. */
export function normalizeAmountText(text) {
    return Array.from(String(text ?? ''))
        .map((char) => {
            const index = BANGLA_DIGITS.indexOf(char);
            return index >= 0 ? String(index) : char;
        })
        .join('')
        .replace(/[\s,_']/g, '')
        .replace(/^\p{Sc}/u, '');
}

/** A typed amount in minor units, or null when it is not a valid amount above zero. */
export function parseAmount(text, currency) {
    const clean = normalizeAmountText(text);
    if (clean === '' || clean.startsWith('-')) return null;
    const minor = decimalStringToMinor(clean, currencyDigits(currency));
    return minor === null || minor <= 0 ? null : minor;
}

/** Minor units as the text an amount field shows ("1250.50"; empty for 0). */
export function amountText(minor, currency) {
    if (!minor) return '';
    return minorToDecimalString(minor, currencyDigits(currency));
}

/**
 * Where an amount goes, as the server will do it: open bills oldest due
 * first; without part payments a bill is met in full or not at all (and
 * nothing is taken when the first bill cannot be). The rest is an advance.
 *
 * @returns {{ bills: Array<{ id: string, number: string, amount: number, full: boolean }>, advance: number, blocked: string|null }}
 */
export function plan(bills, amount, partial = true) {
    const open = [...bills].filter((bill) => bill.status === 'open' && bill.balance_minor > 0)
        .sort((a, b) => a.due_date.localeCompare(b.due_date) || String(a.number).localeCompare(String(b.number)));
    const result = { bills: [], advance: 0, blocked: null };
    let left = amount;
    for (const bill of open) {
        if (left <= 0) break;
        if (!partial && left < bill.balance_minor) {
            if (!result.bills.length) result.blocked = bill.number;
            break;
        }
        const part = Math.min(left, bill.balance_minor);
        result.bills.push({ id: bill.id, number: bill.number, amount: part, full: part === bill.balance_minor });
        left -= part;
    }
    if (!result.blocked) result.advance = left;
    return result;
}

/**
 * What a bill is for, from its key: a month ("monthly:2026-03"), a
 * session, admission, credits or other heads.
 *
 * @returns {{ kind: string, month: string|null }}
 */
export function billKind(billingKey) {
    const [kind, value] = String(billingKey ?? '').split(':');
    return { kind: ['monthly', 'session', 'admission', 'credits', 'other'].includes(kind) ? kind : 'other', month: kind === 'monthly' ? value ?? null : null };
}

/** A bill's state for a badge: paid, overdue, open, cancelled (a word goes with the tone). */
export function billState(bill) {
    if (bill.status === 'cancelled') return { key: 'cancelled', tone: 'outline' };
    if (bill.status === 'paid') return { key: 'paid', tone: 'ok' };
    if (bill.overdue) return { key: 'overdue', tone: 'bad' };
    if (bill.paid_minor > 0) return { key: 'part_paid', tone: 'warn' };
    return { key: 'open', tone: 'brand' };
}

/** Status of a request (discount, void, refund) -> badge tone. */
export function requestTone(status) {
    return { pending: 'warn', active: 'ok', approved: 'ok', rejected: 'bad', ended: 'outline' }[status] ?? 'neutral';
}

/** A percent in basis points as text: 1500 -> "15", 1250 -> "12.5". */
export function percentText(bp) {
    const whole = Math.trunc((bp ?? 0) / 100);
    const rest = String((bp ?? 0) % 100).padStart(2, '0').replace(/0+$/, '');
    return rest ? `${whole}.${rest}` : String(whole);
}

/** A typed percent ("12.5", "১৫") in basis points, or null outside 0–100. */
export function parsePercent(text) {
    const clean = normalizeAmountText(text).replace(/%$/, '');
    if (!/^\d{1,3}(\.\d{1,2})?$/.test(clean)) return null;
    const [whole, part = ''] = clean.split('.');
    const bp = Number(whole) * 100 + Number(part.padEnd(2, '0'));
    return bp > 0 && bp <= 10000 ? bp : null;
}

/** A fresh id for one tap (the server keeps the first and answers the same to a repeat). */
export function newOpId() {
    return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}
