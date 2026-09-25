import { beforeAll, describe, expect, it } from 'vitest';
import { h } from 'vue';
import { mount } from '@vue/test-utils';
import AppMenu from '@/components/AppMenu.vue';
import { initI18n } from '@/lib/i18n';

beforeAll(async () => {
    await initI18n(['en'], 'en');
});

describe('AppMenu', () => {
    it('renders the items it is given', async () => {
        let chosen = null;
        const wrapper = mount(AppMenu, {
            attachTo: document.body,
            props: {
                label: 'Actions',
                items: [{ label: 'Change roles', onSelect: () => (chosen = 'roles') }, { divider: true }, { label: 'Suspend', danger: true, disabled: true }],
            },
            slots: { trigger: ({ toggle, attrs }) => h('button', { ...attrs, onClick: () => toggle(false) }, 'Open') },
        });

        await wrapper.get('button').trigger('click');
        const items = wrapper.findAll('[role="menuitem"]');

        expect(items.map((item) => item.text())).toEqual(['Change roles', 'Suspend']);
        expect(items[1].element.disabled).toBe(true);

        await items[0].trigger('click');
        expect(chosen).toBe('roles');
        wrapper.unmount();
    });
});
