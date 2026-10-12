import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import AppDialog from '@/components/AppDialog.vue';
import { allowedChoices, appearance, applyAppearance, DIALOGS } from '@/lib/appearance';
import en from '@/locales/en/appearance.json';
import bn from '@/locales/bn/appearance.json';

function keys(tree, prefix = '') {
    return Object.entries(tree).flatMap(([key, value]) => (typeof value === 'object' ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

async function open(props = {}) {
    const wrapper = mount(AppDialog, {
        props: { open: true, title: 'New exam', ...props },
        slots: { default: '<p>body</p>', footer: '<button>Save</button>' },
        attachTo: document.body,
    });
    await nextTick();
    return wrapper;
}

const dialog = () => document.body.querySelector('[role="dialog"]');

afterEach(() => {
    applyAppearance({ dialog: 'center' });
    document.body.innerHTML = '';
});

describe('window styles', () => {
    it('knows three styles and falls back to the centred box', () => {
        expect(DIALOGS).toEqual(['center', 'panel', 'premium']);
        applyAppearance({ dialog: 'panel' });
        expect(appearance.dialog).toBe('panel');
        applyAppearance({ dialog: 'sideways' });
        expect(appearance.dialog).toBe('center');
        expect(allowedChoices('dialog')).toEqual(DIALOGS);
    });

    it('opens a form as a side panel, a premium card or a centred box', async () => {
        applyAppearance({ dialog: 'panel' });
        let wrapper = await open();
        expect(dialog().className).toContain('end-0');
        expect(dialog().textContent).toContain('body');
        wrapper.unmount();

        applyAppearance({ dialog: 'premium' });
        wrapper = await open();
        expect(dialog().className).toContain('dialog-premium');
        wrapper.unmount();

        applyAppearance({ dialog: 'center' });
        wrapper = await open();
        expect(dialog().className).toContain('animate-rise');
        wrapper.unmount();
    });

    it('keeps confirmations in the middle whatever the style', async () => {
        applyAppearance({ dialog: 'panel' });
        let wrapper = await open({ confirm: true });
        expect(dialog().className).toContain('animate-rise');
        wrapper.unmount();
        wrapper = await open({ tone: 'warn' });
        expect(dialog().className).toContain('animate-rise');
        wrapper.unmount();
    });

    it('closes from its close button in every style', async () => {
        for (const style of DIALOGS) {
            applyAppearance({ dialog: style });
            const wrapper = await open();
            document.body.querySelector('[role="dialog"] button[aria-label]').click();
            expect(wrapper.emitted('close')).toHaveLength(1);
            wrapper.unmount();
        }
    });

    it('speaks Bangla and English with the same keys', () => {
        expect(keys(bn).sort()).toEqual(keys(en).sort());
        expect(en.dialog.panel).toBeTruthy();
    });
});
