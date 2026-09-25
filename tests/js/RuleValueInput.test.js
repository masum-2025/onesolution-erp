import { beforeAll, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import RuleValueInput from '@/pages/rules/RuleValueInput.vue';
import { initI18n, loadNamespaces } from '@/lib/i18n';

beforeAll(async () => {
    await initI18n(['en', 'bn'], 'en');
    await loadNamespaces(['rules']);
});

const lastValue = (wrapper) => wrapper.emitted('update:modelValue').at(-1)[0];

describe('RuleValueInput sends exactly what the server expects', () => {
    it('integer: a number inside the schema range, otherwise nothing', async () => {
        const rule = { key: 'a.grace', type: 'integer', schema: { minimum: 0, maximum: 240 } };
        const wrapper = mount(RuleValueInput, { props: { rule, modelValue: 10 } });
        const input = wrapper.get('input');

        await input.setValue('15');
        expect(lastValue(wrapper)).toBe(15);

        await input.setValue('999');
        expect(lastValue(wrapper)).toBeUndefined();
        expect(wrapper.text()).toContain('240 or less');

        await input.setValue('1.5');
        expect(lastValue(wrapper)).toBeUndefined();
    });

    it('decimal: a string, never a float', async () => {
        const rule = { key: 'p.ot', type: 'decimal', schema: { pattern: '^[1-4](\\.\\d{1,2})?$' } };
        const wrapper = mount(RuleValueInput, { props: { rule, modelValue: '2.0' } });

        await wrapper.get('input').setValue('1.5');
        expect(lastValue(wrapper)).toBe('1.5');

        await wrapper.get('input').setValue('7');
        expect(lastValue(wrapper)).toBeUndefined();
    });

    it('money: typed in taka, sent in poisha with the currency', async () => {
        const rule = { key: 'a.limit', type: 'money', schema: {} };
        const wrapper = mount(RuleValueInput, { props: { rule, modelValue: { amount: 50000000, currency: 'BDT' } } });
        const [amount] = wrapper.findAll('input');

        expect(amount.element.value).toBe('500000.00');
        await amount.setValue('250000.75');
        expect(lastValue(wrapper)).toEqual({ amount: 25000075, currency: 'BDT' });
    });

    it('money: empty means none when the rule allows it', async () => {
        const rule = { key: 'a.limit', type: 'money', nullable: true, schema: {} };
        const wrapper = mount(RuleValueInput, { props: { rule, modelValue: null } });

        await wrapper.findAll('input')[0].setValue('');
        expect(lastValue(wrapper)).toBeNull();
    });

    it('multi_enum: toggles choices in a stable order and respects maxItems', async () => {
        const rule = {
            key: 'a.weekend',
            type: 'multi_enum',
            schema: { maxItems: 2 },
            options: ['sat', 'sun', 'fri'].map((value) => ({ value, label: value })),
        };
        const wrapper = mount(RuleValueInput, { props: { rule, modelValue: ['fri'] } });
        const [sat, sun] = wrapper.findAll('button');

        await sat.trigger('click');
        expect(lastValue(wrapper)).toEqual(['sat', 'fri']);

        await wrapper.setProps({ modelValue: ['sat', 'fri'] });
        await sun.trigger('click');
        expect(lastValue(wrapper)).toBeUndefined();
    });

    it('table: money columns in normal units, empty cell allowed where the schema says null', async () => {
        const rule = {
            key: 'p.slabs',
            type: 'table',
            schema: {
                items: {
                    properties: {
                        upto_minor: { type: ['integer', 'null'], 'x-format': 'money_minor' },
                        rate_percent: { type: 'string', pattern: '^\\d{1,2}(\\.\\d{1,2})?$' },
                    },
                },
            },
        };
        const wrapper = mount(RuleValueInput, { props: { rule, modelValue: [{ upto_minor: 35000000, rate_percent: '0' }] } });
        const cells = wrapper.findAll('tbody input');

        expect(cells[0].element.value).toBe('350000.00');

        await cells[0].setValue('');
        expect(lastValue(wrapper)).toEqual([{ upto_minor: null, rate_percent: '0' }]);

        await cells[1].setValue('abc');
        expect(lastValue(wrapper)).toBeUndefined();
    });

    it('duration: minutes, hours or days as ISO 8601', async () => {
        const rule = { key: 'a.shift', type: 'duration', schema: {} };
        const wrapper = mount(RuleValueInput, { props: { rule, modelValue: 'PT120M' } });

        expect(wrapper.get('input').element.value).toBe('2');
        expect(wrapper.get('select').element.value).toBe('hour');

        await wrapper.get('input').setValue('3');
        expect(lastValue(wrapper)).toBe('PT3H');
    });
});
