import { beforeAll, describe, expect, it } from 'vitest';
import { formatBounds, formatRuleValue, sameValue, supportsBounds } from '@/lib/ruleValues';
import { initI18n, loadNamespaces } from '@/lib/i18n';

beforeAll(async () => {
    await initI18n(['en', 'bn'], 'en');
    await loadNamespaces(['rules']);
});

const weekend = {
    type: 'multi_enum',
    options: [
        { value: 'fri', label: 'Friday' },
        { value: 'sat', label: 'Saturday' },
    ],
};

describe('rule values for people', () => {
    it('shows every type in words', () => {
        expect(formatRuleValue({ type: 'boolean' }, true)).toBe('On');
        expect(formatRuleValue({ type: 'integer' }, 1500)).toBe('1,500');
        expect(formatRuleValue({ type: 'decimal' }, '1.50')).toBe('1.50');
        expect(formatRuleValue(weekend, ['fri', 'sat'])).toBe('Friday and Saturday');
        expect(formatRuleValue({ type: 'enum', options: [{ value: 'FIFO', label: 'First in, first out' }] }, 'FIFO')).toBe('First in, first out');
        expect(formatRuleValue({ type: 'date' }, '07-01')).toBe('July 1');
        expect(formatRuleValue({ type: 'table' }, [{}, {}])).toBe('2 rows');
        expect(formatRuleValue({ type: 'integer' }, null)).toBe('—');
    });

    it('describes limits', () => {
        expect(formatBounds({ type: 'integer' }, { min: 0, max: 15 })).toBe('0 – 15');
        expect(formatBounds({ type: 'integer' }, { max: 15 })).toBe('at most 15');
        expect(formatBounds(weekend, { allowed: ['fri', 'sat'] })).toBe('only Friday or Saturday');
        expect(formatBounds({ type: 'integer' }, null)).toBeNull();
    });

    it('knows which types can have limits', () => {
        expect(supportsBounds('integer')).toBe(true);
        expect(supportsBounds('multi_enum')).toBe(true);
        expect(supportsBounds('boolean')).toBe(false);
        expect(supportsBounds('table')).toBe(false);
    });

    it('compares structured values', () => {
        expect(sameValue(['fri'], ['fri'])).toBe(true);
        expect(sameValue({ amount: 1, currency: 'BDT' }, { amount: 2, currency: 'BDT' })).toBe(false);
    });
});
