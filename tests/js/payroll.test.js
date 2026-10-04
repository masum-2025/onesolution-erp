import { describe, expect, it } from 'vitest';
import { amountToMinor, bankCsv, bpToPercent, cleanNumber, minorToText, nextPeriod, percentToBp, runTone, sortSlips } from '../../Modules/Payroll/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Payroll/resources/js/locales/en/payroll.json';
import bn from '../../Modules/Payroll/resources/js/locales/bn/payroll.json';

/** Every dotted key of a locale file ("run.steps.pay"). */
function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('Payroll screens', () => {
    it('registers its screens with the app shell, the portal ones marked', () => {
        const routes = moduleRoutes.filter((route) => route.meta.module === 'payroll');
        expect(routes.map((route) => route.name)).toEqual(
            expect.arrayContaining(['payroll', 'payroll-run', 'payroll-slip', 'payroll-employees', 'payroll-employee', 'payroll-components', 'payroll-structures', 'payroll-me', 'payroll-my-slip', 'payroll-portal', 'payroll-portal-slip']),
        );
        expect(routes.find((route) => route.name === 'payroll-portal').meta.portal).toBe(true);
        expect(routes.find((route) => route.name === 'payroll-portal-slip').meta.slip).toBe('portal');
        expect(routes.find((route) => route.name === 'payroll-my-slip').meta.slip).toBe('mine');
        expect(routes.find((route) => route.name === 'payroll-slip').meta.portal).toBeUndefined();
    });

    it('reads typed amounts as integer minor units, Bangla digits too', () => {
        expect(cleanNumber('১,২৫,০০০.৫০')).toBe('125000.50');
        expect(amountToMinor('30,000', 'BDT')).toBe(3000000);
        expect(amountToMinor('১৫০০.৫', 'BDT')).toBe(150050);
        expect(amountToMinor('0.1', 'BDT')).toBe(10);
        expect(amountToMinor('-5', 'BDT')).toBeNull();
        expect(amountToMinor('', 'BDT')).toBeNull();
        expect(amountToMinor('abc', 'BDT')).toBeNull();
        expect(minorToText(3000000, 'BDT')).toBe('30000.00');
        expect(minorToText(null, 'BDT')).toBe('');
    });

    it('turns percentages into basis points and back without floats', () => {
        expect(percentToBp('50')).toBe(5000);
        expect(percentToBp('12.5')).toBe(1250);
        expect(percentToBp('০.০৫')).toBe(5);
        expect(percentToBp('1.234')).toBeNull();
        expect(percentToBp('-3')).toBeNull();
        expect(bpToPercent(1250)).toBe('12.5');
        expect(bpToPercent(5000)).toBe('50');
        expect(bpToPercent(5)).toBe('0.05');
    });

    it('puts slips with a problem first and finds the next month', () => {
        const sorted = sortSlips([
            { employee_name: 'Rahim', problem: null },
            { employee_name: 'Karim', problem: null },
            { employee_name: 'Zara', problem: 'no_salary' },
        ]);
        expect(sorted.map((slip) => slip.employee_name)).toEqual(['Zara', 'Karim', 'Rahim']);
        expect(nextPeriod([{ period: '2026-09' }, { period: '2026-11' }])).toBe('2026-12');
        expect(nextPeriod([{ period: '2026-12' }])).toBe('2027-01');
        expect(nextPeriod([], new Date('2026-10-05T00:00:00Z'))).toBe('2026-10');
    });

    it('writes the bank file as CSV a spreadsheet cannot run', () => {
        const csv = bankCsv(
            {
                currency: 'BDT',
                rows: [
                    { employee_code: 'E1', employee_name: 'Rahim, Md', method: 'bank', provider: 'City "Bank"', account_name: '=HYPERLINK("x")', account_number: '0011223344', branch: '@Gulshan', amount_minor: 2500050 },
                    { employee_code: 'E2', employee_name: 'Karim', method: null, provider: null, account_name: null, account_number: null, branch: null, amount_minor: 100 },
                ],
            },
            ['Code', 'Name', 'Method', 'Bank', 'Account name', 'Account number', 'Branch', 'Amount'],
        );
        const lines = csv.replace(/^﻿/, '').split('\r\n');
        expect(csv.startsWith('﻿')).toBe(true);
        expect(lines[1]).toBe(`E1,"Rahim, Md",bank,"City ""Bank""","'=HYPERLINK(""x"")",0011223344,'@Gulshan,25000.50`);
        expect(lines[2]).toBe('E2,Karim,,,,,,1.00');
        expect(lines[3]).toBe('');
    });

    it('labels every status and payment method, the same keys in both languages', () => {
        for (const status of ['draft', 'pending_approval', 'approved', 'paid']) {
            expect(en.status[status]).toBeTruthy();
            expect(bn.status[status]).toBeTruthy();
            expect(runTone(status)).toBeTruthy();
        }
        expect(runTone('paid')).toBe('ok');
        for (const method of ['bank', 'mobile', 'cash']) expect(bn.methods[method]).toBeTruthy();
        expect(keys(bn).sort()).toEqual(keys(en).sort());
    });
});
