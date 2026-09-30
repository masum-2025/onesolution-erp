import { describe, expect, it } from 'vitest';
import { AUDIT_AREAS, auditQuery, isoDay, lastDays, periodLength } from '@/lib/auditPeriod';

describe('audit periods', () => {
    it('writes calendar days without shifting to UTC', () => {
        expect(isoDay(new Date(2026, 0, 5, 23, 30))).toBe('2026-01-05');
    });

    it('counts the last days including today, across month ends', () => {
        expect(lastDays(7, new Date(2026, 9, 3))).toEqual({ from: '2026-09-27', to: '2026-10-03' });
        expect(lastDays(1, new Date(2026, 9, 3))).toEqual({ from: '2026-10-03', to: '2026-10-03' });
    });

    it('measures a period with both ends included', () => {
        expect(periodLength({ from: '2026-09-01', to: '2026-09-30' })).toBe(30);
        expect(periodLength({ from: '2026-10-01', to: '2026-09-30' })).toBe(0);
        expect(periodLength({ from: '2026-10-01' })).toBe(0);
    });

    it('sends only the filters that are set', () => {
        expect(auditQuery({ from: '2026-09-01', to: '', action: 'all', actor: null, page: 2 })).toEqual({ from: '2026-09-01', page: 2 });
    });

    it('offers plain area names only (no dots, safe for the API filter)', () => {
        expect(AUDIT_AREAS.every((area) => /^[a-z_]+$/.test(area))).toBe(true);
    });
});
