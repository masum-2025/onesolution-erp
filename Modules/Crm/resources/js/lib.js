import { currencyDigits, decimalStringToMinor, formatNumber, minorToDecimalString } from '@/lib/format';

/**
 * CRM helpers for the screens: typed amounts and quantities as integer
 * minor units and thousandths (string maths, Bangla digits understood),
 * quote lines priced exactly as the server does (to preview; the server
 * prices again), extra-field values to and from inputs, and a small CSV
 * reader for imports.
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

/** "2.5" -> 2500 thousandths; null when not a quantity above zero with at most 3 decimals. */
export function quantityToMilli(text) {
    const clean = cleanNumber(text);
    const match = /^(\d{1,9})(?:\.(\d{1,3}))?$/.exec(clean);
    if (!match) return null;
    const milli = Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'));
    return milli > 0 ? milli : null;
}

/** 2500 -> "2.5" */
export function milliToText(milli) {
    if (milli === null || milli === undefined) return '';
    const whole = Math.trunc(milli / 1000);
    const rest = String(Math.abs(milli) % 1000).padStart(3, '0').replace(/0+$/, '');
    return rest ? `${whole}.${rest}` : String(whole);
}

export function formatQuantity(milli) {
    return milliToText(milli).replace(/\d/g, (digit) => formatNumber(Number(digit)));
}

/** quantity × price, rounded half up, as the server does. */
export function lineAmount(quantityMilli, priceMinor) {
    return Math.floor((quantityMilli * priceMinor + 500) / 1000);
}

/** VAT inside the amount or on top, rounded half up (as Accounting's TaxCodes::split). */
export function splitTax(amount, rateBp, inclusive) {
    if (!rateBp) return { net: amount, tax: 0 };
    if (inclusive) {
        const net = Math.floor((amount * 10000 + Math.floor((10000 + rateBp) / 2)) / (10000 + rateBp));
        return { net, tax: amount - net };
    }
    return { net: amount, tax: Math.floor((amount * rateBp + 5000) / 10000) };
}

/**
 * Lines priced: each {quantity_milli, unit_price_minor, discount_minor, tax_rate_bp}.
 * @returns {{lines: Array<{gross:number, net:number, tax:number, total:number}>, subtotal:number, discount:number, tax:number, total:number}}
 */
export function priceQuote(lines, inclusive) {
    const priced = lines.map((line) => {
        const gross = lineAmount(line.quantity_milli ?? 0, line.unit_price_minor ?? 0);
        const discount = Math.min(line.discount_minor ?? 0, gross);
        const split = splitTax(gross - discount, line.tax_rate_bp ?? 0, inclusive);
        return { gross, discount, net: split.net, tax: split.tax, total: split.net + split.tax };
    });
    const sum = (key) => priced.reduce((total, line) => total + line[key], 0);
    return { lines: priced, subtotal: sum('gross'), discount: sum('discount'), tax: sum('tax'), total: sum('total') };
}

/** An extra field's stored value as the text of its input. */
export function fieldToInput(field, value, currency) {
    if (value === null || value === undefined) return field.type === 'yes_no' ? '' : '';
    if (field.type === 'money') return minorToText(value, currency);
    if (field.type === 'yes_no') return value ? 'yes' : 'no';
    return String(value);
}

/**
 * Inputs of extra fields -> what the API takes ({key: value}); money as
 * minor units; empty clears. Returns {values, errors} (errors by key).
 */
export function fieldsToApi(fields, inputs, currency) {
    const values = {};
    const errors = {};
    for (const field of fields) {
        if (!(field.key in inputs)) continue;
        const raw = inputs[field.key];
        const text = typeof raw === 'string' ? raw.trim() : raw;
        if (text === '' || text === null || text === undefined) {
            values[field.key] = null;
            continue;
        }
        if (field.type === 'money') {
            const minor = amountToMinor(text, currency);
            if (minor === null) errors[field.key] = true;
            else values[field.key] = minor;
        } else if (field.type === 'yes_no') {
            values[field.key] = text === 'yes' || text === true;
        } else if (field.type === 'number') {
            values[field.key] = cleanNumber(text);
        } else {
            values[field.key] = text;
        }
    }
    return { values, errors };
}

/** An extra field's value as text to read (choices by their label). */
export function fieldText(field, value, money) {
    if (value === null || value === undefined || value === '') return '—';
    if (field.type === 'money') return money(value);
    if (field.type === 'choice') return field.options.find((option) => option.value === value)?.label ?? value;
    if (field.type === 'yes_no') return value ? '✓' : '✗';
    return String(value);
}

/** Read with the shared CSV reader (kept here for the CRM screens that import it). */
export { readCsv } from '@/lib/csv';

/** Tone of a quote's status badge. */
export function quoteTone(quote) {
    if (quote.expired) return 'warn';
    return { draft: 'neutral', sent: 'brand', accepted: 'ok', declined: 'bad', converted: 'neutral' }[quote.status] ?? 'neutral';
}

/** A phone kept as +8801711000000, shown as 01711-000000 at home and as is elsewhere. */
export function phoneText(phone) {
    if (!phone) return '';
    const bd = /^\+880(\d{4})(\d{6})$/.exec(phone);
    return bd ? `0${bd[1]}-${bd[2]}` : phone;
}
