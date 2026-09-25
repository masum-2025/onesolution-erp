import { describe, expect, it } from 'vitest';
import { conflictsIn, diffPermissions, partnersOf, permissionsOfRoles, toggleGroup } from '@/lib/permissions';

const pairs = [
    { first: 'payroll.run', second: 'payroll.approve' },
    { first: 'accounting.post', second: 'accounting.approve' },
];

describe('permission helpers', () => {
    it('finds broken separation-of-duties pairs', () => {
        expect(conflictsIn(['payroll.run', 'payroll.approve', 'hrm.view'], pairs)).toEqual([pairs[0]]);
        expect(conflictsIn(['payroll.run', 'accounting.approve'], pairs)).toEqual([]);
    });

    it('names the partners of a paired permission', () => {
        expect(partnersOf('payroll.approve', pairs)).toEqual(['payroll.run']);
        expect(partnersOf('hrm.view', pairs)).toEqual([]);
    });

    it('reports what changed', () => {
        expect(diffPermissions(['a.view', 'b.view'], ['b.view', 'c.view'])).toEqual({ added: ['c.view'], removed: ['a.view'] });
    });

    it('toggles only the permissions this person may change', () => {
        const group = {
            permissions: [
                { key: 'payroll.view', blocked_by: null },
                { key: 'payroll.run', blocked_by: null },
                { key: 'payroll.approve', blocked_by: 'not_held' },
            ],
        };

        expect(toggleGroup([], group)).toEqual(['payroll.run', 'payroll.view']);
        // A locked permission that is already there stays there.
        expect(toggleGroup(['payroll.approve', 'payroll.run', 'payroll.view'], group)).toEqual(['payroll.approve']);
    });

    it('adds up the permissions of chosen roles', () => {
        const roles = [
            { id: 'r1', permissions: ['hrm.view', 'payroll.run'] },
            { id: 'r2', permissions: ['hrm.view', 'payroll.approve'] },
        ];

        expect(permissionsOfRoles(roles, ['r1', 'r2'])).toEqual(['hrm.view', 'payroll.approve', 'payroll.run']);
        expect(conflictsIn(permissionsOfRoles(roles, ['r1', 'r2']), pairs)).toHaveLength(1);
    });
});
