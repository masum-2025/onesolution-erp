import { describe, expect, it } from 'vitest';
import {
    dayGrid,
    decimalToMicro,
    guessDeviceColumns,
    microToDecimal,
    positionFix,
    dayTone,
    durationParts,
    localDateTime,
    minutesToTime,
    monthDates,
    nextPunch,
    shiftHours,
    shiftMonth,
    statusCounts,
    STATUSES,
    timeToMinutes,
} from '../../Modules/Attendance/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Attendance/resources/js/locales/en/attendance.json';
import bn from '../../Modules/Attendance/resources/js/locales/bn/attendance.json';

describe('Attendance screens', () => {
    it('registers its screens with the app shell, each a lazy page', () => {
        const names = moduleRoutes.filter((route) => route.meta.module === 'attendance').map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['attendance', 'attendance-me', 'attendance-month', 'attendance-corrections', 'attendance-shifts', 'attendance-holidays', 'attendance-rosters', 'attendance-portal']));
        expect(moduleRoutes.find((route) => route.name === 'attendance-portal').meta.portal).toBe(true);
    });

    it('turns shift minutes into times and back', () => {
        expect(minutesToTime(540)).toBe('09:00');
        expect(minutesToTime(1320)).toBe('22:00');
        expect(minutesToTime(1440)).toBe('00:00');
        expect(timeToMinutes('09:05')).toBe(545);
        expect(timeToMinutes('7:30')).toBe(450);
        expect(timeToMinutes('24:00')).toBeNull();
        expect(timeToMinutes('')).toBeNull();
        expect(shiftHours({ start_minute: 1320, end_minute: 360 })).toEqual({ from: '22:00', to: '06:00', nextDay: true });
        expect(shiftHours({ start_minute: 540, end_minute: 1020 }).nextDay).toBe(false);
        expect(durationParts(505)).toEqual({ hours: 8, minutes: 25 });
        expect(durationParts(-3)).toEqual({ hours: 0, minutes: 0 });
    });

    it('lists the days of a month, up to today, and moves between months', () => {
        expect(monthDates('2026-02')).toHaveLength(28);
        expect(monthDates('2028-02')).toHaveLength(29);
        expect(monthDates('2026-10', '2026-10-03')).toEqual(['2026-10-01', '2026-10-02', '2026-10-03']);
        expect(shiftMonth('2026-01', -1)).toBe('2025-12');
        expect(shiftMonth('2026-12', 1)).toBe('2027-01');
    });

    it('writes now in the company timezone for a datetime field', () => {
        expect(localDateTime('Asia/Dhaka', new Date('2026-10-14T20:30:00Z'))).toBe('2026-10-15T02:30');
        expect(localDateTime('UTC', new Date('2026-10-14T20:30:00Z'))).toBe('2026-10-14T20:30');
    });

    it('groups days for the month grid, counts statuses and picks the next punch', () => {
        const days = [
            { employee_id: 'a', work_date: '2026-10-13', status: 'present' },
            { employee_id: 'a', work_date: '2026-10-14', status: 'late' },
            { employee_id: 'b', work_date: '2026-10-14', status: 'absent' },
        ];
        expect(dayGrid(days).a['2026-10-14'].status).toBe('late');
        expect(statusCounts(days)).toMatchObject({ present: 1, late: 1, absent: 1, weekend: 0 });
        expect(nextPunch(null)).toBe('in');
        expect(nextPunch({ first_in_at: '2026-10-14T03:00:00Z', last_out_at: null })).toBe('out');
        expect(nextPunch({ first_in_at: '2026-10-14T03:00:00Z', last_out_at: '2026-10-14T11:00:00Z' })).toBe('in');
    });

    it('labels every status in both languages, with a tone', () => {
        for (const status of STATUSES) {
            expect(en.status[status]).toBeTruthy();
            expect(bn.status[status]).toBeTruthy();
            expect(en.short[status]).toBeTruthy();
            expect(bn.short[status]).toBeTruthy();
            expect(dayTone(status)).not.toBe(undefined);
        }
        expect(dayTone('absent')).toBe('bad');
        expect(Object.keys(bn).sort()).toEqual(Object.keys(en).sort());
    });
});

describe('Attendance workplaces and machine files', () => {
    it('turns typed coordinates into millionths of a degree without floats, and back', () => {
        expect(decimalToMicro('23.810331')).toBe(23810331);
        expect(decimalToMicro('-0.5')).toBe(-500000);
        expect(decimalToMicro('90.4125215')).toBe(90412522);
        expect(decimalToMicro('90')).toBe(90000000);
        expect(decimalToMicro('abc')).toBeNull();
        expect(microToDecimal(23810331)).toBe('23.810331');
        expect(microToDecimal(-500000)).toBe('-0.500000');
        expect(positionFix({ latitude: 23.8103314, longitude: 90.4125206, accuracy: 18.6 })).toEqual({ latitude_micro: 23810331, longitude_micro: 90412521, accuracy_m: 19 });
    });

    it('guesses the columns of attendance machine files', () => {
        expect(guessDeviceColumns(['AC-No.', 'Name', 'Time', 'State'])).toEqual({ code: 'AC-No.', datetime: 'Time', date: '', time: '' });
        expect(guessDeviceColumns(['User ID', 'Date/Time'])).toMatchObject({ code: 'User ID', datetime: 'Date/Time', date: '' });
        expect(guessDeviceColumns(['Emp Code', 'Date', 'Time'])).toMatchObject({ code: 'Emp Code', date: 'Date', time: 'Time' });
    });

    it('registers the workplaces screen', () => {
        expect(moduleRoutes.find((route) => route.name === 'attendance-locations')?.meta.module).toBe('attendance');
    });
});
