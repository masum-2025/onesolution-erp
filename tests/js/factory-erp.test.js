import { describe, expect, it } from 'vitest';
import { aqlSample, cutAllowed, dhuBp, dhuText, efficiencyBp, fillQuantity, hourTarget, need, percentToBp, piecesOf, quantityToMilli, sewLeft, sheetToLines, sheetTotals, stagePercent, orderTone } from '../../Modules/FactoryErp/resources/js/lib';

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

    it('works out what a line can still write and how far a stage has got', () => {
        // 600 cut, 120 good and 4 rejected: 476 left; never below nothing.
        expect(sewLeft({ cut: 600, good: 120, rejects: 4 })).toBe(476);
        expect(sewLeft({ cut: 10, good: 12, rejects: 0 })).toBe(0);
        expect(sewLeft(undefined)).toBe(0);
        expect(stagePercent(415, 840)).toBe(49);
        expect(stagePercent(900, 840)).toBe(100);
        expect(stagePercent(5, 0)).toBe(0);
    });

    it('works out the hour\'s target and efficiency as the server does', () => {
        // 20 people × 60 ÷ 12.5 minutes × 60% = 57.6 -> 57.
        expect(hourTarget(20, 12500, 6000)).toBe(57);
        expect(hourTarget(20, 0, 6000)).toBe(0);
        // 3,750 earned minutes over 9,600: 39.06%.
        expect(efficiencyBp(3750000, 9600)).toBe(3906);
        expect(efficiencyBp(10, 0)).toBeNull();
    });

    it('works out DHU and the AQL sample as the server does', () => {
        expect(dhuBp(6, 120)).toBe(500);
        expect(dhuBp(1, 0)).toBeNull();
        expect(dhuText(320)).toBe('3.2');
        expect(dhuText(null)).toBe('—');
        const levels = [{ up_to: 8, sample: 2, accept: 0 }, { up_to: 1200, sample: 80, accept: 5 }, { up_to: null, sample: 1250, accept: 21 }];
        expect(aqlSample(levels, 1000)).toEqual({ sample: 80, accept: 5 });
        expect(aqlSample(levels, 2)).toEqual({ sample: 2, accept: 0 });
        expect(aqlSample(levels, 900000)).toEqual({ sample: 1250, accept: 21 });
        expect(aqlSample(levels, 1)).toBeNull();
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
