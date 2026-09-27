import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ModeBanner from '@/layouts/ModeBanner.vue';
import { initI18n } from '@/lib/i18n';
import { session } from '@/lib/session';

vi.mock('vue-router', async (original) => ({ ...(await original()), useRouter: () => ({ push: vi.fn() }) }));

beforeAll(async () => {
    await initI18n(['en'], 'en');
});

afterEach(() => {
    vi.useRealTimers();
    session.me = null;
});

function context(extra) {
    return { type: 'organization', id: 'o1', name: 'Sunrise School', partner: { id: 'p1', name: 'House' }, mode: 'normal', mode_reason: null, mode_until: null, ...extra };
}

const mountBanner = () => mount(ModeBanner, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('ModeBanner', () => {
    it('shows nothing in a normal workspace', () => {
        session.me = { context: context() };
        expect(mountBanner().html()).not.toContain('role="status"');
    });

    it('counts down the minutes of support access and offers a way out', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-26T10:00:00Z'));
        session.me = { context: context({ mode: 'read_only', mode_reason: 'support', mode_until: '2026-09-26T10:30:00Z' }) };

        const wrapper = mountBanner();
        expect(wrapper.text()).toContain('You are viewing Sunrise School as support');
        expect(wrapper.text()).toContain('30 minutes left');
        expect(wrapper.text()).toContain('Leave');

        vi.setSystemTime(new Date('2026-09-26T10:29:30Z'));
        await vi.advanceTimersByTimeAsync(15000);
        expect(wrapper.text()).toContain('1 minute left');
        wrapper.unmount();
    });

    it('explains the grace period of a suspended provider in days, with an export button', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-26T10:00:00Z'));
        session.me = { context: context({ mode: 'read_only', mode_reason: 'partner_suspended', mode_until: '2026-10-06T10:00:00Z' }) };

        const text = mountBanner().text();
        expect(text).toContain('read-only for now');
        expect(text).toContain('10 days left');
        expect(text).toContain('Export data');
        expect(text).not.toContain('Leave');
    });

    it('explains a workspace read-only for an overdue bill and leads to paying it', () => {
        session.me = { context: context({ mode: 'read_only', mode_reason: 'payment_overdue' }) };

        const text = mountBanner().text();
        expect(text).toContain('read-only because a bill is overdue');
        expect(text).toContain('Pay now');
        expect(text).not.toContain('Export data');
    });

    it('says only export remains after the grace period', () => {
        session.me = { context: context({ mode: 'export_only', mode_reason: 'partner_suspended' }) };
        expect(mountBanner().text()).toContain('You can still export all your data');
    });
});
