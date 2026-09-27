import { describe, expect, it } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { menuLink } from '@/lib/menu';
import en from '@/locales/en/payments.json';
import bn from '@/locales/bn/payments.json';

function keys(object, prefix = '') {
    return Object.entries(object).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('online payments screen', () => {
    it('has every text in Bangla and English', () => {
        expect(keys(bn).sort()).toEqual(keys(en).sort());
    });

    it('links a module menu entry to its own screen, else to the coming-soon page', () => {
        const blank = { template: '<div />' };
        const router = createRouter({
            history: createMemoryHistory(),
            routes: [
                { path: '/online-payments', name: 'online-payments', component: blank },
                { path: '/apps/:module/:item', name: 'module-app', component: blank },
                { path: '/:pathMatch(.*)*', name: 'not-found', component: blank },
            ],
        });

        expect(menuLink({ module: 'online_payments', key: 'online_payments', route: '/online-payments' }, router)).toBe('/online-payments');
        expect(menuLink({ module: 'crm', key: 'crm', route: '/crm' }, router)).toBe('/apps/crm/crm');
        expect(menuLink({ module: 'hrm', key: 'hrm' }, router)).toBe('/apps/hrm/hrm');
    });
});
