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
