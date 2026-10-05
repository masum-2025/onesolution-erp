import fs from 'fs';
import { describe, expect, it } from 'vitest';
import { addToCart, amountToMinor, discountShare, formatQuantity, percentToBp, priceCart, quantityToMilli, quickCash, saleBody, settle, shiftTone, splitTax } from '../../Modules/Pos/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Pos/resources/js/locales/en/pos.json';
import bn from '../../Modules/Pos/resources/js/locales/bn/pos.json';

function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('Point of sale screens', () => {
    it('registers its screens with the app shell', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'pos').map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['pos', 'pos-sales', 'pos-sale', 'pos-shifts', 'pos-shift', 'pos-registers']));
    });

    it('prices the cart exactly as the server does (the same cases as PosTest)', () => {
        expect(priceCart([{ quantity_milli: 2000, unit_price_minor: 11500, discount_minor: 0, tax_rate_bp: 1500 }], true)).toMatchObject({ subtotal: 23000, tax: 3000, total: 23000 });
        expect(priceCart([{ quantity_milli: 2000, unit_price_minor: 10000, discount_minor: 1000, tax_rate_bp: 1500 }], false)).toMatchObject({ subtotal: 20000, discount: 1000, tax: 2850, total: 21850 });
        expect(priceCart([{ quantity_milli: 1250, unit_price_minor: 8000, discount_minor: 0, tax_rate_bp: 0 }], true).total).toBe(10000);
        expect(splitTax(11500, 1500, true)).toEqual({ net: 10000, tax: 1500 });
        expect(discountShare(2000, 11500)).toBe(1739);
        expect(percentToBp('10')).toBe(1000);
        expect(percentToBp('১২.৫')).toBe(1250);
    });

    it('settles payments: change only from cash, never short', () => {
        expect(settle(1000, [{ method: 'card', amount_minor: 500 }, { method: 'cash', amount_minor: 1000 }])).toMatchObject({ ok: true, paid: 1500, change: 500 });
        expect(settle(1000, [{ method: 'cash', amount_minor: 999 }])).toMatchObject({ ok: false, reason: 'short', due: 1 });
        expect(settle(1000, [{ method: 'card', amount_minor: 2000 }])).toMatchObject({ ok: false, reason: 'change_without_cash' });
        expect(quickCash(26000, 'BDT')).toEqual([26000, 30000, 50000, 100000]);
    });

    it('builds the cart and the sale body', () => {
        const cart = [];
        const soap = { id: 's', sku: 'SOAP', name: 'Soap', unit_id: 'u', sale_price_minor: 11500, tax_rate_bp: 1500 };
        addToCart(cart, soap);
        addToCart(cart, soap);
        expect(cart).toHaveLength(1);
        expect(cart[0].quantity_milli).toBe(2000);
        expect(saleBody(cart, [{ method: 'cash', amount_minor: 30000 }, { method: 'card', amount_minor: 0 }], { op_id: 'x', register_id: 'r' })).toEqual({
            op_id: 'x', register_id: 'r', lines: [{ item_id: 's', quantity_milli: 2000 }], payments: [{ method: 'cash', amount_minor: 30000 }],
        });
        expect(quantityToMilli('1.5', 0)).toBeNull();
        expect(quantityToMilli('0', 0)).toBeNull();
        expect(quantityToMilli('২', 0)).toBe(2000);
        expect(amountToMinor('120.50', 'BDT')).toBe(12050);
        expect(formatQuantity(12500)).toBe('12.5');
        expect(shiftTone('pending_review')).toBe('warn');
    });

    it('labels everything the screens use, the same keys in both languages', () => {
        const dir = 'Modules/Pos/resources/js/pages/';
        const used = new Set(fs.readdirSync(dir).flatMap((file) => [...fs.readFileSync(dir + file, 'utf8').matchAll(/'(pos\.[a-z_]+(?:\.[a-z_]+)+)'/g)].map((match) => match[1])));
        for (const key of used) {
            const value = key.split('.').slice(1).reduce((tree, part) => tree?.[part], en);
            expect(typeof value, key).toBe('string');
        }
        for (const reason of ['negative_stock', 'late_session', 'discount']) expect(bn.review[reason]).toBeTruthy();
        expect(keys(bn).sort()).toEqual(keys(en).sort());
    });
});
