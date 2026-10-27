import { describe, expect, it } from 'vitest';
import { advanceTone, amountToMinor, kmToTenths, mileageAmount, minorToText, monthsFrom, nameIn, needsReceipt, statusTone, tenthsToKm } from '../../Modules/Expenses/resources/js/lib';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Expenses/resources/js/locales/en/expenses.json';
import bn from '../../Modules/Expenses/resources/js/locales/bn/expenses.json';

const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`])).sort();

describe('expenses helpers', () => {
    it('registers its screens with the app shell, the portal page for portal members', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'expenses').map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['expenses-me', 'expenses-approvals', 'expenses-claims', 'expenses-categories', 'expenses-portal']));
        expect(moduleRoutes.find((route) => route.name === 'expenses-portal').meta.portal).toBe(true);
    });

    it('says everything in Bangla and English', () => {
        expect(keys(bn)).toEqual(keys(en));
    });

    it('reads typed money as minor units, Bangla digits too', () => {
        expect(amountToMinor('1,250.50', 'BDT')).toBe(125050);
        expect(amountToMinor('১২৫০', 'BDT')).toBe(125000);
        expect(amountToMinor('-5', 'BDT')).toBeNull();
        expect(amountToMinor('abc', 'BDT')).toBeNull();
        expect(minorToText(125050, 'BDT')).toBe('1250.50');
    });

    it('works mileage out as the server does: tenths of a km, rounded half up', () => {
        expect(kmToTenths('12.5')).toBe(125);
        expect(kmToTenths('১২')).toBe(120);
        expect(kmToTenths('0')).toBeNull();
        expect(kmToTenths('1.25')).toBeNull();
        expect(tenthsToKm(125)).toBe('12.5');
        // 12.5 km at 15.00 a km
        expect(mileageAmount(125, 1500)).toBe(18750);
        // 0.3 km at 0.15: 4.5 minor rounds up to 5
        expect(mileageAmount(3, 15)).toBe(5);
        expect(mileageAmount(125, 0)).toBe(0);
    });

    it('asks for a receipt above the limit, never for mileage', () => {
        expect(needsReceipt({ amount_minor: 50001 }, 50000)).toBe(true);
        expect(needsReceipt({ amount_minor: 50000 }, 50000)).toBe(false);
        expect(needsReceipt({ amount_minor: 90000, mileage: true }, 50000)).toBe(false);
    });

    it('names the daily rates of the rule in the language of the reader, and tones advances', () => {
        expect(nameIn({ en: 'Outside the city', bn: 'শহরের বাইরে' }, 'bn')).toBe('শহরের বাইরে');
        expect(nameIn({ en: 'Outside' }, 'bn')).toBe('Outside');
        expect(nameIn(null)).toBe('');
        expect(advanceTone('settled')).toBe('ok');
        expect(advanceTone('submitted')).toBe('warn');
    });

    it('steps months for paying with a salary, and tones each status', () => {
        expect(monthsFrom('2026-11', 3)).toEqual(['2026-11', '2026-12', '2027-01']);
        expect(statusTone('paid')).toBe('ok');
        expect(statusTone('rejected')).toBe('bad');
        expect(statusTone('unknown')).toBe('neutral');
    });
});
