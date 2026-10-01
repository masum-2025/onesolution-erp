import { describe, expect, it } from 'vitest';
import { addDays, availableSteps, changedDetails, hirePayload, initials, missingRequired, statusTone } from '../../Modules/Hrm/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';

describe('HRM screens', () => {
    it('registers its screens with the app shell, each a lazy page', () => {
        const names = moduleRoutes.map((route) => route.name);
        expect(names).toEqual(expect.arrayContaining(['hrm', 'hrm-hire', 'hrm-positions', 'hrm-employee']));
        moduleRoutes.forEach((route) => {
            expect(typeof route.component).toBe('function');
            expect(route.meta.ns).toContain(route.meta.module);
        });
    });

    it('shows statuses in their own tone and initials of any script', () => {
        expect(statusTone('active')).toBe('ok');
        expect(statusTone('probation')).toBe('warn');
        expect(statusTone('unknown')).toBe('neutral');
        expect(initials('rahima akter khan')).toBe('RA');
        expect(initials('  করিম   উদ্দিন ')).toBe('কউ');
        expect(initials('')).toBe('');
    });

    it('lists required details still empty, including address parts', () => {
        const form = { phone: ' ', national_id: '123', address: { line1: '', city: '' }, emergency_contact: { name: 'Mina' } };
        expect(missingRequired(form, ['phone', 'national_id', 'address', 'emergency_contact', 'email'])).toEqual(['phone', 'address', 'email']);
        expect(missingRequired(form, undefined)).toEqual([]);
    });

    it('counts probation days like the server', () => {
        expect(addDays('2026-10-01', 90)).toBe('2026-12-30');
        expect(addDays('2024-02-28', 1)).toBe('2024-02-29');
        expect(addDays('', 10)).toBeNull();
    });

    it('sends only filled details when hiring', () => {
        expect(
            hirePayload({ full_name: ' Rahima ', email: '', phone: '+880', address: { line1: 'Road 1', city: '' }, emergency_contact: { name: '', phone: '' }, manager_id: '' }),
        ).toEqual({ full_name: 'Rahima', phone: '+880', address: { line1: 'Road 1' } });
    });

    it('sends only changed details when editing, clearing with null', () => {
        expect(changedDetails({ phone: '+880', email: 'a@b.c', gender: null }, { phone: '+880', email: ' ', gender: 'female' })).toEqual({ email: null, gender: 'female' });
    });

    it('offers only the steps the status and permissions allow', () => {
        const all = { manage: true, exit: true };
        expect(availableSteps({ status: 'probation' }, all)).toEqual(['confirm', 'transfer', 'promote', 'notice', 'exit']);
        expect(availableSteps({ status: 'on_notice' }, all)).toEqual(['transfer', 'promote', 'exit']);
        expect(availableSteps({ status: 'exited' }, all)).toEqual(['rehire']);
        expect(availableSteps({ status: 'active' }, { manage: true, exit: false })).toEqual(['transfer', 'promote']);
        expect(availableSteps({ status: 'active' }, { manage: false, exit: false })).toEqual([]);
        expect(availableSteps(null, all)).toEqual([]);
    });
});
