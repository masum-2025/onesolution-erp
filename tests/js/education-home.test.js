import { describe, expect, it } from 'vitest';
import { familyWork } from '../../Modules/Education/resources/js/lib';

describe('education home', () => {
    it('keeps only the work of the education family that is on here', () => {
        const items = [
            { key: 'education.admissions', count: 2 },
            { key: 'education_fees.discounts_waiting', count: 1 },
            { key: 'education_exams.marks_due', count: 3 },
            { key: 'hrm.documents_expiring', count: 4 },
            { key: 'two_factor', count: 1 },
            // A module whose name starts like a family member's is not one.
            { key: 'education_feesy.other', count: 1 },
        ];
        expect(familyWork(items, ['education', 'education_fees']).map((item) => item.key)).toEqual(['education.admissions', 'education_fees.discounts_waiting']);
        expect(familyWork(items, [])).toEqual([]);
    });
});
