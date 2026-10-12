import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { bandFor, bandsComplete, cellText, centiText, gradePaper, nextStatuses, parseCenti, parsePercent, percentBp, pointText, readCell, sheetCounts, sheetProgress } from '../../Modules/EducationExams/resources/js/lib.js';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/EducationExams/resources/js/locales/en/education_exams.json';
import bn from '../../Modules/EducationExams/resources/js/locales/bn/education_exams.json';

function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

// The published GPA 5 scale (database/data/grading/bd_school_gpa5.php).
const gpa5 = [
    { min_percent_bp: 8000, letter: 'A+', point_centi: 500, is_pass: true },
    { min_percent_bp: 7000, letter: 'A', point_centi: 400, is_pass: true },
    { min_percent_bp: 6000, letter: 'A-', point_centi: 350, is_pass: true },
    { min_percent_bp: 5000, letter: 'B', point_centi: 300, is_pass: true },
    { min_percent_bp: 4000, letter: 'C', point_centi: 200, is_pass: true },
    { min_percent_bp: 3300, letter: 'D', point_centi: 100, is_pass: true },
    { min_percent_bp: 0, letter: 'F', point_centi: 0, is_pass: false },
];

describe('exam screens', () => {
    it('registers its screens behind the module, fixed paths before an exam id', () => {
        const routes = moduleRoutes.filter((route) => route.meta.module === 'education_exams');
        expect(routes.map((route) => route.name)).toEqual(['exams-list', 'exams-my-sheets', 'exams-grading', 'exams-sheet', 'exams-exam']);
        expect(routes.every((route) => route.meta.ns.includes('education_exams') && !route.meta.portal)).toBe(true);
    });

    it('speaks Bangla and English with the same keys, and has every key the screens use', () => {
        expect(keys(bn).sort()).toEqual(keys(en).sort());
        const known = new Set(keys(en));
        const dir = join(__dirname, '../../Modules/EducationExams/resources/js');
        const files = ['pages', 'components'].flatMap((sub) => readdirSync(join(dir, sub)).map((name) => join(dir, sub, name))).concat([join(dir, 'labels.js')]);
        const used = files.flatMap((file) => [...readFileSync(file, 'utf8').matchAll(/'education_exams\.([a-z_.]+)'/g)].map((match) => match[1]));
        expect(used.filter((key) => !known.has(key))).toEqual([]);
    });

    it('reads typed marks in English or Bangla digits as hundredths, never floats', () => {
        expect(parseCenti('82.5')).toBe(8250);
        expect(parseCenti('৮২.৫')).toBe(8250);
        expect(parseCenti('0')).toBe(0);
        expect(parseCenti('12.345')).toBeNull();
        expect(parseCenti('-5')).toBeNull();
        expect(parseCenti('abc')).toBeNull();
        expect(centiText(8250)).toBe('82.5');
        expect(centiText(7000)).toBe('70');
        expect(centiText(5)).toBe('0.05');
        expect(pointText(417)).toBe('4.17');
        expect(parsePercent('33.5%')).toBe(3350);
        expect(parsePercent('101')).toBeNull();
    });

    it('reads a cell: marks within full marks, "a" for absent, empty clears', () => {
        expect(readCell('70', 7000)).toEqual({ kind: 'marks', centi: 7000 });
        expect(readCell('70.5', 7000)).toEqual({ kind: 'invalid', centi: 7050, reason: 'too_high' });
        expect(readCell('A', 7000)).toEqual({ kind: 'absent', centi: null });
        expect(readCell('অ', 7000)).toEqual({ kind: 'absent', centi: null });
        expect(readCell('  ', 7000)).toEqual({ kind: 'empty', centi: null });
        expect(readCell('7o', 7000)).toEqual({ kind: 'invalid', centi: null, reason: 'format' });
        expect(cellText({ marks_centi: 4550, absent: false })).toBe('45.5');
        expect(cellText({ marks_centi: null, absent: true })).toBe('a');
        expect(cellText(null)).toBe('');
    });

    it('grades as the server does (ExamMath)', () => {
        expect(percentBp(6600, 7500)).toBe(8800);
        expect(percentBp(1, 3)).toBe(3333);
        expect(percentBp(500, 0)).toBe(0);
        expect(bandFor(gpa5, 7999).letter).toBe('A');
        expect(bandFor(gpa5, 3299).letter).toBe('F');
        expect(bandFor([], 5000)).toBeNull();

        const parts = (cq, mcq, absent = false) => [
            { full_marks_centi: 7000, pass_marks_centi: 2300, marks_centi: absent ? null : cq, absent },
            { full_marks_centi: 3000, pass_marks_centi: 1000, marks_centi: absent ? null : mcq, absent },
        ];
        expect(gradePaper(parts(6000, 2500), 3300, gpa5)).toMatchObject({ marks_centi: 8500, percent_bp: 8500, letter: 'A+', point_centi: 500, passed: true });
        // 65 % overall, but MCQ below its pass mark: F.
        expect(gradePaper(parts(6000, 500), 3300, gpa5)).toMatchObject({ marks_centi: 6500, letter: 'F', point_centi: 0, passed: false });
        expect(gradePaper(parts(0, 0, true), 3300, gpa5)).toMatchObject({ absent: true, passed: false, letter: 'F' });
    });

    it('checks that bands make a whole scheme', () => {
        expect(bandsComplete(gpa5)).toBe(true);
        expect(bandsComplete([{ min_percent_bp: 4000 }])).toBe(false);
        expect(bandsComplete([{ min_percent_bp: 0 }, { min_percent_bp: 0 }])).toBe(false);
        expect(bandsComplete([{ min_percent_bp: 0 }, { min_percent_bp: null }])).toBe(false);
        expect(bandsComplete([])).toBe(false);
    });

    it('counts a student done with every part entered or any part absent, as handing in does', () => {
        const parts = [{ id: 'cq' }, { id: 'mcq' }];
        const students = [{ id: 'a' }, { id: 'b' }, { id: 'c' }, { id: 'd' }];
        const cells = {
            a: { cq: { kind: 'marks' }, mcq: { kind: 'marks' } },
            b: { cq: { kind: 'absent' } },
            c: { cq: { kind: 'marks' }, mcq: { kind: 'empty' } },
            d: { cq: { kind: 'marks' }, mcq: { kind: 'invalid' } },
        };
        expect(sheetProgress(students, parts, cells)).toEqual({ done: 2, total: 4, missing: 2 });
    });

    it('moves an exam only where the server allows, and counts its sheets', () => {
        expect(nextStatuses('draft')).toEqual(['marking']);
        expect(nextStatuses('marking')).toEqual(['closed']);
        expect(nextStatuses('closed')).toEqual(['marking']);
        expect(nextStatuses('published')).toEqual([]);
        expect(sheetCounts({ open: 2, approved: 3, submitted: 1 })).toEqual({ total: 6, approved: 3, submitted: 1, open: 2, returned: 0 });
        expect(sheetCounts(undefined).total).toBe(0);
    });
});
