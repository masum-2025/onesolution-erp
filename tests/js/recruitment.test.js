import { describe, expect, it } from 'vitest';
import { amountToMinor, applicationTone, byStage, dayOf, minorToText, nameIn, nextSlot, offerTone, openingTone, recommendationTone } from '../../Modules/Recruitment/resources/js/lib';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Recruitment/resources/js/locales/en/recruitment.json';
import bn from '../../Modules/Recruitment/resources/js/locales/bn/recruitment.json';

const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`])).sort();

describe('recruitment helpers', () => {
    it('registers its screens with the app shell', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'recruitment').map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['recruitment-openings', 'recruitment-pipeline', 'recruitment-interviews', 'recruitment-settings', 'recruitment-letter', 'recruitment-offer']));
    });

    it('says everything in Bangla and English', () => {
        expect(keys(bn)).toEqual(keys(en));
    });

    it('reads typed salaries as minor units, Bangla digits too', () => {
        expect(amountToMinor('25,000', 'BDT')).toBe(2500000);
        expect(amountToMinor('২৫০০০.৫', 'BDT')).toBe(2500050);
        expect(amountToMinor('', 'BDT')).toBeNull();
        expect(amountToMinor('-1', 'BDT')).toBeNull();
        expect(minorToText(2500000, 'BDT')).toBe('25000.00');
        expect(minorToText(null, 'BDT')).toBe('');
    });

    it('sorts applications into the stages for the board, every stage present', () => {
        const stages = [{ key: 'applied' }, { key: 'screening' }, { key: 'offer' }];
        const columns = byStage([{ id: 'a', stage: 'applied' }, { id: 'b', stage: 'offer' }, { id: 'c', stage: 'applied' }], stages);
        expect(Object.keys(columns)).toEqual(['applied', 'screening', 'offer']);
        expect(columns.applied.map((item) => item.id)).toEqual(['a', 'c']);
        expect(columns.screening).toEqual([]);
    });

    it('names, tones and the next free slot', () => {
        expect(nameIn({ en: 'Applied', bn: 'আবেদন' }, 'bn')).toBe('আবেদন');
        expect(nameIn({ en: 'Applied' }, 'bn')).toBe('Applied');
        expect(nameIn(null, 'bn')).toBe('');
        expect(openingTone('open')).toBe('ok');
        expect(applicationTone('rejected')).toBe('bad');
        expect(recommendationTone('maybe')).toBe('warn');
        expect(offerTone('accepted')).toBe('ok');
        expect(offerTone('pending_approval')).toBe('warn');
        expect(nextSlot(new Date(2026, 9, 28, 15, 0))).toBe('2026-10-29T10:00');
    });

    it('dates the first day and the answer day by the reader clock, not UTC', () => {
        // 1:30 at night is still yesterday in UTC east of Greenwich
        const night = new Date(2026, 9, 28, 1, 30);
        expect(dayOf(0, night)).toBe('2026-10-28');
        expect(dayOf(14, night)).toBe('2026-11-11');
        expect(nextSlot(night)).toBe('2026-10-29T10:00');
    });
});
