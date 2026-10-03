import { describe, expect, it } from 'vitest';
import {
    accountTree,
    amountText,
    formLines,
    journalPayload,
    lineTotals,
    monthOf,
    normalizeAmountText,
    parseAmount,
    postableAccounts,
    statusTone,
    todayIn,
} from '../../Modules/Accounting/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';

describe('Accounting screens', () => {
    it('registers its screens with the app shell, each a lazy page', () => {
        const names = moduleRoutes.map((route) => route.name);
        expect(names).toEqual(
            expect.arrayContaining(['accounting', 'accounting-journal-new', 'accounting-journal', 'accounting-approvals', 'accounting-accounts', 'accounting-reports', 'accounting-setup']),
        );
        moduleRoutes
            .filter((route) => route.meta.module === 'accounting')
            .forEach((route) => {
                expect(typeof route.component).toBe('function');
                expect(route.meta.ns).toContain('accounting');
            });
        expect(moduleRoutes.find((route) => route.name === 'accounting-approvals').meta.approvals).toBe(true);
    });

    it('reads typed amounts exactly, in Bangla or English digits, with separators', () => {
        expect(normalizeAmountText('১,২৫০.৫০')).toBe('1250.50');
        expect(parseAmount('১,২৫০.৫০', 'BDT')).toBe(125050);
        expect(parseAmount('1 250.5', 'BDT')).toBe(125050);
        expect(parseAmount('৳500', 'BDT')).toBe(50000);
        expect(parseAmount('', 'BDT')).toBe(0);
        // 0.1 + 0.2 never drifts: text math, not floats.
        expect(parseAmount('0.1', 'BDT') + parseAmount('0.2', 'BDT')).toBe(30);
        expect(parseAmount('12.345', 'BDT')).toBeNull();
        expect(parseAmount('-5', 'BDT')).toBeNull();
        expect(parseAmount('abc', 'BDT')).toBeNull();
        // Currencies without minor units.
        expect(parseAmount('1500', 'JPY')).toBe(1500);
        expect(amountText(125050, 'BDT')).toBe('1250.50');
        expect(amountText(0, 'BDT')).toBe('');
    });

    it('adds up the lines and says when the entry can be sent', () => {
        const lines = [
            { account_id: 'a', debit: '1000', credit: '' },
            { account_id: 'b', debit: '', credit: '600' },
        ];
        expect(lineTotals(lines, 'BDT')).toMatchObject({ debit: 100000, credit: 60000, difference: 40000, balanced: false });

        lines.push({ account_id: 'c', debit: '', credit: '৪০০' });
        expect(lineTotals(lines, 'BDT')).toMatchObject({ difference: 0, balanced: true, invalid: false });

        // Both sides on one line, or an unreadable amount, never balances.
        expect(lineTotals([...lines, { account_id: 'd', debit: '5', credit: '5' }], 'BDT').balanced).toBe(false);
        expect(lineTotals([{ account_id: 'a', debit: '1', credit: '' }, { account_id: '', debit: '', credit: '1' }], 'BDT').balanced).toBe(false);
        expect(lineTotals([{ account_id: 'a', debit: '0', credit: '' }, { account_id: 'b', debit: '', credit: '0' }], 'BDT').balanced).toBe(false);
    });

    it('sends only filled lines, amounts in minor units, empty optional parts left out', () => {
        const body = journalPayload(
            {
                entry_date: '2026-10-15',
                narration: '  Rent  ',
                lines: [
                    { account_id: 'rent', debit: '20,000', credit: '', cost_centre_id: 'branch', memo: ' October ' },
                    { account_id: 'cash', debit: '', credit: '20000', cost_centre_id: '', memo: '' },
                    { account_id: '', debit: '', credit: '', cost_centre_id: '', memo: '' },
                ],
            },
            'BDT',
        );
        expect(body).toEqual({
            entry_date: '2026-10-15',
            narration: 'Rent',
            lines: [
                { account_id: 'rent', debit_minor: 2000000, cost_centre_id: 'branch', memo: 'October' },
                { account_id: 'cash', credit_minor: 2000000 },
            ],
        });
    });

    it('turns a saved journal back into form lines, the company itself as no cost centre', () => {
        const lines = formLines(
            { lines: [{ account_id: 'a', debit_minor: 150, credit_minor: 0, cost_centre_id: 'company', memo: null }, { account_id: 'b', debit_minor: 0, credit_minor: 150, cost_centre_id: 'branch', memo: 'x' }] },
            'BDT',
            'company',
        );
        expect(lines).toEqual([
            { account_id: 'a', debit: '1.50', credit: '', cost_centre_id: '', memo: '' },
            { account_id: 'b', debit: '', credit: '1.50', cost_centre_id: 'branch', memo: 'x' },
        ]);
    });

    it('nests accounts in code order with their depth, and offers only active non-group accounts for lines', () => {
        const accounts = [
            { id: 'cash', code: '1110', parent_id: 'current', is_group: false, status: 'active' },
            { id: 'assets', code: '1000', parent_id: null, is_group: true, status: 'active' },
            { id: 'current', code: '1100', parent_id: 'assets', is_group: true, status: 'active' },
            { id: 'bank', code: '1120', parent_id: 'current', is_group: false, status: 'archived' },
            { id: 'orphan', code: '9999', parent_id: 'gone', is_group: false, status: 'active' },
        ];
        const { roots, flat } = accountTree(accounts);
        expect(flat.map((account) => [account.code, account.depth])).toEqual([
            ['1000', 0],
            ['1100', 1],
            ['1110', 2],
            ['1120', 2],
            ['9999', 0],
        ]);
        expect(roots[0].children[0].children.map((account) => account.id)).toEqual(['cash', 'bank']);
        expect(postableAccounts(accounts).map((account) => account.id)).toEqual(['cash', 'orphan']);
    });

    it('gives statuses a tone, and knows today and the month in the company timezone', () => {
        expect(statusTone('posted')).toBe('ok');
        expect(statusTone('pending_approval')).toBe('warn');
        expect(statusTone('rejected')).toBe('bad');
        expect(statusTone('other')).toBe('neutral');
        // 20:00 UTC on 14 October is already 15 October in Dhaka.
        expect(todayIn('Asia/Dhaka', new Date('2026-10-14T20:00:00Z'))).toBe('2026-10-15');
        expect(monthOf('2026-02-10')).toEqual({ from: '2026-02-01', to: '2026-02-28' });
        expect(monthOf('2028-02-10').to).toBe('2028-02-29');
    });
});
