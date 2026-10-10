import { describe, expect, it } from 'vitest';
import {
    ADMISSION_NEXT,
    admissionTone,
    byLevel,
    canAdmit,
    decisionTotals,
    emptyGuardian,
    guardianToApi,
    guardianToForm,
    importColumns,
    prepareImport,
    promotionTone,
    fieldsToApi,
    fieldsToForm,
    fieldText,
    fullness,
    hue,
    initials,
    keyFrom,
    newOpId,
    phoneText,
    statusTone,
    stepOfError,
    suggestSessions,
} from '../../Modules/Education/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import { readCsv } from '../../resources/js/lib/csv.js';
import { readCsv as crmReadCsv } from '../../Modules/Crm/resources/js/lib.js';
import en from '../../Modules/Education/resources/js/locales/en/education.json';
import bn from '../../Modules/Education/resources/js/locales/bn/education.json';

function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('education screens', () => {
    it('registers its screens behind the education module', () => {
        const routes = moduleRoutes.filter((route) => route.meta.module === 'education');
        expect(routes.map((route) => route.name)).toEqual(
            expect.arrayContaining(['education', 'education-students', 'education-student', 'education-sections', 'education-section', 'education-structure', 'education-fields', 'education-admissions', 'education-admission', 'education-promotions', 'education-promotion', 'education-import']),
        );
        expect(routes.every((route) => route.meta.ns.includes('education'))).toBe(true);
    });

    it('speaks Bangla and English with the same keys', () => {
        expect(keys(bn).sort()).toEqual(keys(en).sort());
    });

    it('makes avatar initials and a steady colour from a name', () => {
        expect(initials('Rahim Uddin')).toBe('RU');
        expect(initials('  rahim  ')).toBe('R');
        expect(initials('রহিম')).toBe('র');
        expect(initials('')).toBe('?');
        expect(hue('Rahim Uddin')).toBe(hue('Rahim Uddin'));
        expect(hue('Rahim Uddin', 8)).toBeGreaterThanOrEqual(0);
        expect(hue('Rahim Uddin', 8)).toBeLessThan(8);
    });

    it('says how full a section is with a word, never colour alone', () => {
        expect(fullness(10, 40)).toMatchObject({ percent: 25, left: 30, state: 'open', tone: 'ok' });
        expect(fullness(30, 40)).toMatchObject({ state: 'filling', tone: 'brand' });
        expect(fullness(37, 40)).toMatchObject({ state: 'nearly_full', tone: 'warn', left: 3 });
        expect(fullness(40, 40)).toMatchObject({ state: 'full', tone: 'bad', left: 0, percent: 100 });
        expect(fullness(0, 0)).toMatchObject({ state: 'full', percent: 0 });
        for (const state of ['open', 'filling', 'nearly_full']) expect(en.capacity[`${state}_other`]).toBeTruthy();
        expect(en.capacity.full).toBeTruthy();
    });

    it('shows statuses and Bangladesh phone numbers the local way', () => {
        expect(statusTone('active')).toBe('ok');
        expect(statusTone('unknown')).toBe('neutral');
        expect(phoneText('+8801711000000')).toBe('01711-000000');
        expect(phoneText('+441234567890')).toBe('+441234567890');
        expect(phoneText(null)).toBe('');
    });

    it('turns own fields into API values and back, clearing wiped ones when changing', () => {
        const fields = [
            { key: 'blood_group', type: 'choice', options: [{ value: 'b_pos', label: { en: 'B+' } }] },
            { key: 'hobbies', type: 'multi_choice', options: [{ value: 'art', label: { en: 'Art' } }, { value: 'music', label: { en: 'Music' } }] },
            { key: 'transport', type: 'yes_no' },
            { key: 'note', type: 'text' },
        ];
        const form = { blood_group: 'b_pos', hobbies: [], transport: 'no', note: '' };
        expect(fieldsToApi(fields, form)).toEqual({ blood_group: 'b_pos', transport: false });
        expect(fieldsToApi(fields, form, { clear: true })).toEqual({ blood_group: 'b_pos', hobbies: null, transport: false, note: null });
        expect(fieldsToForm(fields, { transport: true, hobbies: ['art'] })).toEqual({ transport: 'yes', hobbies: ['art'] });

        const label = (value) => (typeof value === 'string' ? value : value.en);
        expect(fieldText(fields[0], 'b_pos', label)).toBe('B+');
        expect(fieldText(fields[1], ['art', 'music'], label)).toBe('Art, Music');
        expect(fieldText(fields[2], true, label)).toBe('yes');
        expect(fieldText(fields[3], '', label)).toBe('—');
    });

    it('makes a field key from its English label', () => {
        expect(keyFrom('Blood group')).toBe('blood_group');
        expect(keyFrom('  2nd language ')).toBe('nd_language');
        expect(keyFrom('A')).toBe('');
        expect(keyFrom('x'.repeat(60))).toHaveLength(40);
        expect(keyFrom('Previous school!')).toMatch(/^[a-z][a-z0-9_]{1,39}$/);
    });

    it('suggests sessions for a year, its semesters or terms', () => {
        expect(suggestSessions('year', 1, '2026-01-01', '2026-12-31')).toEqual([{ sequence: 1, starts_on: '2026-01-01', ends_on: '2026-12-31' }]);
        expect(suggestSessions('semester', 2, '2026-01-01', '2026-12-31')).toEqual([
            { sequence: 1, starts_on: '2026-01-01', ends_on: '2026-06-30' },
            { sequence: 2, starts_on: '2026-07-01', ends_on: '2026-12-31' },
        ]);
        expect(suggestSessions('term', 3, '2026-07-01', '2027-06-30').map((session) => session.starts_on)).toEqual(['2026-07-01', '2026-11-01', '2027-03-01']);
        expect(suggestSessions('year', 1, '2026-12-31', '2026-01-01')).toEqual([]);
    });

    it('groups sections by class in program order, summing seats', () => {
        const sections = [
            { id: 'b', level_id: 'c7', taken: 30, capacity: 40 },
            { id: 'a', level_id: 'c6', taken: 40, capacity: 40 },
            { id: 'c', level_id: 'c6', taken: 5, capacity: 40 },
        ];
        const rank = (id) => ({ c6: 6, c7: 7 })[id];
        const groups = byLevel(sections, rank);
        expect(groups.map((group) => group.level_id)).toEqual(['c6', 'c7']);
        expect(groups[0]).toMatchObject({ taken: 45, capacity: 80 });
        expect(groups[0].sections.map((section) => section.id)).toEqual(['a', 'c']);
    });

    it('opens the step of a form where the server found a problem', () => {
        const steps = [
            { key: 'student', prefixes: ['name', 'extra'] },
            { key: 'guardians', prefixes: ['guardians'] },
            { key: 'place', prefixes: ['enrollment'] },
        ];
        expect(stepOfError(['guardians.0.phone'], steps)).toBe('guardians');
        expect(stepOfError(['enrollment.section_id', 'name'], steps)).toBe('place');
        expect(stepOfError(['extra.blood_group'], steps)).toBe('student');
        expect(stepOfError(['nameless'], steps)).toBe('student');
    });

    it('makes a new op id each time', () => {
        expect(newOpId()).not.toBe(newOpId());
        expect(newOpId().length).toBeLessThanOrEqual(64);
    });

    it('follows the application decisions the server allows', () => {
        expect(ADMISSION_NEXT.applied).toEqual(['test', 'offered', 'rejected', 'withdrawn']);
        expect(ADMISSION_NEXT.offered).not.toContain('test');
        expect(ADMISSION_NEXT.admitted).toBeUndefined();
        expect(canAdmit('offered')).toBe(true);
        expect(canAdmit('rejected')).toBe(false);
        expect(admissionTone('rejected')).toBe('bad');
        expect(promotionTone('pending_approval')).toBe('warn');
        for (const status of ['applied', 'test', 'offered', 'admitted', 'rejected', 'withdrawn']) expect(en.admission_statuses[status]).toBeTruthy();
        for (const status of ['draft', 'pending_approval', 'applied', 'undone', 'cancelled']) expect(en.promotion_statuses[status]).toBeTruthy();
    });

    it('counts the decisions of a promotion list', () => {
        expect(decisionTotals([{ decision: 'promote' }, { decision: 'promote' }, { decision: 'leave' }, { decision: 'other' }])).toEqual({ promote: 2, repeat: 0, leave: 1, graduate: 0 });
        expect(decisionTotals(null)).toEqual({ promote: 0, repeat: 0, leave: 0, graduate: 0 });
    });

    it('sends guardians the way the server takes them, national ids only when allowed', () => {
        const fields = [{ key: 'income', type: 'number' }];
        const guardian = { ...emptyGuardian('mother'), name: ' Rafia ', phone: '01911000000', national_id: '123', is_primary: true, extra: { income: '5000' } };
        expect(guardianToApi(guardian, fields)).toEqual({ relation: 'mother', name: 'Rafia', phone: '01911000000', email: null, occupation: null, is_primary: true, can_pick_up: true, receives_notices: true, extra: { income: '5000' } });
        expect(guardianToApi(guardian, fields, { sensitive: true, links: false })).toMatchObject({ national_id: '123' });
        expect(guardianToApi(guardian, fields, { links: false })).not.toHaveProperty('can_pick_up');
        expect(guardianToForm({ name: 'Rafiq', relation: 'father', extra: { income: 10 } }, fields)).toMatchObject({ name: 'Rafiq', relation: 'father', phone: '', extra: { income: 10 } });
    });

    it('reads a spreadsheet and keeps only the columns that may be imported', () => {
        expect(crmReadCsv).toBe(readCsv);
        const fields = [{ key: 'blood_group', is_sensitive: false }, { key: 'religion', is_sensitive: true }];
        expect(importColumns(fields, false)).not.toContain('birth_registration_no');
        expect(importColumns(fields, false)).toContain('blood_group');
        expect(importColumns(fields, false)).not.toContain('religion');
        expect(importColumns(fields, true)).toEqual(expect.arrayContaining(['date_of_birth', 'religion']));

        const rows = readCsv('﻿Name;Guardian Phone;Blood_group;Hobby;Birth_registration_no\r\nRahim;01711000000;B+;chess;2014\r\nKarim;;;;\n');
        const prepared = prepareImport(rows, importColumns(fields, false));
        expect(prepared.hasName).toBe(true);
        expect(prepared.unknown).toEqual(['hobby', 'birth_registration_no']);
        expect(prepared.rows).toEqual([{ name: 'Rahim', guardian_phone: '01711000000', blood_group: 'B+' }, { name: 'Karim' }]);
        expect(prepareImport(readCsv('student,class\nX,6'), ['name']).hasName).toBe(false);
    });
});
