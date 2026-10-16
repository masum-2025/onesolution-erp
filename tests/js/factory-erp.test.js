import { describe, expect, it } from 'vitest';
import { cutAllowed, fillQuantity, need, percentToBp, piecesOf, quantityToMilli, sheetToLines, sheetTotals, orderTone } from '../../Modules/FactoryErp/resources/js/lib';

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

    it('turns a typed lay into lines and caps cutting as the server does', () => {
        expect(sheetToLines({ 'M|Navy': '৩০০', 'XL|Red': '', 'L|Navy': 'x' })).toEqual([
            { size: 'M', color: 'Navy', quantity: 300 }, { size: 'XL', color: 'Red', quantity: 0 }, { size: 'L', color: 'Navy', quantity: 0 },
        ]);
        // 600 + 3% = 618; 333 + 3% = 342.99 -> 342.
        expect(cutAllowed(600, 300)).toBe(618);
        expect(cutAllowed(333, 300)).toBe(342);
        expect(cutAllowed(0, 300)).toBe(0);
    });

    it('fills in what is left as far as the stores hold it, in whole pieces where the unit has no decimals', () => {
        // 7,861.8 buttons still to give, 2,000 held: 2,000.
        expect(fillQuantity(7861800, 2000000, 0)).toBe(2000000);
        // 7,861.8 to give, plenty held: 7,862 whole buttons.
        expect(fillQuantity(7861800, 9000000, 0)).toBe(7862000);
        // Kilograms keep thousandths; 52.5 kg held of 812.898.
        expect(fillQuantity(812898, 52500, 3)).toBe(52500);
        expect(fillQuantity(812898, 900000, 3)).toBe(812898);
        expect(fillQuantity(5, 0, 0)).toBe(0);
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
