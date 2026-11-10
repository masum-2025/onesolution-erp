import { decimalStringToMinor, formatNumber, minorToDecimalString } from '@/lib/format';
import { amountToMinor, cleanNumber, minorToText } from '@/lib/numbers';

// Shared helpers, passed on so this module's screens keep importing them from here.
export { amountToMinor, cleanNumber, minorToText };

/**
 * Inventory helpers for the screens: quantities typed as text become
 * integer thousandths (string maths, Bangla digits understood, no more
 * decimals than the unit allows), money becomes minor units. The server
 * works out every value; nothing here values stock.
 */

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

// CSV saving is shared by the screens.
export { downloadText, milliForCsv, toCsv } from '@/lib/csv';

// Barcodes for labels, drawn as SVG on the page: EAN-13 for 13-digit
// barcodes, Code 128 (set B) for anything else (SKUs). Each returns the
// widths of alternating bars and spaces, starting with a bar.

export { barcodeModules, CODE128_TABLE, code128Modules, ean13Modules, ean13Valid } from '@/lib/barcode';
