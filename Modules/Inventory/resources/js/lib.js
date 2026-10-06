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

/**
 * A CSV file of rows (first row the headings), safe to open in a
 * spreadsheet: a cell that could start a formula is kept as text.
 */
export function toCsv(rows) {
    const cell = (value) => {
        let text = value === null || value === undefined ? '' : String(value);
        if (/^[=+\-@\t\r]/.test(text) && !/^-?\d+(\.\d+)?$/.test(text)) text = `'${text}`;
        return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    };
    return `﻿${rows.map((row) => row.map(cell).join(',')).join('\r\n')}\r\n`;
}

/** Hands the reader a text file to save (no server round trip). */
export function downloadText(name, text, type = 'text/csv;charset=utf-8') {
    const url = URL.createObjectURL(new Blob([text], { type }));
    const link = Object.assign(document.createElement('a'), { href: url, download: name });
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Thousandths -> "12.5" for a CSV (plain digits, no grouping). */
export function milliForCsv(milli) {
    return milli === null || milli === undefined ? '' : milliToText(milli);
}

// Barcodes for labels, drawn as SVG on the page: EAN-13 for 13-digit
// barcodes, Code 128 (set B) for anything else (SKUs). Each returns the
// widths of alternating bars and spaces, starting with a bar.

const EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
const EAN_G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

/** Whether thirteen digits carry a right EAN-13 check digit. */
export function ean13Valid(code) {
    if (!/^\d{13}$/.test(code)) return false;
    const sum = [...code.slice(0, 12)].reduce((total, digit, index) => total + Number(digit) * (index % 2 === 0 ? 1 : 3), 0);
    return (10 - (sum % 10)) % 10 === Number(code[12]);
}

/** EAN-13 as 95 modules ("1" bar, "0" space). */
export function ean13Modules(code) {
    const parity = EAN_PARITY[Number(code[0])];
    let modules = '101';
    for (let index = 1; index <= 6; index++) modules += (parity[index - 1] === 'L' ? EAN_L : EAN_G)[Number(code[index])];
    modules += '01010';
    for (let index = 7; index <= 12; index++) modules += [...EAN_L[Number(code[index])]].map((bit) => (bit === '1' ? '0' : '1')).join('');
    return `${modules}101`;
}

const CODE128 = [
    '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
    '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
    '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
    '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
    '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
    '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
    '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];
export const CODE128_TABLE = CODE128;

/** Code 128 set B as modules; null when a character is outside printable ASCII. */
export function code128Modules(text) {
    const values = [...text].map((char) => char.charCodeAt(0) - 32);
    if (!values.length || values.some((value) => value < 0 || value > 94)) return null;
    const check = values.reduce((sum, value, index) => sum + value * (index + 1), 104) % 103;
    return [104, ...values, check, 106].map((value) => [...CODE128[value]].map((width, index) => (index % 2 === 0 ? '1' : '0').repeat(Number(width))).join('')).join('');
}

/** The modules of an item's barcode (EAN-13 when valid, else Code 128 of the barcode or SKU). */
export function barcodeModules(code) {
    return ean13Valid(code) ? ean13Modules(code) : code128Modules(code);
}
