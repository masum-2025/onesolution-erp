import { beforeAll, describe, expect, it } from 'vitest';
import { ref } from 'vue';
import { mount } from '@vue/test-utils';
import MoneyInput from '@/components/MoneyInput.vue';
import PageFrame from '@/components/PageFrame.vue';
import { initI18n } from '@/lib/i18n';

beforeAll(async () => {
    await initI18n(['en'], 'en');
});

/** A useResource() result as PageFrame reads it. */
function resource({ data = null, loading = false, error = null } = {}) {
    return { data: ref(data), loading: ref(loading), error: ref(error), reload: () => {} };
}

describe('MoneyInput', () => {
    it('gives minor units, and tells an empty field from one that is not an amount', async () => {
        const field = mount(MoneyInput, { props: { modelValue: null, currency: 'BDT' } });
        const input = field.find('input');

        await input.setValue('১,২৫০.৫');
        expect(field.emitted('update:modelValue').at(-1)).toEqual([125050]);
        expect(field.emitted('update:invalid')).toBeUndefined();

        await input.setValue('abc');
        expect(field.emitted('update:modelValue').at(-1)).toEqual([null]);
        expect(field.emitted('update:invalid').at(-1)).toEqual([true]);

        await field.setProps({ invalid: true });
        expect(input.attributes('aria-invalid')).toBe('true');
        await input.setValue('');
        expect(field.emitted('update:invalid').at(-1)).toEqual([false]);
    });

    it('shows an amount set from outside and clears the mistake mark', async () => {
        const field = mount(MoneyInput, { props: { modelValue: null, currency: 'BDT', invalid: true } });
        await field.setProps({ modelValue: 5000 });
        expect(field.find('input').element.value).toBe('50.00');
        expect(field.emitted('update:invalid').at(-1)).toEqual([false]);
    });
});

describe('PageFrame', () => {
    const stubs = { ExportMenu: { template: '<div class="export-menu" />' } };

    it('keeps the buttons and export off paper', () => {
        const frame = mount(PageFrame, {
            props: { title: 'Employees', export: { module: 'hrm', page: 'hrm.employees', columns: [], rows: [] } },
            slots: { actions: '<button>Hire</button>', default: '<p>list</p>' },
            global: { stubs },
        });
        const wrapper = frame.find('.print\\:hidden');
        expect(wrapper.find('button').text()).toBe('Hire');
        expect(wrapper.find('.export-menu').exists()).toBe(true);
        expect(frame.text()).toContain('list');
    });

    it('leaves its name, date and header off a page that lays out its own paper', () => {
        const plain = mount(PageFrame, { props: { title: 'Stock' }, global: { stubs } });
        expect(plain.find('.print\\:block').exists()).toBe(true);
        const own = mount(PageFrame, { props: { title: 'Labels', ownPaper: true }, global: { stubs } });
        expect(own.find('.print\\:block').exists()).toBe(false);
        expect(own.find('h1').element.closest('.print\\:hidden')).not.toBeNull();
    });

    it('shows a record page\'s header only with its data, a back link before it, and "not found" for a 404', async () => {
        const loading = mount(PageFrame, {
            props: { title: '', resource: resource({ loading: true }) },
            slots: { before: '<a>Back</a>', default: '<p>record</p>' },
            global: { stubs },
        });
        expect(loading.find('h1').exists()).toBe(false);
        expect(loading.text()).toContain('Back');
        expect(loading.text()).not.toContain('record');

        const missing = mount(PageFrame, {
            props: { title: '', resource: resource({ error: { status: 404, message: 'Not found' } }), notFound: { title: 'No such employee' } },
            global: { stubs },
        });
        expect(missing.text()).toContain('No such employee');

        const ready = mount(PageFrame, {
            props: { title: 'Rahima Akter', resource: resource({ data: { data: { id: 1 } } }) },
            slots: { default: '<p>record</p>' },
            global: { stubs },
        });
        expect(ready.find('h1').text()).toBe('Rahima Akter');
        expect(ready.text()).toContain('record');
    });
});
