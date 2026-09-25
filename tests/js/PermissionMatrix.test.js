import { beforeAll, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import PermissionMatrix from '@/pages/access/PermissionMatrix.vue';
import { initI18n, loadNamespaces } from '@/lib/i18n';

beforeAll(async () => {
    await initI18n(['en', 'bn'], 'en');
    await loadNamespaces(['access']);
});

const groups = [
    {
        group: 'payroll',
        label: 'Payroll',
        module: 'payroll',
        module_enabled: true,
        permissions: [
            { key: 'payroll.view', label: 'View Payroll', blocked_by: null },
            { key: 'payroll.run', label: 'Run Payroll', blocked_by: null },
            { key: 'payroll.approve', label: 'Approve Payroll', blocked_by: 'not_held' },
        ],
    },
    {
        group: 'inventory',
        label: 'Inventory',
        module: 'inventory',
        module_enabled: false,
        permissions: [{ key: 'inventory.view', label: 'View Inventory', blocked_by: 'module_disabled' }],
    },
];
const pairs = [{ first: 'payroll.run', second: 'payroll.approve' }];

const box = (wrapper, key) => wrapper.findAll('input[type="checkbox"]').find((input) => input.element.closest('label').textContent.includes(key));

describe('PermissionMatrix', () => {
    it('ticks a permission and emits the sorted list', async () => {
        const wrapper = mount(PermissionMatrix, { props: { groups, pairs, modelValue: ['payroll.view'] } });

        await box(wrapper, 'payroll.run').setValue(true);

        expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual(['payroll.run', 'payroll.view']);
    });

    it('locks permissions this person cannot grant and explains why', () => {
        const wrapper = mount(PermissionMatrix, { props: { groups, pairs, modelValue: [] } });

        expect(box(wrapper, 'payroll.approve').element.disabled).toBe(true);
        expect(box(wrapper, 'inventory.view').element.disabled).toBe(true);
        expect(wrapper.text()).toContain('You do not hold this permission');
        expect(wrapper.text()).toContain('Module off');
    });

    it('warns at once when both sides of a pair are ticked', () => {
        const wrapper = mount(PermissionMatrix, { props: { groups, pairs, modelValue: ['payroll.run', 'payroll.approve'] } });

        expect(wrapper.get('[role="alert"]').text()).toContain('One person cannot hold both "Run Payroll" and "Approve Payroll"');
    });

    it('shows everything read-only when asked', () => {
        const wrapper = mount(PermissionMatrix, { props: { groups, pairs, modelValue: ['payroll.view'], readonly: true } });

        expect(wrapper.findAll('input[type="checkbox"]').every((input) => input.element.disabled)).toBe(true);
        expect(wrapper.text()).not.toContain('Select all');
    });
});
