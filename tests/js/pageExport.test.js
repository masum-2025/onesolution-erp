import { describe, expect, it } from 'vitest';
import { amountToMinor, cleanNumber, latinDigits, minorToText } from '@/lib/numbers';
import { localToday } from '@/lib/dates';
import { cellText, fetchPages, fileName, safeText, sheetData, toCsv } from '@/lib/pageExport';
import * as ui from '@/ui';

describe('shared number readers', () => {
    it('read Bangla or Latin digits, commas and spaces, the same everywhere', () => {
        expect(latinDigits('১২৫০ টাকা')).toBe('1250 টাকা');
        expect(cleanNumber(' ১,২৫০.৫০ ')).toBe('1250.50');
        expect(amountToMinor('১,২৫০.৫০', 'BDT')).toBe(125050);
        expect(amountToMinor('0', 'BDT')).toBe(0);
        expect(amountToMinor('-5', 'BDT')).toBeNull();
        expect(amountToMinor('12.345', 'BDT')).toBeNull();
        expect(amountToMinor('', 'BDT')).toBeNull();
        expect(minorToText(125050, 'BDT')).toBe('1250.50');
        expect(minorToText(null, 'BDT')).toBe('');
    });

    it('takes today and days around it from the reader\'s own clock', () => {
        const late = new Date(2026, 10, 3, 23, 50);
        expect(localToday(0, late)).toBe('2026-11-03');
        expect(localToday(-7, late)).toBe('2026-10-27');
        expect(localToday(30, late)).toBe('2026-12-03');
    });

    it('are all offered from one import for modules', () => {
        for (const name of ['PageFrame', 'MoneyInput', 'DateInput', 'StatusBadge', 'ExportMenu', 'AppButton', 'amountToMinor', 'localToday', 'newOpId']) {
            expect(ui[name], name).toBeTruthy();
        }
    });
});

describe('page export', () => {
    const columns = [
        { key: 'number', label: 'Number' },
        { key: 'narration', label: 'Narration' },
        { key: 'total_minor', label: 'Amount', type: 'money', currency: 'BDT' },
        { key: 'entry_date', label: 'Date', type: 'date' },
        { key: 'status', label: 'Status', value: (row) => `#${row.status}` },
    ];
    const rows = [
        { number: 'JV-1', narration: 'Rent, October', total_minor: 50000, entry_date: '2026-10-15', status: 'posted' },
        { number: 'JV-2', narration: '=HYPERLINK("http://evil")', total_minor: -1250, entry_date: '2026-10-16T00:00:00Z', status: 'draft' },
    ];

    it('writes CSV that Excel reads in Bangla, with formulas made plain text', () => {
        const csv = toCsv(columns, rows);
        expect(csv.startsWith('﻿Number,Narration,Amount,Date,Status\r\n')).toBe(true);
        expect(csv).toContain('JV-1,"Rent, October",500.00,2026-10-15,#posted');
        // A formula never runs; a negative amount stays a number.
        expect(csv).toContain(`JV-2,"'=HYPERLINK(""http://evil"")",-12.50,2026-10-16,#draft`);
        expect(safeText('+1')).toBe("'+1");
        expect(safeText('@sum')).toBe("'@sum");
        expect(safeText('Rent')).toBe('Rent');
    });

    it('gives a spreadsheet numbers, dates and text, with a bold header', () => {
        const [header, first] = sheetData(columns, rows);
        expect(header[0]).toEqual({ value: 'Number', fontWeight: 'bold' });
        expect(first[2]).toEqual({ type: Number, value: 500, format: '#,##0.00' });
        expect(first[3].type).toBe(Date);
        expect(first[3].value.toISOString().slice(0, 10)).toBe('2026-10-15');
        expect(first[0]).toEqual({ type: String, value: 'JV-1' });
        expect(cellText({ amount: 7, currency_code: 'JPY' }, { key: 'amount', type: 'money' })).toBe('7');
    });

    it('names files after the page and the day, safely', () => {
        expect(fileName('Journal entries', 'xlsx', new Date(2026, 10, 3))).toBe('Journal entries 2026-11-03.xlsx');
        expect(fileName('a/b:c*?', 'csv', new Date(2026, 10, 3))).toBe('a b c 2026-11-03.csv');
        expect(fileName('', 'csv', new Date(2026, 10, 3))).toBe('export 2026-11-03.csv');
    });

    it('reads every page of a list, and one row past the limit so the server can refuse', async () => {
        const pages = { 1: [1, 2], 2: [3, 4], 3: [5] };
        const fetchPage = async (page) => ({ data: pages[page], meta: { page, last_page: 3 } });
        expect(await fetchPages(fetchPage)).toEqual([1, 2, 3, 4, 5]);
        expect(await fetchPages(fetchPage, 2)).toEqual([1, 2, 3]);
        expect(await fetchPages(async () => ({ data: [9] }))).toEqual([9]);
    });
});
