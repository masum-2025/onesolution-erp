import { describe, expect, it } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { visibleTabs } from '../../Modules/Education/resources/js/moduleTabs';
import ModuleSummaryCards from '../../Modules/Education/resources/js/components/ModuleSummaryCards.vue';

const tab = (key, module, order, summary) => ({ key, module, order, ns: module, label: () => key, component: {}, summary });

describe('student page module tabs', () => {
    it('shows a tab only when its module is in the person\'s menu, in order', () => {
        const tabs = [tab('courses', 'course_registration', 50), tab('fees', 'education_fees', 30), tab('results', 'education_exams', 40)];
        const menu = [{ module: 'education' }, { module: 'education_exams' }, { module: 'education_fees' }];
        expect(visibleTabs(tabs, menu).map((item) => item.key)).toEqual(['fees', 'results']);
        expect(visibleTabs(tabs, [{ module: 'education' }])).toEqual([]);
    });

    it('shows each summary on its own: one that fails stays away, a click opens its tab', async () => {
        const tabs = [
            tab('fees', 'education_fees', 30, async () => ({ value: '৳ 1,200', hint: '৳ 500 overdue', tone: 'bad' })),
            tab('results', 'education_exams', 40, async () => { throw Object.assign(new Error('Not found'), { status: 404 }); }),
            tab('courses', 'course_registration', 50),
        ];
        const wrapper = mount(ModuleSummaryCards, { props: { tabs, studentId: '01student' } });
        await flushPromises();
        const cards = wrapper.findAll('button');
        expect(cards).toHaveLength(1);
        expect(wrapper.text()).toContain('৳ 1,200');
        expect(wrapper.text()).toContain('৳ 500 overdue');
        await cards[0].trigger('click');
        expect(wrapper.emitted('open')).toEqual([['fees']]);
    });

    it('shows nothing when no summary can be seen', async () => {
        const wrapper = mount(ModuleSummaryCards, { props: { tabs: [tab('results', 'education_exams', 40, async () => { throw new Error('no'); })], studentId: '01student' } });
        await flushPromises();
        expect(wrapper.html()).toBe('<!--v-if-->');
    });
});
