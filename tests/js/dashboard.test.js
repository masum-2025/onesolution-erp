import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import StatWidget from '@/components/dashboard/StatWidget.vue';
import BarsWidget from '@/components/dashboard/BarsWidget.vue';
import DashboardWidget from '@/components/dashboard/DashboardWidget.vue';
import { initI18n } from '@/lib/i18n';
import { api } from '@/lib/http';

vi.mock('@/lib/http', () => ({ api: vi.fn() }));

beforeAll(async () => {
    await initI18n(['en'], 'en');
    const { loadNamespaces } = await import('@/lib/i18n');
    await loadNamespaces(['dashboard']);
});

afterEach(() => {
    vi.mocked(api).mockReset();
});

const stat = (extra = {}) => ({ value: 42, format: 'number', currency: null, hint: 'Working here now', change: null, series: [], ...extra });
const global = { stubs: { RouterLink: { template: '<a><slot /></a>' } } };

describe('StatWidget', () => {
    it('shows a change with an arrow and a sign, not only a color', () => {
        const wrapper = mount(StatWidget, { props: { label: 'Employees', data: stat({ change: { value: -3, direction: 'down', tone: 'bad' } }) } });
        const badge = wrapper.find('span.rounded-full');

        expect(wrapper.text()).toContain('42');
        expect(badge.text()).toContain('−3');
        expect(badge.find('svg.lucide-arrow-down').exists()).toBe(true);
        expect(badge.classes()).toContain('text-bad');
        expect(badge.find('.sr-only').text()).toBe('down 3 on the previous period');
    });

    it('keeps a neutral change neutral', () => {
        const wrapper = mount(StatWidget, { props: { label: 'Employees', data: stat({ change: { value: 2, direction: 'up', tone: 'neutral' } }) } });
        expect(wrapper.find('span.rounded-full').classes()).toContain('text-fg-2');
        expect(wrapper.find('span.rounded-full').text()).toContain('+2');
    });

    it('draws the trend with a readable table behind it', () => {
        const wrapper = mount(StatWidget, { props: { label: 'Employees', data: stat({ series: [1, 1, 2, 4] }) } });

        expect(wrapper.find('svg path').exists()).toBe(true);
        expect(wrapper.findAll('table td').map((cell) => cell.text())).toEqual(['1', '1', '2', '4']);
        expect(wrapper.find('table caption').text()).toBe('Employees');
    });
});

describe('BarsWidget', () => {
    it('writes every value next to its bar, largest bar full width', () => {
        const wrapper = mount(BarsWidget, { props: { data: { items: [{ label: 'Teacher', value: 4 }, { label: 'Accountant', value: 1 }] } } });
        const bars = wrapper.findAll('li span.bg-brand');

        expect(wrapper.text()).toContain('Teacher');
        expect(bars[0].attributes('style')).toContain('width: 100%');
        expect(bars[1].attributes('style')).toContain('width: 25%');
    });
});

describe('ListWidget', () => {
    it('writes dates in the reader\'s language, on their own day', async () => {
        const { default: ListWidget } = await import('@/components/dashboard/ListWidget.vue');
        const wrapper = mount(ListWidget, { props: { data: { items: [{ label: 'Chandni Akter', meta: 'Hired', date: '2026-10-05', path: '/hrm/employees/1', tone: 'good' }] } }, global });

        expect(wrapper.text()).toContain('Hired · Oct 5, 2026');
        expect(wrapper.find('svg.lucide-circle-check').exists()).toBe(true);
    });
});

describe('DashboardWidget', () => {
    const widget = { module: 'hrm', module_name: 'Human Resources', key: 'headcount', label: 'Employees', type: 'stat', size: 1 };

    it('loads its own data', async () => {
        vi.mocked(api).mockResolvedValue({ data: stat() });
        const wrapper = mount(DashboardWidget, { props: { widget, showModule: true }, global });
        expect(wrapper.find('[role="status"]').exists()).toBe(true);

        await flushPromises();
        expect(api).toHaveBeenCalledWith('/api/modules/hrm/dashboard/headcount');
        expect(wrapper.text()).toContain('Human Resources');
        expect(wrapper.text()).toContain('42');
    });

    it('fails on its own and offers to try again', async () => {
        vi.mocked(api).mockRejectedValueOnce(new Error('down')).mockResolvedValueOnce({ data: stat({ value: 7 }) });
        const wrapper = mount(DashboardWidget, { props: { widget }, global });
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('This could not be loaded.');
        await wrapper.find('[role="alert"] button').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('7');
    });

    it('says when a list has nothing yet', async () => {
        vi.mocked(api).mockResolvedValue({ data: { items: [] } });
        const wrapper = mount(DashboardWidget, { props: { widget: { ...widget, key: 'recent', type: 'list' } }, global });
        await flushPromises();

        expect(wrapper.text()).toContain('Nothing to show yet.');
    });
});
