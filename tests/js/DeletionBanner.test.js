import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import DeletionBanner from '@/layouts/DeletionBanner.vue';
import { initI18n } from '@/lib/i18n';
import { session } from '@/lib/session';

let routeName = 'home';
vi.mock('vue-router', async (original) => ({ ...(await original()), useRoute: () => ({ name: routeName }) }));

beforeAll(async () => {
    await initI18n(['en'], 'en');
});

afterEach(() => {
    session.me = null;
    routeName = 'home';
});

const mountBanner = () => mount(DeletionBanner, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('DeletionBanner', () => {
    it('shows nothing while no deletion waits', () => {
        session.me = { user: { deletion_due_at: null } };
        expect(mountBanner().html()).not.toContain('role="status"');
    });

    it('says when the account will be deleted and how to keep it', () => {
        session.me = { user: { deletion_due_at: '2026-11-04T09:00:00+00:00' } };
        const text = mountBanner().text();
        expect(text).toContain('Your account will be deleted on');
        expect(text).toContain('2026');
        expect(text).toContain('Keep my account');
    });

    it('stays out of the way on My account, which shows it in full', () => {
        routeName = 'account';
        session.me = { user: { deletion_due_at: '2026-11-04T09:00:00+00:00' } };
        expect(mountBanner().html()).not.toContain('role="status"');
    });
});
