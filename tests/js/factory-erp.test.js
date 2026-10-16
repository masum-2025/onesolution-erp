import { describe, expect, it } from 'vitest';
import { need, percentToBp, piecesOf, quantityToMilli, sheetTotals, orderTone } from '../../Modules/FactoryErp/resources/js/lib';

describe('factory', () => {
    it('works out a need exactly as the server does, rounded up', () => {
        // 840 pieces, 2.400 kg a dozen, 5% wastage: 176.400 kg.
        expect(need(840, 2400, 500, 'dozen')).toBe(176400);
        // 240 pieces, 12 a dozen (one each), 2%: 244.8 -> 244800 thousandths.
        expect(need(240, 12000, 200, 'dozen')).toBe(244800);
        expect(need(1, 1, 0, 'dozen')).toBe(1);
        expect(need(10, 1500, 0, 'piece')).toBe(15000);
        expect(need(0, 2400, 500, 'dozen')).toBe(0);
    });

    it('adds up an order sheet by size, by colour and in all', () => {
        const totals = sheetTotals(['M', 'XL'], ['Navy', 'Red'], { 'M|Navy': 600, 'XL|Red': 240, 'XL|Navy': 10 });
        expect(totals).toEqual({ bySize: { M: 600, XL: 250 }, byColor: { Navy: 610, Red: 240 }, total: 850 });
    });

    it('reads typed numbers, Bangla digits too, without floats', () => {
        expect(quantityToMilli('২.৪')).toBe(2400);
        expect(quantityToMilli('0')).toBeNull();
        expect(quantityToMilli('1.2345')).toBeNull();
        expect(piecesOf('১,২০০')).toBe(1200);
        expect(piecesOf('')).toBe(0);
        expect(piecesOf('12.5')).toBeNull();
        expect(percentToBp('5')).toBe(500);
        expect(percentToBp('2.5')).toBe(250);
        expect(percentToBp('101')).toBeNull();
        expect(orderTone('in_production')).toBe('warn');
    });
});
