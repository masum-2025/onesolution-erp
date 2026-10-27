import { describe, expect, it } from 'vitest';
import { bandName, cycleTone, nameIn, ratingName, scoreText, stageTone, stagesOf, weightsTotal } from '../../Modules/Appraisals/resources/js/lib';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Appraisals/resources/js/locales/en/appraisals.json';
import bn from '../../Modules/Appraisals/resources/js/locales/bn/appraisals.json';

const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`])).sort();

describe('appraisal helpers', () => {
    it('registers its screens with the app shell', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'appraisals').map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['appraisals-me', 'appraisals-team', 'appraisals-cycles', 'appraisals-cycle', 'appraisals-review', 'appraisals-competencies']));
    });

    it('says everything in Bangla and English', () => {
        expect(keys(bn)).toEqual(keys(en));
    });

    it('shows scores per mille as percent, and adds goal weights as typed', () => {
        expect(scoreText(680)).toBe('68%');
        expect(scoreText(683)).toBe('68.3%');
        expect(scoreText(null)).toBe('—');
        expect(weightsTotal([{ weight: '60' }, { weight: 30 }, { weight: '' }])).toBe(90);
    });

    it('leaves the self review out when the cycle has none', () => {
        expect(stagesOf({ self_review: true })).toEqual(['goals', 'self', 'manager', 'acknowledge', 'done']);
        expect(stagesOf({ self_review: false })).toEqual(['goals', 'manager', 'acknowledge', 'done']);
    });

    it('names ratings and bands from the rules, and tones each step', () => {
        const scale = [{ value: 1, name: { en: 'Low', bn: 'কম' } }, { value: 2, name: { en: 'High' } }];
        expect(ratingName(scale, 1, 'bn')).toBe('কম');
        expect(ratingName(scale, 2, 'bn')).toBe('High');
        expect(bandName([{ key: 'solid', name: { en: 'Solid' } }], 'solid', 'en')).toBe('Solid');
        expect(bandName([], 'gone', 'en')).toBe('gone');
        expect(nameIn(null, 'en')).toBe('');
        expect(stageTone('done')).toBe('ok');
        expect(cycleTone('open')).toBe('ok');
    });
});
