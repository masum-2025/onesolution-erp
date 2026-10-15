import { describe, expect, it } from 'vitest';
import { mount, RouterLinkStub } from '@vue/test-utils';
import SetupChecklist from '../../Modules/Education/resources/js/components/SetupChecklist.vue';

const list = {
    done: 2,
    total: 4,
    groups: [
        { module: 'education', label: 'Education', steps: [
            { key: 'structure', label: 'Programs', done: true, detail: null, route: '/education/structure', optional: false },
            { key: 'class_teachers', label: 'Class teachers', done: false, detail: '2 sections have none', route: '/education/sections', optional: true },
            { key: 'sections', label: 'Sections', done: false, detail: 'No section yet', route: '/education/sections', optional: false },
        ] },
        { module: 'education_fees', label: 'Fees', steps: [
            { key: 'fee_heads', label: 'Fee heads', done: true, detail: null, route: '/fees/heads', optional: false },
            { key: 'fee_structure', label: 'Fee structure', done: false, detail: 'None active', route: '/fees/structures', optional: false },
        ] },
    ],
};

function render() {
    return mount(SetupChecklist, { props: { list }, global: { stubs: { RouterLink: RouterLinkStub } } });
}

describe('setup checklist', () => {
    it('sends the reader to the first required step still to do, never an optional one', () => {
        const links = render().findAllComponents(RouterLinkStub);
        expect(links[0].props('to')).toBe('/education/sections');
    });

    it('shows each missing step with what is missing and a way there, done ones without', () => {
        const wrapper = render();
        expect(wrapper.text()).toContain('No section yet');
        expect(wrapper.text()).toContain('None active');
        const targets = wrapper.findAllComponents(RouterLinkStub).map((link) => link.props('to'));
        expect(targets).not.toContain('/education/structure');
        expect(targets).toContain('/fees/structures');
    });

    it('reports progress as a share of the required steps', () => {
        expect(render().find('[role="progressbar"]').attributes('aria-valuenow')).toBe('50');
    });

    it('folds the steps away and back', async () => {
        const wrapper = render();
        await wrapper.findAll('button').at(-1).trigger('click');
        expect(wrapper.text()).not.toContain('None active');
    });
});
