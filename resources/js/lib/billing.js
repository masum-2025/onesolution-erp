import { currencyDigits, decimalStringToMinor, minorToDecimalString } from './format';

/*
 * Billing helpers. Amounts stay integer minor units end to end; text typed
 * by a person ("7000.50") is converted with string math, never floats.
 */

/** "7000.50" in BDT -> 700050; null when it is not a valid, non-negative amount. */
export function amountToMinor(text, currency) {
    const minor = decimalStringToMinor(String(text ?? '').replace(/,/g, ''), currencyDigits(currency));
    return minor === null || minor < 0 ? null : minor;
}

/** 700050 in BDT -> "7000.50" (for an input field). */
export function minorToAmount(minor, currency) {
    return minor === null || minor === undefined ? '' : minorToDecimalString(minor, currencyDigits(currency));
}

/**
 * What the partner keeps from one client per month: its monthly price in a
 * currency minus our wholesale price per client in that same currency. null
 * when the two cannot be compared (other currency, per-seat price, no price).
 */
export function monthlyMargin(prices, cost) {
    if (!cost || cost.kind !== 'wholesale' || cost.unit !== 'per_client' || cost.amount_minor === null) return null;
    const monthly = prices.find((price) => price.currency === cost.currency && price.period === 'monthly');
    return monthly ? { currency: cost.currency, amount: monthly.amount_minor - cost.amount_minor } : null;
}

/** Badge tone for an invoice, credit note or commission status. */
export function statusTone(status) {
    return { issued: 'brand', overdue: 'bad', paid: 'ok', credited: 'neutral', pending: 'warn', payable: 'brand' }[status] ?? 'neutral';
}

/* ── Self-serve billing (Phase 5C-2) ─────────────────────────────────── */

/**
 * A fresh idempotency key for one click: sent with the checkout, so a
 * double click or a retry after a lost answer never starts a second payment.
 */
export function newOpId() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID().replace(/-/g, '');
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** What paying yearly saves against twelve monthly payments; null when it saves nothing. */
export function yearlySaving(prices) {
    if (!Number.isInteger(prices?.monthly) || !Number.isInteger(prices?.yearly)) return null;
    const saving = prices.monthly * 12 - prices.yearly;
    return saving > 0 ? saving : null;
}

/** Badge tone for where a self-serve account stands. */
export function standingTone(status) {
    return { free: 'neutral', trial: 'brand', active: 'ok', past_due: 'warn', read_only: 'bad' }[status] ?? 'neutral';
}

/** A payment the gateway has settled one way or another (the status page stops asking). */
export function paymentSettled(status) {
    return status !== 'pending';
}

/** Whole days from now until an ISO time, never negative. */
export function daysUntil(iso, now = Date.now()) {
    if (!iso) return null;
    return Math.max(0, Math.ceil((new Date(iso).getTime() - now) / 86400000));
}
