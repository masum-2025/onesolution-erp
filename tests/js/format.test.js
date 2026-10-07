import { beforeAll, describe, expect, it } from 'vitest';
import { decimalStringToMinor, formatDuration, formatMoney, minorToDecimalString, parseDuration } from '@/lib/format';
import { i18n, initI18n, setLocale } from '@/lib/i18n';

beforeAll(() => initI18n(['en', 'bn'], 'en'));

describe('money in minor units, without floats', () => {
    it('turns minor units into an exact decimal string', () => {
        expect(minorToDecimalString(35000000, 2)).toBe('350000.00');
        expect(minorToDecimalString(-5, 2)).toBe('-0.05');
        expect(minorToDecimalString(1500, 0)).toBe('1500');
        expect(minorToDecimalString(1, 3)).toBe('0.001');
    });

    it('parses typed amounts into minor units', () => {
        expect(decimalStringToMinor('350000.5', 2)).toBe(35000050);
        expect(decimalStringToMinor('0.1', 2)).toBe(10);
        expect(decimalStringToMinor(' 12 ', 2)).toBe(1200);
        expect(decimalStringToMinor('-3.25', 2)).toBe(-325);
    });

    it('rejects amounts it cannot store exactly', () => {
        expect(decimalStringToMinor('1.005', 2)).toBeNull();
        expect(decimalStringToMinor('1,000', 2)).toBeNull();
        expect(decimalStringToMinor('abc', 2)).toBeNull();
        expect(decimalStringToMinor('99999999999999999', 2)).toBeNull();
    });

    it('keeps 0.1 + 0.2 style amounts exact', () => {
        const total = decimalStringToMinor('0.1', 2) + decimalStringToMinor('0.2', 2);
        expect(minorToDecimalString(total, 2)).toBe('0.30');
    });

    it('formats money in the user language, with Bangla digits in bn', async () => {
        expect(formatMoney({ amount: 150050, currency: 'BDT' })).toContain('1,500.50');

        await setLocale('bn', { remember: false });
        expect(formatMoney({ amount: 150050, currency: 'BDT' })).toMatch(/১,৫০০\.৫০/);
        await setLocale('en', { remember: false });
    });

    it('shows the number alone when the currency is not known yet, never "undefined"', () => {
        expect(formatMoney({ amount: 0, currency: undefined })).not.toContain('undefined');
        expect(formatMoney({ amount: 150050, currency: null })).toContain('1,500.50');
        expect(formatMoney({ amount: null, currency: 'BDT' })).toBe('—');
    });
});

describe('durations', () => {
    it('parses ISO 8601 durations used by rules', () => {
        expect(parseDuration('PT15M')).toEqual({ day: 0, hour: 0, minute: 15, second: 0 });
        expect(parseDuration('P1DT2H')).toEqual({ day: 1, hour: 2, minute: 0, second: 0 });
        expect(parseDuration('15 minutes')).toBeNull();
    });

    it('reads them out in words', () => {
        expect(i18n.locale).toBe('en');
        expect(formatDuration('PT90M')).toBe('90 minutes');
        expect(formatDuration('P1DT2H')).toMatch(/1 day.*2 hours/);
    });
});
