import { describe, expect, it } from 'vitest';
import { addDays, addMonths, countStates, holidayOn, monthDays, nextStatus, periodAt, statusTone, weekdayKey } from '../../Modules/StudentAttendance/resources/js/lib';

describe('students\' attendance helpers', () => {
    it('moves a student through the states with one tap each, and colours them with a letter beside', () => {
        expect(['present', 'absent', 'late', 'leave'].map(nextStatus)).toEqual(['absent', 'late', 'leave', 'present']);
        expect(statusTone('absent')).toBe('bad');
        expect(countStates([{ status: 'present' }, { status: 'absent' }, { status: 'absent' }])).toEqual({ present: 1, absent: 2, late: 0, leave: 0 });
    });

    it('moves days and months without time zones getting in the way', () => {
        expect(addDays('2026-03-01', -1)).toBe('2026-02-28');
        expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
        expect(addMonths('2026-01', -1)).toBe('2025-12');
        expect(monthDays('2026-02')).toHaveLength(28);
        expect(monthDays('2028-02').at(-1)).toBe('2028-02-29');
        expect(weekdayKey('2026-03-06')).toBe('fri');
    });

    it('picks the period running now, else the next, else the last', () => {
        const periods = [
            { id: 'p1', starts_at: '08:00', ends_at: '08:45' },
            { id: 'p2', starts_at: '08:45', ends_at: '09:30' },
        ];
        expect(periodAt(periods, '08:50').id).toBe('p2');
        expect(periodAt(periods, '07:30').id).toBe('p1');
        expect(periodAt(periods, '15:00').id).toBe('p2');
        expect(periodAt([], '08:00')).toBeNull();
    });

    it('finds the holiday of a day for a class', () => {
        const holidays = [
            { id: 'all', starts_on: '2026-03-25', ends_on: '2026-03-27', level_ids: null },
            { id: 'c7', starts_on: '2026-03-10', ends_on: '2026-03-10', level_ids: ['class7'] },
        ];
        expect(holidayOn(holidays, '2026-03-26').id).toBe('all');
        expect(holidayOn(holidays, '2026-03-10', 'class6')).toBeNull();
        expect(holidayOn(holidays, '2026-03-10', 'class7').id).toBe('c7');
    });
});
