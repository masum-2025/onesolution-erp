import { currencyDigits, decimalStringToMinor, minorToDecimalString } from '@/lib/format';

/**
 * Numbers people type, read the same way everywhere: Bangla or Latin
 * digits, thousands commas and spaces allowed. Every module uses these;
 * none keeps its own copy (a source test checks).
 */

const BANGLA = Object.fromEntries([...'০১২৩৪৫৬৭৮৯'].map((digit, index) => [digit, String(index)]));

/** Bangla digits as Latin ones, nothing else changed: "১২৫০ টাকা" -> "1250 টাকা". */
export function latinDigits(text) {
    return String(text ?? '').replace(/[০-৯]/g, (digit) => BANGLA[digit]);
}

/** Typed digits as plain Latin text: "১,২৫০.৫০" -> "1250.50". */
export function cleanNumber(text) {
    return latinDigits(text)
        .replace(/[,\s ]/g, '')
        .trim();
}

/** Typed money -> minor units of the currency; null unless an amount at or above zero with no finer part than the currency has. */
export function amountToMinor(text, currency) {
    const clean = cleanNumber(text);
    if (clean === '' || !/^\d+(\.\d+)?$/.test(clean)) return null;
    return decimalStringToMinor(clean, currencyDigits(currency));
}

/** Minor units -> the text a money field shows ("" for none). */
export function minorToText(minor, currency) {
    return minor === null || minor === undefined ? '' : minorToDecimalString(minor, currencyDigits(currency));
}
