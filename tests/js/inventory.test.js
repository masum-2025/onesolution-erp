import { describe, expect, it } from 'vitest';
import { amountToMinor, cleanNumber, formatQuantity, levelTone, lineToApi, milliToText, quantityToMilli, statusTone } from '../../Modules/Inventory/resources/js/lib.js';
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
});
