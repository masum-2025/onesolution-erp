import { beforeAll, describe, expect, it } from 'vitest';
import { initI18n } from '@/lib/i18n';
import { displayValue, fieldToInput, inputToApi, milliText } from '@/lib/generatedFields';

beforeAll(() => initI18n(['en', 'bn'], 'en'));

describe('fields of generated modules', () => {
    it('reads typed values into what the API takes, without floats', () => {
        expect(inputToApi({ type: 'quantity' }, '২.৫')).toEqual({ value: 2500 });
        expect(inputToApi({ type: 'quantity' }, '1.2345')).toEqual({ error: 'quantity' });
        expect(inputToApi({ type: 'money' }, '1,250.50', 'BDT')).toEqual({ value: 125050 });
        expect(inputToApi({ type: 'integer' }, '১২')).toEqual({ value: 12 });
        expect(inputToApi({ type: 'integer' }, '1.5')).toEqual({ error: 'whole_number' });
        expect(inputToApi({ type: 'text', required: true }, '  ')).toEqual({ error: 'required' });
        expect(inputToApi({ type: 'text' }, '')).toEqual({ value: null });
        expect(inputToApi({ type: 'text', max: 3 }, 'abcd')).toEqual({ error: 'too_long' });
        expect(inputToApi({ type: 'translatable', required: true }, { en: '', bn: 'বই' })).toEqual({ error: 'english_needed' });
        expect(inputToApi({ type: 'translatable' }, { en: ' Book ', bn: '' })).toEqual({ value: { en: 'Book', bn: null } });
        expect(inputToApi({ type: 'boolean' }, 1)).toEqual({ value: true });
    });

    it('puts stored values back into inputs', () => {
        expect(fieldToInput({ type: 'quantity' }, 2500)).toBe('2.5');
        expect(fieldToInput({ type: 'money' }, 125050, 'BDT')).toBe('1250.50');
        expect(fieldToInput({ type: 'integer' }, null)).toBe('');
        expect(milliText(-1500)).toBe('-1.5');
    });

    it('shows a value as a list does', () => {
        const ctx = { t: (key) => key, ns: 'fleet', entity: 'vehicle', currency: 'USD', options: { vehicle: { v1: 'DHA-11' } }, employees: { e1: 'Rahima' } };
        expect(displayValue({ type: 'enum', name: 'kind' }, 'truck', ctx)).toBe('fleet.options.vehicle.kind.truck');
        expect(displayValue({ type: 'belongs_to', entity: 'vehicle' }, 'v1', ctx)).toBe('DHA-11');
        expect(displayValue({ type: 'employee' }, 'e1', ctx)).toBe('Rahima');
        expect(displayValue({ type: 'money' }, 48500, ctx)).toBe('$485.00');
        expect(displayValue({ type: 'text' }, null, ctx)).toBe('—');
    });
});
