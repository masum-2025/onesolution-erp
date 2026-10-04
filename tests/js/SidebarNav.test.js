import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import SidebarNav from '@/layouts/SidebarNav.vue';
import { initI18n } from '@/lib/i18n';
import { session } from '@/lib/session';
import { brand } from '@/lib/brand';
import AppHeader from '@/layouts/AppHeader.vue';

// The header's person menu loads the theme helper, which needs matchMedia (not in jsdom).
vi.mock('@/lib/theme', () => ({ theme: { preference: 'system', dark: false }, setTheme: () => {} }));

const Empty = { template: '<div />' };

beforeAll(async () => {
    await initI18n(['en'], 'en');
});

afterEach(() => {
    session.me = null;
    brand.band_colors = [];
    brand.side_band_color = null;
});

const HRM = {
    module: 'hrm',
    key: 'hrm',
    label: 'Human Resources',
    route: '/hrm',
    icon: 'users',
    order: 10,
    section: 'people',
    section_label: 'People',
    children: [
        { key: 'employees', label: 'Employees', route: '/hrm' },
        { key: 'positions', label: 'Positions', route: '/hrm/positions' },
    ],
};
const CRM = { module: 'crm', key: 'crm', label: 'Customer Relations', route: '/crm', icon: 'handshake', order: 20, section: 'business', section_label: 'Business', children: [] };

async function mountNav(path, props = {}, permissions = ['rules.approve']) {
    session.me = {
        user: { name: 'Rahima Akter' },
        context: { type: 'organization', id: 'o1', name: 'Sunrise', organization_type: 'company', membership_type: 'owner', partner: { name: 'House' } },
        can: Object.fromEntries(permissions.map((key) => [key, true])),
    };
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', component: Empty },
            { path: '/hrm', name: 'hrm', component: Empty },
            { path: '/hrm/positions', name: 'hrm-positions', component: Empty },
            { path: '/hrm/employees/:id', component: Empty },
            { path: '/approvals', component: Empty },
            { path: '/accounting', name: 'accounting', component: Empty },
            { path: '/accounting/sales', name: 'accounting-sales', component: Empty },
            { path: '/apps/:module/:key', name: 'module-app', component: Empty },
            { path: '/:any(.*)*', name: 'not-found', component: Empty },
        ],
    });
    await router.push(path);
    await router.isReady();
    const wrapper = mount(SidebarNav, {
        props: { menu: [HRM, CRM], ...props },
        global: { plugins: [router], stubs: { ContextSwitcher: true } },
        attachTo: document.body,
    });
    await flushPromises();
    return wrapper;
}

describe('SidebarNav', () => {
    it('groups module entries under their sections', async () => {
        const wrapper = await mountNav('/');
        const sections = wrapper.findAll('section').map((section) => section.attributes('aria-label'));

        expect(sections).toEqual(['Workspace', 'Administration', 'People', 'Business']);
        wrapper.unmount();
    });

    it('opens the module holding the current page and marks the sub-page', async () => {
        const wrapper = await mountNav('/hrm/employees/7');

        expect(wrapper.find('button[aria-expanded="true"]').text()).toContain('Human Resources');
        expect(wrapper.find('[aria-current="page"]').text()).toBe('Employees');
        wrapper.unmount();
    });

    it('lights one entry when a module has several (the longest matching link wins)', async () => {
        const books = { module: 'accounting', key: 'accounting', label: 'Accounting', route: '/accounting', icon: 'book', order: 40, section: 'finance', section_label: 'Finance', children: [{ key: 'journals', label: 'Journal entries', route: '/accounting' }] };
        const sales = { module: 'accounting', key: 'sales', label: 'Sales', route: '/accounting/sales', icon: 'receipt', order: 41, section: 'finance', section_label: 'Finance', children: [{ key: 'invoices', label: 'Invoices', route: '/accounting/sales' }] };
        const wrapper = await mountNav('/accounting/sales', { menu: [books, sales] });

        expect(wrapper.findAll('button[aria-expanded="true"]').map((button) => button.text())).toEqual(['Sales']);
        expect(wrapper.findAll('[aria-current="page"]').map((link) => link.text())).toEqual(['Invoices']);
        wrapper.unmount();
    });

    it('picks the longest matching sub-page', async () => {
        const wrapper = await mountNav('/hrm/positions');
        expect(wrapper.find('[aria-current="page"]').text()).toBe('Positions');
        wrapper.unmount();
    });

    it('opens and closes a module with sub-pages', async () => {
        const wrapper = await mountNav('/');
        const toggle = wrapper.find('button[aria-expanded]');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.text()).toContain('Positions');
        wrapper.unmount();
    });

    it('shows counts as numbers, also when collapsed', async () => {
        const wrapper = await mountNav('/', { pendingApprovals: 4, collapsed: true });
        const approvals = wrapper.find('a[href="/approvals"]');

        expect(approvals.attributes('aria-label')).toBe('Approvals');
        expect(approvals.text()).toBe('4');
        // Icon-only entries keep their names: a label for screen readers, a tooltip on hover.
        expect(wrapper.text()).not.toContain('Human Resources');
        await wrapper.find('a[aria-label="Human Resources"]').trigger('mouseenter');
        expect(document.body.querySelector('.side-tip')?.textContent).toBe('Human Resources');
        wrapper.unmount();
    });

    it('asks to collapse from the edge button', async () => {
        const wrapper = await mountNav('/', { collapsible: true });
        await wrapper.find('button[aria-label="Collapse the menu"]').trigger('click');

        expect(wrapper.emitted('toggle')).toHaveLength(1);
        wrapper.unmount();
    });

    it('hides screens the person may not use', async () => {
        const wrapper = await mountNav('/', {}, []);
        expect(wrapper.find('a[href="/approvals"]').exists()).toBe(false);
        wrapper.unmount();
    });
});

describe('brand bands', () => {
    it('draws the sidebar band in its one color', async () => {
        brand.side_band_color = '#FFFFFF';
        const wrapper = await mountNav('/');
        const band = wrapper.find('.shell-side > div.h-1');

        expect(band.attributes('style')).toContain('background: rgb(255, 255, 255)');
        wrapper.unmount();
    });

    it('draws the header band as the brand colors side by side', () => {
        brand.band_colors = ['#2B4C9B', '#DD5144', '#23A562'];
        const wrapper = mount(AppHeader, { global: { stubs: { QuickCreateMenu: true, NotificationBell: true, LanguageSwitcher: true, UserMenu: true, BrandMark: true } } });
        const segments = wrapper.findAll('header > div.h-1 > span');

        expect(segments.map((segment) => segment.attributes('style'))).toEqual([
            'background: rgb(43, 76, 155);',
            'background: rgb(221, 81, 68);',
            'background: rgb(35, 165, 98);',
        ]);
        // Along the bottom edge of the header.
        expect(wrapper.find('header').element.lastElementChild.classList.contains('h-1')).toBe(true);
    });

    it('draws no band when the brand has none', () => {
        const wrapper = mount(AppHeader, { global: { stubs: { QuickCreateMenu: true, NotificationBell: true, LanguageSwitcher: true, UserMenu: true, BrandMark: true } } });
        expect(wrapper.find('header > div.h-1').exists()).toBe(false);
        // Without a band the header keeps its plain bottom line.
        expect(wrapper.find('header').classes()).toContain('border-b');
    });
});
