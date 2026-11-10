import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import GuideButton from '@/components/GuideButton.vue';
import PageFrame from '@/components/PageFrame.vue';
import { i18n, initI18n } from '@/lib/i18n';
import { guideContent, guideSeen, safeVideo } from '@/lib/guide';

const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }, { path: '/rules', name: 'rules', component: { template: '<div />' } }] });

beforeAll(async () => {
    await initI18n(['en'], 'en');
    i18n.messages.en.demo = {
        guide: {
            sell: {
                title: 'How to sell',
                steps: { 1: 'Scan the item.', 2: 'Take <b>payment</b>.' },
                tips: { 1: 'Red means it expires soon.' },
                mistakes: { 1: 'Never above MRP.' },
                settings: 'Discount limits are in Rules.',
            },
            half: { title: 'Only a title' },
        },
    };
});

afterEach(() => {
    window.localStorage.clear();
    document.body.innerHTML = '';
});

function mountButton(props) {
    return mount(GuideButton, { props, attachTo: document.body, global: { plugins: [router] } });
}

describe('guide content', () => {
    it('reads numbered steps, tips and mistakes from translation keys', () => {
        expect(guideContent('demo.guide.sell')).toEqual({
            title: 'How to sell',
            steps: ['Scan the item.', 'Take <b>payment</b>.'],
            tips: ['Red means it expires soon.'],
            mistakes: ['Never above MRP.'],
            settings: 'Discount limits are in Rules.',
        });
    });

    it('has no guide without a title and a first step', () => {
        expect(guideContent('demo.guide.half')).toBeNull();
        expect(guideContent('demo.guide.missing')).toBeNull();
        expect(guideContent('')).toBeNull();
    });

    it('accepts only https video links', () => {
        expect(safeVideo('https://example.com/v')).toBe('https://example.com/v');
        expect(safeVideo('http://example.com/v')).toBeNull();
        expect(safeVideo('javascript:alert(1)')).toBeNull();
        expect(safeVideo('not a url')).toBeNull();
    });
});

describe('GuideButton', () => {
    it('shows nothing when the page has no guide (never raw keys)', () => {
        const wrapper = mountButton({ guide: 'demo.guide.missing' });
        expect(wrapper.find('[data-guide-button]').exists()).toBe(false);
    });

    it('pulses until opened once, then opens the steps as text', async () => {
        const wrapper = mountButton({ guide: 'demo.guide.sell', settings: { name: 'rules' }, video: 'javascript:alert(1)' });
        const button = wrapper.get('[data-guide-button]');
        expect(button.text()).toContain('How to?');
        expect(button.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[data-guide-pulse]').exists()).toBe(true);

        await button.trigger('click');
        await flushPromises();
        await vi.dynamicImportSettled?.();
        await flushPromises();

        const drawer = document.body.querySelector('[data-guide-drawer]');
        expect(drawer).not.toBeNull();
        expect(drawer.textContent).toContain('Scan the item.');
        expect(drawer.textContent).toContain('Never above MRP.');
        // Markup in a translation stays text.
        expect(drawer.querySelector('b')).toBeNull();
        expect(drawer.textContent).toContain('Take <b>payment</b>.');
        expect(document.body.querySelector('[data-guide-settings]')).not.toBeNull();
        expect(document.body.querySelector('[data-guide-video]')).toBeNull();
        expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
        expect(button.attributes('aria-expanded')).toBe('true');
        expect(wrapper.find('[data-guide-pulse]').exists()).toBe(false);
        expect(guideSeen('demo.guide.sell')).toBe(true);
    });

    it('closes on Escape', async () => {
        const wrapper = mountButton({ guide: 'demo.guide.sell' });
        await wrapper.get('[data-guide-button]').trigger('click');
        await flushPromises();
        await vi.dynamicImportSettled?.();
        await flushPromises();
        const dialog = document.body.querySelector('[role="dialog"]');
        dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(wrapper.get('[data-guide-button]').attributes('aria-expanded')).toBe('false');
    });

    it('"Don\'t remind me again" stops the pulse on every page', async () => {
        const wrapper = mountButton({ guide: 'demo.guide.sell' });
        await wrapper.get('[data-guide-button]').trigger('click');
        await flushPromises();
        await vi.dynamicImportSettled?.();
        await flushPromises();
        document.body.querySelector('[data-guide-quiet]').click();
        expect(guideSeen('demo.guide.other')).toBe(true);
    });

    it('still works when browser storage is blocked', () => {
        const spy = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('blocked');
        });
        const wrapper = mountButton({ guide: 'demo.guide.sell' });
        expect(wrapper.find('[data-guide-button]').exists()).toBe(true);
        expect(wrapper.find('[data-guide-pulse]').exists()).toBe(true);
        spy.mockRestore();
    });
});

describe('PageFrame guide', () => {
    it('puts the guide button in the page header when the page has one', () => {
        const wrapper = mount(PageFrame, { props: { title: 'Sales', guide: 'demo.guide.sell' }, global: { plugins: [router] } });
        expect(wrapper.find('[data-guide-button]').exists()).toBe(true);
    });

    it('leaves the header alone without a guide', () => {
        const wrapper = mount(PageFrame, { props: { title: 'Sales' }, global: { plugins: [router] } });
        expect(wrapper.find('[data-guide-button]').exists()).toBe(false);
    });
});
