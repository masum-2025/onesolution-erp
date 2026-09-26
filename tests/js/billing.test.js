import { describe, expect, it } from 'vitest';
import { amountToMinor, minorToAmount, monthlyMargin, statusTone } from '@/lib/billing';

describe('billing helpers', () => {
    it('turns typed amounts into minor units without floats', () => {
        expect(amountToMinor('7000.50', 'BDT')).toBe(700050);
        expect(amountToMinor('1,999.99', 'USD')).toBe(199999);
        expect(amountToMinor('0.1', 'USD')).toBe(10);
        expect(amountToMinor('500', 'JPY')).toBe(500);
        expect(amountToMinor('5.5', 'JPY')).toBeNull();
        expect(amountToMinor('-1', 'USD')).toBeNull();
        expect(amountToMinor('abc', 'USD')).toBeNull();
    });

    it('shows minor units back as an editable amount', () => {
        expect(minorToAmount(700050, 'BDT')).toBe('7000.50');
        expect(minorToAmount(500, 'JPY')).toBe('500');
        expect(minorToAmount(null, 'USD')).toBe('');
    });

    it('computes the monthly margin only when price and cost can be compared', () => {
        const prices = [
            { currency: 'USD', period: 'monthly', amount_minor: 6900 },
            { currency: 'BDT', period: 'monthly', amount_minor: 700000 },
        ];
        expect(monthlyMargin(prices, { kind: 'wholesale', currency: 'USD', unit: 'per_client', amount_minor: 2900 })).toEqual({ currency: 'USD', amount: 4000 });
        expect(monthlyMargin(prices, { kind: 'wholesale', currency: 'EUR', unit: 'per_client', amount_minor: 2900 })).toBeNull();
        expect(monthlyMargin(prices, { kind: 'wholesale', currency: 'USD', unit: 'per_seat', amount_minor: 300 })).toBeNull();
        expect(monthlyMargin(prices, { kind: 'revenue_share', share_bp: 3000 })).toBeNull();
        expect(monthlyMargin(prices, { kind: 'wholesale', currency: 'USD', unit: 'per_client', amount_minor: 9900 })).toEqual({ currency: 'USD', amount: -3000 });
    });

    it('gives every status a tone', () => {
        expect(statusTone('overdue')).toBe('bad');
        expect(statusTone('paid')).toBe('ok');
        expect(statusTone('something-new')).toBe('neutral');
    });
});
