import { describe, expect, it } from 'vitest';
import { amountToMinor, barcodeModules, cleanNumber, CODE128_TABLE, code128Modules, ean13Modules, ean13Valid, formatQuantity, levelTone, lineToApi, milliToText, quantityToMilli, statusTone, toCsv } from '../../Modules/Inventory/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Inventory/resources/js/locales/en/inventory.json';
import bn from '../../Modules/Inventory/resources/js/locales/bn/inventory.json';

function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('Inventory screens', () => {
    it('registers its screens with the app shell, each a lazy page', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'inventory').map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['inventory', 'inventory-stock', 'inventory-items', 'inventory-item', 'inventory-documents', 'inventory-document', 'inventory-document-new', 'inventory-counts', 'inventory-count', 'inventory-expiring', 'inventory-warehouses', 'inventory-units', 'inventory-categories']));
        expect(moduleRoutes.find((route) => route.name === 'inventory-units').meta.kind).toBe('units');
    });

    it('reads quantities as thousandths, no more decimals than the unit allows, Bangla digits too', () => {
        expect(cleanNumber('১,২৫০.৫')).toBe('1250.5');
        expect(quantityToMilli('12', 0)).toBe(12000);
        expect(quantityToMilli('1.5', 0)).toBeNull();
        expect(quantityToMilli('১.২৫০', 3)).toBe(1250);
        expect(quantityToMilli('-2', 0)).toBeNull();
        expect(quantityToMilli('-2', 0, { signed: true })).toBe(-2000);
        expect(quantityToMilli('abc', 3)).toBeNull();
        expect(milliToText(12500)).toBe('12.5');
        expect(milliToText(100000)).toBe('100');
        expect(milliToText(0)).toBe('0');
        expect(milliToText(-2000)).toBe('-2');
        expect(formatQuantity(null)).toBe('—');
        expect(amountToMinor('120.50', 'BDT')).toBe(12050);
    });

    it('turns form lines into API lines, saying what to fix', () => {
        expect(lineToApi({ item_id: 'a', quantity: '10', unit_cost: '120' }, { type: 'receipt', decimals: 0, currency: 'BDT' })).toEqual([{ item_id: 'a', quantity_milli: 10000, unit_cost_minor: 12000 }, null]);
        expect(lineToApi({ item_id: 'a', quantity: '10', unit_cost: '' }, { type: 'receipt', decimals: 0, currency: 'BDT' })).toEqual([null, 'unit_cost']);
        expect(lineToApi({ item_id: 'a', quantity: '2', unit_cost: '' }, { type: 'issue', decimals: 0, currency: 'BDT' })).toEqual([{ item_id: 'a', quantity_milli: 2000 }, null]);
        expect(lineToApi({ item_id: 'a', quantity: '-2', unit_cost: '' }, { type: 'adjustment', decimals: 0, currency: 'BDT' })).toEqual([{ item_id: 'a', quantity_milli: -2000 }, null]);
        expect(lineToApi({ item_id: 'a', quantity: '5', unit_cost: '50' }, { type: 'receipt', decimals: 0, currency: 'BDT', tracksBatches: true })).toEqual([null, 'batch']);
        expect(lineToApi({ item_id: '', quantity: '5' }, { type: 'issue', decimals: 0, currency: 'BDT' })).toEqual([null, 'item']);
        expect(lineToApi({ item_id: 'a', quantity: '0' }, { type: 'issue', decimals: 0, currency: 'BDT' })).toEqual([null, 'quantity']);
    });

    it('colours levels and statuses, with the same keys in both languages', () => {
        expect(levelTone(4000, 5000)).toBe('warn');
        expect(levelTone(6000, 5000)).toBe('ok');
        expect(levelTone(-1000, null)).toBe('bad');
        expect(levelTone(1000, null)).toBe('neutral');
        for (const status of ['draft', 'pending_approval', 'in_transit', 'posted', 'cancelled', 'counting']) {
            expect(statusTone(status)).toBeTruthy();
            expect(bn.status[status]).toBeTruthy();
        }
        expect(keys(bn).sort()).toEqual(keys(en).sort());
    });

    it('draws barcodes for labels: EAN-13 when valid, else Code 128', () => {
        expect(ean13Valid('4006381333931')).toBe(true);
        expect(ean13Valid('4006381333932')).toBe(false);
        const ean = ean13Modules('4006381333931');
        expect(ean).toHaveLength(95);
        expect(ean.slice(0, 3)).toBe('101');
        expect(ean.slice(45, 50)).toBe('01010');
        // First digit 4 = LGLLGG; the second digit 0 in set L.
        expect(ean.slice(3, 10)).toBe('0001101');
        // Every Code 128 symbol is 11 modules wide, the stop 13.
        expect(CODE128_TABLE).toHaveLength(107);
        CODE128_TABLE.forEach((pattern, value) => expect([...pattern].reduce((sum, width) => sum + Number(width), 0), String(value)).toBe(value === 106 ? 13 : 11));
        // Start B, two symbols, check, stop: 11 * 4 + 13.
        expect(code128Modules('AB')).toHaveLength(57);
        expect(code128Modules('চাল')).toBeNull();
        // Start, nine characters, check: eleven symbols of 11 modules, and the stop.
        expect(barcodeModules('RICE-MINI')).toHaveLength(11 * 11 + 13);
    });

    it('saves CSV that a spreadsheet opens safely', () => {
        const csv = toCsv([['Item', 'Value'], ['=HYPERLINK("x")', '-1500.50'], ['Rice, 25 kg', '১২']]);
        expect(csv.startsWith('\uFEFF')).toBe(true);
        expect(csv).toContain(`"'=HYPERLINK(""x"")"`);
        expect(csv).toContain(',-1500.50\r\n');
        expect(csv).toContain('"Rice, 25 kg",১২');
    });

    it('opens the reports and labels screens', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'inventory').map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['inventory-reports', 'inventory-labels']));
    });
});
