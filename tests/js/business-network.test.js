import { describe, expect, it } from 'vitest';
import { indentOf, statusTone } from '../../Modules/BusinessNetwork/resources/js/api';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/BusinessNetwork/resources/js/locales/en/business_network.json';
import bn from '../../Modules/BusinessNetwork/resources/js/locales/bn/business_network.json';

const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`])).sort();

describe('business connections screens', () => {
    it('registers its screen with the app shell', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'business_network').map((route) => route.name);
        expect(names).toEqual(['connections']);
    });

    it('says everything in Bangla and English', () => {
        expect(keys(bn)).toEqual(keys(en));
    });

    it('tones each status and indents units below the shallowest one', () => {
        expect(statusTone('active')).toBe('ok');
        expect(statusTone('invited')).toBe('warn');
        expect(statusTone('unknown')).toBe('neutral');
        const units = [{ depth: 1 }, { depth: 2 }, { depth: 3 }];
        expect(indentOf(units, units[0])).toBe(0);
        expect(indentOf(units, units[2])).toBe(2);
    });
});
