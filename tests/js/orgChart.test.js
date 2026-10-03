import { beforeAll, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import OrgChartNode from '../../Modules/Hrm/resources/js/components/OrgChartNode.vue';
import { initI18n, loadNamespaces } from '@/lib/i18n';

beforeAll(async () => {
    await initI18n(['en'], 'en');
    await loadNamespaces(['hrm']);
});

const person = (id, name, reports = 0) => ({ id, full_name: name, reports_count: reports, position: { title: 'Teacher' }, unit: { name: 'Demo School' } });
const global = { stubs: { RouterLink: { template: '<a><slot /></a>' } } };

describe('OrgChartNode', () => {
    it('loads the direct reports only when opened', async () => {
        const hrm = { orgChart: vi.fn().mockResolvedValue({ data: [person('b', 'Bilal Hossain')], meta: { total: 1 } }) };
        const wrapper = mount(OrgChartNode, { props: { person: person('a', 'Anika Rahman', 1), hrm }, global });
        const toggle = wrapper.find('button[aria-expanded]');

        expect(wrapper.text()).toContain('Anika Rahman');
        expect(toggle.attributes('aria-label')).toBe('Show or hide the person who reports to Anika Rahman');
        expect(hrm.orgChart).not.toHaveBeenCalled();

        await toggle.trigger('click');
        await flushPromises();
        expect(hrm.orgChart).toHaveBeenCalledWith('a');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(wrapper.text()).toContain('Bilal Hossain');

        // Closing and opening again does not ask twice.
        await toggle.trigger('click');
        await toggle.trigger('click');
        expect(hrm.orgChart).toHaveBeenCalledTimes(1);
    });

    it('has no toggle for someone nobody reports to', () => {
        const wrapper = mount(OrgChartNode, { props: { person: person('c', 'Chandni Akter'), hrm: { orgChart: vi.fn() } }, global });
        expect(wrapper.find('button[aria-expanded]').exists()).toBe(false);
    });

    it('offers to try again when a level fails', async () => {
        const hrm = { orgChart: vi.fn().mockRejectedValueOnce(new Error('down')).mockResolvedValueOnce({ data: [], meta: { total: 0 } }) };
        const wrapper = mount(OrgChartNode, { props: { person: person('a', 'Anika Rahman', 2), hrm }, global });

        await wrapper.find('button[aria-expanded]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[role="alert"]').text()).toContain('Could not load these people.');

        await wrapper.find('[role="alert"] button').trigger('click');
        await flushPromises();
        expect(hrm.orgChart).toHaveBeenCalledTimes(2);
    });
});
