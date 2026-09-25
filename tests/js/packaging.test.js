import { beforeAll, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { initI18n, loadNamespaces } from '@/lib/i18n';

vi.mock('@/lib/http', () => ({
    api: vi.fn(async (path) => {
        if (path === '/api/sectors') {
            return {
                data: [
                    { key: 'school', name: 'School', description: 'Staff and payroll.', modules: [{ key: 'hrm', name: 'HR' }], roles: ['Teacher'], rules_count: 1 },
                    { key: 'retail', name: 'Retail shop', description: 'Stock.', modules: [{ key: 'inventory', name: 'Inventory' }], roles: [], rules_count: 0 },
                ],
            };
        }
        throw new Error(`unexpected ${path}`);
    }),
}));

const { planPrice, usageShare, usageTone } = await import('@/lib/packaging');
const SectorPicker = (await import('@/pages/organizations/SectorPicker.vue')).default;

beforeAll(async () => {
    await initI18n(['en', 'bn'], 'en');
    await loadNamespaces(['packaging']);
});

describe('plan helpers', () => {
    const plan = {
        prices: [
            { currency: 'BDT', period: 'monthly', amount_minor: 150000 },
            { currency: 'USD', period: 'monthly', amount_minor: 1500 },
            { currency: 'USD', period: 'yearly', amount_minor: 15000 },
        ],
    };

    it('shows the price in the preferred currency, from integer minor units', () => {
        expect(planPrice(plan, 'USD')).toContain('15.00');
        expect(planPrice(plan, 'BDT')).toContain('1,500.00');
        // Unknown currency: the first monthly price.
        expect(planPrice(plan, 'EUR')).toContain('1,500.00');
        expect(planPrice({ prices: [] })).toBeNull();
    });

    it('measures how full a limit is', () => {
        expect(usageShare({ max: 10, used: 5 })).toBe(0.5);
        expect(usageShare({ max: null, used: 5 })).toBeNull();
        expect(usageShare({ max: 10, used: null })).toBeNull();
        expect(usageTone({ max: 10, used: 5 })).toBe('ok');
        expect(usageTone({ max: 10, used: 8 })).toBe('warn');
        expect(usageTone({ max: 10, used: 10 })).toBe('full');
        expect(usageTone({ max: 10, used: 12 })).toBe('full');
        expect(usageTone({ max: null, used: 99 })).toBe('ok');
    });
});

describe('SectorPicker', () => {
    it('offers the sectors with what they bring and emits the choice', async () => {
        const wrapper = mount(SectorPicker, { props: { modelValue: '' } });
        await flushPromises();

        const options = wrapper.findAll('[role="radio"]');
        expect(options).toHaveLength(2);
        expect(options[0].text()).toContain('School');
        expect(options[0].text()).toContain('Roles: Teacher');

        await options[1].trigger('click');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['retail']);
    });
});
