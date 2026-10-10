import { describe, expect, it } from 'vitest';
import { byLevel, creditState, credits, itemTone, offeringAction, outcomeTone, portalBlock, registrationTone, seats, windowState } from '../../Modules/CourseRegistration/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/CourseRegistration/resources/js/locales/en/course_registration.json';
import bn from '../../Modules/CourseRegistration/resources/js/locales/bn/course_registration.json';

function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('course registration screens', () => {
    it('registers its screens behind the module', () => {
        const routes = moduleRoutes.filter((route) => route.meta.module === 'course_registration');
        expect(routes.map((route) => route.name)).toEqual(['crs-offerings', 'crs-offering', 'crs-registrations', 'crs-registration', 'crs-portal']);
        expect(routes.find((route) => route.name === 'crs-portal').meta.portal).toBe(true);
        expect(routes.every((route) => route.meta.ns.includes('course_registration'))).toBe(true);
    });

    it('speaks Bangla and English with the same keys', () => {
        expect(keys(bn).sort()).toEqual(keys(en).sort());
    });

    it('shows credits kept in hundredths', () => {
        expect(credits(300)).toBe('3');
        expect(credits(150)).toBe('1.5');
        expect(credits(1525)).toBe('15.25');
        expect(credits(0)).toBe('0');
        expect(credits(-50)).toBe('-0.5');
    });

    it('says where the credits stand against the rules', () => {
        const rules = { min_credits_centi: 900, max_credits_centi: 1800, overload_credits_centi: 300 };
        expect(creditState(600, rules)).toMatchObject({ state: 'under', tone: 'warn' });
        expect(creditState(1500, rules)).toMatchObject({ state: 'ok', tone: 'ok', limit: 2100 });
        expect(creditState(1950, rules)).toMatchObject({ state: 'overload', tone: 'warn' });
        expect(creditState(2400, rules)).toMatchObject({ state: 'over', tone: 'bad', percent: 100 });
        expect(creditState(1800, rules).maxPercent).toBe(86);
        expect(creditState(5000, {})).toMatchObject({ state: 'ok', maxPercent: null });
    });

    it('reads the registration window of a day', () => {
        const window = { opens_on: '2026-01-05', closes_on: '2026-01-20', add_drop_until: '2026-01-31' };
        expect(windowState(null, '2026-01-10')).toBe('none');
        expect(windowState(window, '2026-01-01')).toBe('upcoming');
        expect(windowState(window, '2026-01-05')).toBe('open');
        expect(windowState(window, '2026-01-25')).toBe('add_drop');
        expect(windowState(window, '2026-02-01')).toBe('closed');
    });

    it('says how full a group is in words', () => {
        expect(seats({ taken: 10, capacity: 40 })).toMatchObject({ left: 30, state: 'open', tone: 'ok', percent: 25 });
        expect(seats({ taken: 37, capacity: 40, waiting: 2 })).toMatchObject({ left: 3, state: 'few', waiting: 2 });
        expect(seats({ taken: 40, capacity: 40 })).toMatchObject({ left: 0, state: 'full', tone: 'bad' });
        for (const state of ['open', 'few', 'full', 'waiting']) expect(en.seats[state]).toBeTruthy();
    });

    it('gives each status a tone', () => {
        expect(registrationTone('approved')).toBe('ok');
        expect(registrationTone('returned')).toBe('bad');
        expect(itemTone('waitlisted')).toBe('warn');
        expect(outcomeTone('failed')).toBe('bad');
        for (const status of ['draft', 'submitted', 'approved', 'returned']) expect(en.statuses[status]).toBeTruthy();
    });

    it('groups offerings by class, open-to-all last', () => {
        const groups = byLevel([
            { id: 1, level_id: null, subject: { code: 'ENG101' }, group_name: 'A' },
            { id: 2, level_id: 's2', subject: { code: 'CSE201' }, group_name: 'A' },
            { id: 3, level_id: 's1', subject: { code: 'CSE101' }, group_name: 'B' },
            { id: 4, level_id: 's1', subject: { code: 'CSE101' }, group_name: 'A' },
        ], (id) => ({ s1: 1, s2: 2 })[id]);
        expect(groups.map((group) => group.level_id)).toEqual(['s1', 's2', null]);
        expect(groups[0].offerings.map((offering) => offering.id)).toEqual([4, 3]);
    });

    it('tells a student in the portal why they cannot change their subjects', () => {
        const window = { opens_on: '2026-01-05', closes_on: '2026-01-20', add_drop_until: '2026-01-31' };
        const view = { own: true, can: { add: false }, rules: { self_registration: true }, window, today: '2026-01-10' };
        expect(portalBlock(null)).toBeNull();
        expect(portalBlock({ ...view, can: { add: true } })).toBeNull();
        expect(portalBlock({ ...view, own: false })).toBe('look_only');
        expect(portalBlock({ ...view, rules: { self_registration: false } })).toBe('self_off');
        expect(portalBlock({ ...view, window: null })).toBe('none');
        expect(portalBlock({ ...view, today: '2026-01-01' })).toBe('upcoming');
        expect(portalBlock({ ...view, today: '2026-01-25' })).toBe('add_drop');
        expect(portalBlock({ ...view, today: '2026-02-01' })).toBe('closed');
        for (const key of ['look_only', 'self_off', 'none', 'upcoming', 'closed', 'add_drop']) expect(en.portal.block[key]).toBeTruthy();
    });

    it('says what tapping an offered subject does, or why it cannot', () => {
        expect(offeringAction({ reason: null, waitlist: false }, true)).toEqual({ kind: 'add', reason: null });
        expect(offeringAction({ reason: null, waitlist: true }, true)).toEqual({ kind: 'waitlist', reason: null });
        expect(offeringAction({ reason: 'credits' }, true)).toEqual({ kind: 'blocked', reason: 'credits' });
        expect(offeringAction({ reason: null, waitlist: false }, false)).toEqual({ kind: 'blocked', reason: null });
        for (const reason of ['taken', 'prerequisites', 'full', 'credits']) expect(bn.portal.why[reason]).toBeTruthy();
    });
});
