import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { moduleKeyOf, moduleOutsideRoutes, resetModuleRoutes, syncModuleRoutes } from '@/modules';

// FE-1: a module's screens are registered only where it is on, without the menu pages the person may not open.

const Page = { template: '<div />' };

const lists = {
    online_orders: vi.fn(async () => [
        { path: 'online-orders', name: 'online-orders', component: Page, meta: { module: 'online_orders' } },
        { path: 'online-orders/couriers', name: 'online-order-couriers', component: Page, meta: { module: 'online_orders' } },
        { path: 'online-orders/:id', name: 'online-order', component: Page, meta: { module: 'online_orders' } },
    ]),
    hrm: vi.fn(async () => [{ path: 'hrm', name: 'hrm', component: Page, meta: { module: 'hrm' } }]),
};

function makeRouter() {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/login', name: 'login', component: Page },
            { path: '/', name: 'app-shell', component: Page, children: [{ path: '', name: 'home', component: Page }, { path: ':pathMatch(.*)*', name: 'not-found', component: Page }] },
        ],
    });
}

const me = (overrides = {}) => ({ context: { type: 'organization', id: 'org-1' }, modules: ['online_orders'], denied_pages: [], ...overrides });
const at = (router, path) => router.resolve(path).name;

beforeEach(() => {
    resetModuleRoutes();
    Object.values(lists).forEach((load) => load.mockClear());
});

describe('syncModuleRoutes', () => {
    it('registers only the modules on here', async () => {
        const router = makeRouter();
        expect(await syncModuleRoutes(router, me(), { modules: lists })).toBe(true);

        expect(at(router, '/online-orders/couriers')).toBe('online-order-couriers');
        expect(at(router, '/online-orders/123')).toBe('online-order');
        expect(at(router, '/hrm')).toBe('not-found');
        expect(lists.hrm).not.toHaveBeenCalled();
    });

    it('leaves out the menu pages the person may not open, but keeps pages outside the menu', async () => {
        const router = makeRouter();
        await syncModuleRoutes(router, me({ denied_pages: ['/online-orders/couriers'] }), { modules: lists });

        expect(at(router, '/online-orders/couriers')).toBe('online-order');
        expect(router.hasRoute('online-order-couriers')).toBe(false);
        expect(at(router, '/online-orders')).toBe('online-orders');
    });

    it('changes nothing for the same answer, and swaps the screens for another organization', async () => {
        const router = makeRouter();
        await syncModuleRoutes(router, me(), { modules: lists });
        expect(await syncModuleRoutes(router, me(), { modules: lists })).toBe(false);

        expect(await syncModuleRoutes(router, me({ context: { type: 'organization', id: 'org-2' }, modules: ['hrm'] }), { modules: lists })).toBe(true);
        expect(at(router, '/hrm')).toBe('hrm');
        expect(router.hasRoute('online-orders')).toBe(false);
    });

    it('removes every module screen on sign-out and in the partner console', async () => {
        const router = makeRouter();
        await syncModuleRoutes(router, me(), { modules: lists });
        expect(await syncModuleRoutes(router, null, { modules: lists })).toBe(true);
        expect(router.hasRoute('online-orders')).toBe(false);

        await syncModuleRoutes(router, me(), { modules: lists });
        await syncModuleRoutes(router, { context: { type: 'partner', id: 'p-1' }, modules: [] }, { modules: lists });
        expect(router.hasRoute('online-orders')).toBe(false);
        // Signed out from the start: nothing to resolve again.
        resetModuleRoutes();
        expect(await syncModuleRoutes(makeRouter(), null, { modules: lists })).toBe(false);
    });

    it('loads the partner console only in a partner context (FE-2)', async () => {
        const partner = vi.fn(async () => [{ path: 'partner/plans', name: 'partner-plans', component: Page, meta: { context: 'partner' } }]);
        const router = makeRouter();

        await syncModuleRoutes(router, me(), { modules: lists, partner });
        expect(partner).not.toHaveBeenCalled();
        expect(at(router, '/partner/plans')).toBe('not-found');

        expect(await syncModuleRoutes(router, { context: { type: 'partner', id: 'p-1' } }, { modules: lists, partner })).toBe(true);
        expect(at(router, '/partner/plans')).toBe('partner-plans');
        expect(router.hasRoute('online-orders')).toBe(false);

        // Back in a company: the console's screens go.
        await syncModuleRoutes(router, me(), { modules: lists, partner });
        expect(router.hasRoute('partner-plans')).toBe(false);
    });

    it('keeps every partner console screen in its own file, none in the first load', async () => {
        const { default: routes } = await import('@/partnerRoutes.js');
        expect(routes.length).toBe(25);
        expect(routes.every((route) => route.meta.context === 'partner' && route.path.startsWith('partner/'))).toBe(true);
        expect(routes.every((route) => typeof route.component === 'function')).toBe(true);
    });

    it('skips a module whose list cannot be fetched (offline) and tries again later', async () => {
        const router = makeRouter();
        lists.hrm.mockRejectedValueOnce(new Error('offline'));
        await syncModuleRoutes(router, me({ modules: ['online_orders', 'hrm'] }), { modules: lists });
        expect(router.hasRoute('online-orders')).toBe(true);
        expect(router.hasRoute('hrm')).toBe(false);
    });
});

describe('module keys and public pages', () => {
    it('takes the key from the folder name', () => {
        expect(moduleKeyOf('../../Modules/OnlineOrders/resources/js/routes.js')).toBe('online_orders');
        expect(moduleKeyOf('../../Modules/Hrm/resources/js/routes.js')).toBe('hrm');
        expect(moduleKeyOf('../../Modules/EduWebsite/resources/js/routes.js')).toBe('edu_website');
    });

    it('keeps the public pages in the first load', () => {
        expect(moduleOutsideRoutes.map((route) => route.name)).toContain('education-verify');
        expect(moduleOutsideRoutes.every((route) => route.meta.outside && route.meta.public)).toBe(true);
    });
});
