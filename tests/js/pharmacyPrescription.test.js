import { describe, expect, it } from 'vitest';
import { dosePerDay, durationDays, suggestedLine, suggestedPieces } from '../../Modules/Pharmacy/resources/js/prescription.js';

describe('pharmacy: quantity from a prescription', () => {
    it('reads doses in English or Bangla digits, and refuses words', () => {
        expect(dosePerDay('1+0+1')).toBe(2);
        expect(dosePerDay('১+১+১')).toBe(3);
        expect(dosePerDay('½ + 0 + ½')).toBe(1);
        expect(dosePerDay('1-0-1')).toBe(2);
        expect(dosePerDay('after each loose stool')).toBeNull();
        expect(dosePerDay('0+0+0')).toBeNull();
        expect(dosePerDay('')).toBeNull();
        expect(dosePerDay(null)).toBeNull();
    });

    it('reads durations in days, weeks or months', () => {
        expect(durationDays('7 days')).toBe(7);
        expect(durationDays('৭ দিন')).toBe(7);
        expect(durationDays('2 weeks')).toBe(14);
        expect(durationDays('১ মাস')).toBe(30);
        expect(durationDays('5')).toBe(5);
        expect(durationDays('continue')).toBeNull();
        expect(durationDays(null)).toBeNull();
    });

    it('suggests pieces for the whole course only when both are plain', () => {
        expect(suggestedPieces('1+0+1', '7 days')).toBe(14);
        expect(suggestedPieces('½+0+½', '5 days')).toBe(5);
        expect(suggestedPieces('1+0+1', 'continue')).toBeNull();
        expect(suggestedPieces('as needed', '7 days')).toBeNull();
    });

    it('turns pieces into a pack the counter sells', () => {
        const strips = { pieces_per_strip: 10, strips_per_box: 10 };
        expect(suggestedLine(strips, true, 20)).toEqual({ pack: 'strip', quantity: 2 });
        expect(suggestedLine(strips, true, 14)).toEqual({ pack: 'piece', quantity: 14 });
        expect(suggestedLine(strips, false, 14)).toEqual({ pack: 'strip', quantity: 2 });
        expect(suggestedLine(strips, true, null)).toEqual({ pack: 'strip', quantity: 1 });
        expect(suggestedLine({ pieces_per_strip: null, strips_per_box: null }, false, 3)).toEqual({ pack: 'piece', quantity: 3 });
    });
});
