import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { prefetchPath, prefetchWhenIdle, resetPrefetch, savingData } from '@/lib/prefetch';

// PERF-1: a page's code is fetched before it is opened (pointing at its link, or idle time).

const Loaded = { template: '<div />' };

function routerWith(pages) {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', component: Loaded },
            ...Object.entries(pages).map(([path, component]) => ({ path, component })),
            { path: '/:pathMatch(.*)*', component: Loaded },
        ],
    });
}

beforeEach(() => {
    resetPrefetch();
    vi.useRealTimers();
});

describe('prefetchPath', () => {
    it('fetches a lazy page once, and only in-app paths', async () => {
        const orders = vi.fn(() => Promise.resolve({ default: Loaded }));
        const lazyOrders = () => orders();
        const router = routerWith({ '/online-orders': lazyOrders });

        prefetchPath(router, '/online-orders');
        prefetchPath(router, '/online-orders');
        await Promise.resolve();
        await Promise.resolve();
        expect(orders).toHaveBeenCalledTimes(1);

        prefetchPath(router, 'https://evil.example/x');
        prefetchPath(router, '//evil.example/x');
        prefetchPath(router, null);
        expect(orders).toHaveBeenCalledTimes(1);
    });

    it('tries again after a failed fetch (offline)', async () => {
        const page = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue({ default: Loaded });
        const router = routerWith({ '/hrm': () => page() });

        prefetchPath(router, '/hrm');
        await new Promise((resolve) => setTimeout(resolve, 0));
        prefetchPath(router, '/hrm');
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(page).toHaveBeenCalledTimes(2);
    });
});

describe('prefetchWhenIdle', () => {
    it('fetches the first pages when idle, not on a slow or data-saving connection', async () => {
        vi.useFakeTimers();
        const pages = { '/a': vi.fn(() => Promise.resolve({ default: Loaded })), '/b': vi.fn(() => Promise.resolve({ default: Loaded })), '/c': vi.fn(() => Promise.resolve({ default: Loaded })) };
        const router = routerWith(Object.fromEntries(Object.entries(pages).map(([path, page]) => [path, () => page()])));

        prefetchWhenIdle(router, ['/a', '/b', '/c'], { limit: 2, connection: { saveData: true } });
        prefetchWhenIdle(router, ['/a'], { connection: { effectiveType: '2g' } });
        await vi.advanceTimersByTimeAsync(5000);
        expect(pages['/a']).not.toHaveBeenCalled();

        prefetchWhenIdle(router, ['/a', '/b', '/c'], { limit: 2, connection: { effectiveType: '4g' } });
        await vi.advanceTimersByTimeAsync(5000);
        expect(pages['/a']).toHaveBeenCalledTimes(1);
        expect(pages['/b']).toHaveBeenCalledTimes(1);
        expect(pages['/c']).not.toHaveBeenCalled();
    });

    it('knows a data-saving connection', () => {
        expect(savingData({ saveData: true })).toBe(true);
        expect(savingData({ effectiveType: '3g' })).toBe(true);
        expect(savingData({ effectiveType: '4g' })).toBe(false);
        expect(savingData(undefined)).toBe(false);
    });
});
