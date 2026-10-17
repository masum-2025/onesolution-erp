import { currencyDigits, decimalStringToMinor, formatDate, formatDecimal, formatMoney, minorToDecimalString } from './format';
import { textIn } from './texts';

/**
 * Fields of modules made by the generator (make:erp-module): a stored value
 * to the text of its input, typed input back to what the API takes (or
 * an error key), and a value as a list shows it. Types: text, long_text,
 * translatable, integer, quantity (thousandths), money (minor units),
 * date, boolean, enum, belongs_to, employee. Numbers never pass through
 * floats; Bangla digits are read.
 */

const BANGLA = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' };
const clean = (text) => String(text ?? '').trim().replace(/[০-৯]/g, (digit) => BANGLA[digit]).replace(/[,\s]/g, '');

/** 2500 -> "2.5" */
export function milliText(milli) {
    if (milli === null || milli === undefined) return '';
    const negative = milli < 0;
    const abs = Math.abs(milli);
    const rest = String(abs % 1000).padStart(3, '0').replace(/0+$/, '');
    return `${negative ? '-' : ''}${Math.trunc(abs / 1000)}${rest ? `.${rest}` : ''}`;
}

/** A stored value as its input holds it. */
export function fieldToInput(field, value, currency) {
    switch (field.type) {
        case 'translatable': return { ...(value ?? {}) };
        case 'boolean': return Boolean(value);
        case 'integer': return value === null || value === undefined ? '' : String(value);
        case 'quantity': return milliText(value);
        case 'money': return value === null || value === undefined ? '' : minorToDecimalString(value, currencyDigits(currency));
        default: return value ?? '';
    }
}

/**
 * What an input sends: { value } or { error } (an error key under "generated.errors").
 * Empty optional inputs send null; empty required ones are an error.
 */
export function inputToApi(field, input, currency) {
    if (field.type === 'boolean') return { value: Boolean(input) };
    if (field.type === 'translatable') {
        const texts = Object.fromEntries(Object.entries(input ?? {}).map(([locale, text]) => [locale, String(text ?? '').trim() || null]));
        if (!texts.en) return field.required || Object.values(texts).some(Boolean) ? { error: 'english_needed' } : { value: null };
        return { value: texts };
    }
    const text = String(input ?? '').trim();
    if (text === '') return field.required ? { error: 'required' } : { value: null };
    switch (field.type) {
        case 'integer': {
            const digits = clean(text);
            return /^-?\d{1,15}$/.test(digits) ? { value: Number(digits) } : { error: 'whole_number' };
        }
        case 'quantity': {
            const match = /^(-?)(\d{1,12})(?:\.(\d{1,3}))?$/.exec(clean(text));
            if (!match) return { error: 'quantity' };
            const milli = Number(match[2]) * 1000 + Number((match[3] ?? '').padEnd(3, '0'));
            return { value: match[1] ? -milli : milli };
        }
        case 'money': {
            const minor = decimalStringToMinor(clean(text), currencyDigits(currency));
            return minor === null ? { error: 'amount' } : { value: minor };
        }
        case 'date': return /^\d{4}-\d{2}-\d{2}$/.test(text) ? { value: text } : { error: 'date' };
        default: return field.max && text.length > field.max ? { error: 'too_long' } : { value: text };
    }
}

/**
 * A value as a list or a page shows it. ctx: { t, ns, entity, currency, options: { entity: { id: title } }, employees: { id: name } }.
 */
export function displayValue(field, value, ctx) {
    if (value === null || value === undefined || value === '') return '—';
    switch (field.type) {
        case 'translatable': return textIn(value) || '—';
        case 'boolean': return value ? ctx.t('generated.yes') : ctx.t('generated.no');
        case 'enum': return ctx.t(`${ctx.ns}.options.${ctx.entity}.${field.name}.${value}`);
        case 'quantity': return formatDecimal(milliText(value));
        case 'money': return formatMoney({ amount: value, currency: ctx.currency });
        case 'date': return formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
        case 'belongs_to': return ctx.options?.[field.entity]?.[value] ?? '—';
        case 'employee': return ctx.employees?.[value] ?? '—';
        default: return String(value);
    }
}
