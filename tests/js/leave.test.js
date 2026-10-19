import { describe, expect, it } from 'vitest';
import { covers, daysToHalves, halvesToDays, monthDays, shiftMonth, statusTone } from '../../Modules/Leave/resources/js/lib';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Leave/resources/js/locales/en/leave.json';
import bn from '../../Modules/Leave/resources/js/locales/bn/leave.json';

const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`])).sort();

describe('leave helpers', () => {
    it('registers its screens with the app shell, the portal page for portal members', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'leave').map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['leave-me', 'leave-requests', 'leave-calendar', 'leave-balances', 'leave-types', 'leave-portal']));
        expect(moduleRoutes.find((route) => route.name === 'leave-portal').meta.portal).toBe(true);
    });

    it('says everything in Bangla and English', () => {
        expect(keys(bn)).toEqual(keys(en));
    });

    it('turns half days into days and back, in whole halves', () => {
        expect(halvesToDays(5)).toBe(2.5);
        expect(halvesToDays(null)).toBe(0);
        expect(daysToHalves(2.5)).toBe(5);
        expect(daysToHalves('1')).toBe(2);
        expect(daysToHalves(0.3)).toBe(1);
    });

    it('walks the days and months of a calendar', () => {
        expect(monthDays('2026-02')).toHaveLength(28);
        expect(monthDays('2028-02').at(-1)).toBe('2028-02-29');
        expect(shiftMonth('2026-12', 1)).toBe('2027-01');
        expect(shiftMonth('2026-01', -1)).toBe('2025-12');
    });

    it('knows which days a request covers and how its status reads', () => {
        const request = { from_date: '2026-10-18', to_date: '2026-10-20' };
        expect(covers(request, '2026-10-18')).toBe(true);
        expect(covers(request, '2026-10-21')).toBe(false);
        expect(statusTone('approved')).toBe('ok');
        expect(statusTone('unknown')).toBe('neutral');
    });
});
