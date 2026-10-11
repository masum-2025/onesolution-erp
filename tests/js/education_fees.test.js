import { describe, expect, it } from 'vitest';
import { billKind, billState, normalizeAmountText, parseAmount, parsePercent, percentText, plan, requestTone } from '../../Modules/EducationFees/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/EducationFees/resources/js/locales/en/education_fees.json';
import bn from '../../Modules/EducationFees/resources/js/locales/bn/education_fees.json';

function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

const bill = (id, due, balance, extra = {}) => ({ id, number: `FEE-${id}`, due_date: due, balance_minor: balance, status: 'open', ...extra });

describe('fee screens', () => {
    it('registers its screens behind the module', () => {
        const routes = moduleRoutes.filter((route) => route.meta.module === 'education_fees');
        expect(routes.map((route) => route.name)).toEqual(['fees-counter', 'fees-student', 'fees-receipt', 'fees-takings', 'fees-approvals', 'fees-runs', 'fees-run', 'fees-bills', 'fees-bill', 'fees-dues', 'fees-heads', 'fees-structures', 'fees-books']);
        expect(routes.every((route) => route.meta.ns.includes('education_fees'))).toBe(true);
    });

    it('speaks Bangla and English with the same keys', () => {
        expect(keys(bn).sort()).toEqual(keys(en).sort());
    });

    it('reads typed amounts in English or Bangla digits, never as floats', () => {
        expect(normalizeAmountText('৳ ১,২৫০.৫০')).toBe('1250.50');
        expect(parseAmount('1,500', 'BDT')).toBe(150000);
        expect(parseAmount('১৫০০.৫', 'BDT')).toBe(150050);
        expect(parseAmount('0', 'BDT')).toBeNull();
        expect(parseAmount('-5', 'BDT')).toBeNull();
        expect(parseAmount('12.345', 'BDT')).toBeNull();
        expect(parseAmount('abc', 'BDT')).toBeNull();
    });

    it('shows where money goes before it is taken, as the server does', () => {
        const bills = [bill('b', '2026-02-10', 150000), bill('a', '2026-01-10', 150000), bill('c', '2026-03-10', 0), bill('d', '2026-01-01', 9, { status: 'paid' })];
        expect(plan(bills, 200000)).toEqual({
            bills: [{ id: 'a', number: 'FEE-a', amount: 150000, full: true }, { id: 'b', number: 'FEE-b', amount: 50000, full: false }],
            advance: 0, blocked: null,
        });
        expect(plan(bills, 400000).advance).toBe(100000);
        // Without part payments: whole bills only, and nothing when the first cannot be met.
        expect(plan(bills, 200000, false)).toEqual({ bills: [{ id: 'a', number: 'FEE-a', amount: 150000, full: true }], advance: 50000, blocked: null });
        expect(plan(bills, 100000, false)).toEqual({ bills: [], advance: 0, blocked: 'FEE-a' });
    });

    it('names bills and their states in words', () => {
        expect(billKind('monthly:2026-03')).toEqual({ kind: 'monthly', month: '2026-03' });
        expect(billKind('credits:01J:2')).toEqual({ kind: 'credits', month: null });
        expect(billKind('weird')).toEqual({ kind: 'other', month: null });
        expect(billState({ status: 'open', overdue: true, paid_minor: 0 })).toEqual({ key: 'overdue', tone: 'bad' });
        expect(billState({ status: 'open', overdue: false, paid_minor: 5 }).key).toBe('part_paid');
        expect(billState({ status: 'paid' }).key).toBe('paid');
        for (const key of ['open', 'part_paid', 'overdue', 'paid', 'cancelled']) expect(en.bill_states[key]).toBeTruthy();
        expect(requestTone('pending')).toBe('warn');
    });

    it('reads and writes percents in basis points', () => {
        expect(percentText(1500)).toBe('15');
        expect(percentText(1250)).toBe('12.5');
        expect(parsePercent('১২.৫')).toBe(1250);
        expect(parsePercent('100')).toBe(10000);
        expect(parsePercent('101')).toBeNull();
        expect(parsePercent('0')).toBeNull();
        expect(parsePercent('1.234')).toBeNull();
    });
});
