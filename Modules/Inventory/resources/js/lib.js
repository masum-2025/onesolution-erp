import { currencyDigits, decimalStringToMinor, formatNumber, minorToDecimalString } from '@/lib/format';

/**
 * Inventory helpers for the screens: quantities typed as text become
 * integer thousandths (string maths, Bangla digits understood, no more
 * decimals than the unit allows), money becomes minor units. The server
 * works out every value; nothing here values stock.
 */

const BANGLA = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' };

/** "১,২৫০.৫" -> "1250.5" */
export function cleanNumber(text) {
    return String(text ?? '')
        .replace(/[০-৯]/g, (digit) => BANGLA[digit])
        .replace(/[,\s ]/g, '')
        .trim();
}

/** Typed quantity -> thousandths; null when not a number or with more decimals than the unit allows. */
export function quantityToMilli(text, decimals = 3, { signed = false } = {}) {
    const clean = cleanNumber(text);
    if (clean === '' || (!signed && clean.startsWith('-'))) return null;
    const match = clean.match(/^-?\d+(?:\.(\d+))?$/);
    if (!match || (match[1] ?? '').length > decimals) return null;
    return decimalStringToMinor(clean, 3);
}

/** Thousandths -> the unit's text ("12.5"), trailing zeros dropped. */
export function milliToText(milli) {
    if (milli === null || milli === undefined) return '';
    const text = minorToDecimalString(milli, 3);
    return text.replace(/\.?0+$/, '');
}

/** Thousandths in the reader's digits ("১২.৫"). */
export function formatQuantity(milli) {
    if (milli === null || milli === undefined) return '—';
    return milliToText(milli).replace(/\d/g, (digit) => formatNumber(Number(digit)));
}

/** Typed money -> minor units of the currency; null when not an amount at or above zero. */
export function amountToMinor(text, currency) {
    const clean = cleanNumber(text);
    if (clean === '' || clean.startsWith('-')) return null;
    return decimalStringToMinor(clean, currencyDigits(currency));
}

/** Minor units -> the text of an amount field. */
export function minorToText(minor, currency) {
    return minor === null || minor === undefined ? '' : minorToDecimalString(minor, currencyDigits(currency));
}

/** Tone of a document's or count's status badge. */
export function statusTone(status) {
    return { draft: 'neutral', counting: 'neutral', pending_approval: 'warn', in_transit: 'brand', posted: 'ok', cancelled: 'neutral' }[status] ?? 'neutral';
}

/** Tone of a stock level against the item's reorder level (low when at or below it). */
export function levelTone(quantityMilli, reorderMilli) {
    if (quantityMilli < 0) return 'bad';
    if (reorderMilli === null || reorderMilli === undefined) return 'neutral';
    return quantityMilli <= reorderMilli ? 'warn' : 'ok';
}

/** The kinds of document. */
export const DOCUMENT_TYPES = ['receipt', 'issue', 'transfer', 'adjustment'];

/** A new line for a document form. */
export function emptyLine() {
    return { item_id: '', quantity: '', unit_cost: '', batch_number: '', expires_on: '' };
}

/**
 * A form line -> the API's line: quantity in thousandths, cost in minor
 * units. Returns [line, null] or [null, what to fix].
 */
export function lineToApi(line, { type, decimals, currency, tracksBatches }) {
    if (!line.item_id) return [null, 'item'];
    const quantity = quantityToMilli(line.quantity, decimals, { signed: type === 'adjustment' });
    if (quantity === null || quantity === 0) return [null, 'quantity'];
    const out = { item_id: line.item_id, quantity_milli: quantity };
    const comingIn = type === 'receipt' || (type === 'adjustment' && quantity > 0);
    if (type === 'receipt' || (comingIn && String(line.unit_cost ?? '').trim() !== '')) {
        const cost = amountToMinor(line.unit_cost, currency);
        if (cost === null) return [null, 'unit_cost'];
        out.unit_cost_minor = cost;
    }
    if (tracksBatches && comingIn && !String(line.batch_number ?? '').trim()) return [null, 'batch'];
    if (String(line.batch_number ?? '').trim()) out.batch_number = line.batch_number.trim();
    if (line.expires_on) out.expires_on = line.expires_on;
    return [out, null];
}
