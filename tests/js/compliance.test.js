import { describe, expect, it } from 'vitest';
import { counts, expiryFrom, grid, stateTone } from '../../Modules/Compliance/resources/js/lib';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Compliance/resources/js/locales/en/compliance.json';
import bn from '../../Modules/Compliance/resources/js/locales/bn/compliance.json';

const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`])).sort();

describe('compliance screens', () => {
    it('registers its screens with the app shell', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'compliance').map((route) => route.name);
        expect(names).toEqual(['compliance', 'compliance-lists', 'compliance-import']);
    });

    it('says everything in Bangla and English', () => {
        expect(keys(bn)).toEqual(keys(en));
    });

    it('proposes the expiry as the day before the same day so many months on, keeping month ends', () => {
        expect(expiryFrom('2026-03-15', 12)).toBe('2027-03-14');
        expect(expiryFrom('2026-01-31', 1)).toBe('2026-02-27');
        expect(expiryFrom('2024-02-29', 12)).toBe('2025-02-27');
        expect(expiryFrom('', 12)).toBe('');
        expect(expiryFrom('2026-03-15', null)).toBe('');
    });

    it('lays the register out as factories by services, empty rows kept only when not filtering', () => {
        const factories = [{ id: 'c1' }, { id: 'b1' }, { id: 'c2' }];
        const services = [{ id: 's1' }, { id: 's2' }, { id: 's3' }];
        const lines = [{ id: 'l1', factory: { id: 'b1' }, service: { id: 's2' } }, { id: 'l2', factory: { id: 'c1' }, service: { id: 's1' } }];
        const all = grid(lines, factories, services);
        expect(all.columns.map((column) => column.id)).toEqual(['s1', 's2']);
        expect(all.rows.map((row) => row.cells.map((cell) => cell?.id ?? null))).toEqual([['l2', null], [null, 'l1'], [null, null]]);
        expect(grid(lines, factories, services, false).rows.map((row) => row.factory.id)).toEqual(['c1', 'b1']);
    });

    it('tones each state and counts every state', () => {
        expect(stateTone('expired')).toBe('bad');
        expect(stateTone('valid')).toBe('ok');
        expect(counts({ expired: 2 })).toEqual({ expired: 2, expiring: 0, valid: 0, unknown: 0 });
    });
});
