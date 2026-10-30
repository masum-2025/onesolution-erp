import { describe, expect, it } from 'vitest';
import { amountToMinor, columns, groupMine, initials, progress, projectTone, quantityToMilli } from '../../Modules/Projects/resources/js/lib';
import { moduleRoutes } from '../../resources/js/modules.js';
import en from '../../Modules/Projects/resources/js/locales/en/projects.json';
import bn from '../../Modules/Projects/resources/js/locales/bn/projects.json';

const keys = (object, prefix = '') => Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`])).sort();

describe('projects screens', () => {
    it('registers its screens with the app shell, literal paths before the project', () => {
        const routes = moduleRoutes.filter((route) => route.meta.module === 'projects');
        expect(routes.map((route) => route.name)).toEqual(['projects', 'projects-mine', 'projects-templates', 'project']);
        expect(routes.findIndex((route) => route.path === 'projects/my-tasks')).toBeLessThan(routes.findIndex((route) => route.path === 'projects/:id'));
    });

    it('says everything in Bangla and English', () => {
        expect(keys(bn)).toEqual(keys(en));
    });

    it('works out progress and lays tasks out by status in their order', () => {
        expect(progress({ total: 3, done: 1 })).toBe(33);
        expect(progress({ total: 0, done: 0 })).toBe(0);
        const board = columns([{ id: 'b', status: 'todo', sort_order: 2 }, { id: 'a', status: 'todo', sort_order: 1 }, { id: 'c', status: 'done', sort_order: 3 }]);
        expect(board.todo.map((task) => task.id)).toEqual(['a', 'b']);
        expect(board.done.map((task) => task.id)).toEqual(['c']);
        expect(board.blocked).toEqual([]);
        expect(projectTone('active')).toBe('ok');
    });

    it('groups my tasks: overdue, this week, later, no due day', () => {
        const groups = groupMine([
            { id: 'late', due_on: '2026-10-20', status: 'doing' },
            { id: 'soon', due_on: '2026-11-03', status: 'todo' },
            { id: 'later', due_on: '2026-12-01', status: 'todo' },
            { id: 'free', due_on: null, status: 'todo' },
        ], '2026-10-29');
        expect(Object.fromEntries(Object.entries(groups).map(([key, tasks]) => [key, tasks.map((task) => task.id)])))
            .toEqual({ overdue: ['late'], week: ['soon'], later: ['later'], none: ['free'] });
    });

    it('reads typed money and quantities, Bangla digits too', () => {
        expect(amountToMinor('৪৫,০০০', 'BDT')).toBe(4500000);
        expect(amountToMinor('12.5', 'BDT')).toBe(1250);
        expect(amountToMinor('-1', 'BDT')).toBeNull();
        expect(amountToMinor('1.234', 'BDT')).toBeNull();
        expect(quantityToMilli('১.৫')).toBe(1500);
        expect(quantityToMilli('2')).toBe(2000);
        expect(quantityToMilli('0')).toBeNull();
        expect(quantityToMilli('1.2345')).toBeNull();
    });

    it('marks people with up to two letters', () => {
        expect(initials('Ayesha Siddika Rahman')).toBe('AR');
        expect(initials('karim')).toBe('KA');
        expect(initials('')).toBe('?');
    });
});
