import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LegalText from '@/components/LegalText.vue';

describe('LegalText', () => {
    it('turns "# " lines into headings and blank lines into paragraphs', () => {
        const wrapper = mount(LegalText, { props: { text: '# Payment\nDue in 14 days.\nNo cash.\n\nSecond paragraph.' } });

        expect(wrapper.findAll('h3').map((node) => node.text())).toEqual(['Payment']);
        const paragraphs = wrapper.findAll('p');
        expect(paragraphs).toHaveLength(2);
        expect(paragraphs[0].html()).toContain('Due in 14 days.<br>No cash.');
        expect(paragraphs[1].text()).toBe('Second paragraph.');
    });

    it('shows markup as text, never as HTML', () => {
        const wrapper = mount(LegalText, { props: { text: '<img src=x onerror=alert(1)> <b>bold</b>' } });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<b>bold</b>');
    });
});
