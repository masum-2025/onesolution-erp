import { describe, expect, it } from 'vitest';
import { daysUntil, newOpId, paymentSettled, standingTone, yearlySaving } from '@/lib/billing';

describe('self-serve billing helpers', () => {
    it('makes a new idempotency key for every click, in the form the server accepts', () => {
        const first = newOpId();
        expect(first).toMatch(/^[A-Za-z0-9_-]{16,64}$/);
        expect(newOpId()).not.toBe(first);
    });

    it('shows a yearly saving only when yearly is cheaper than twelve months', () => {
        expect(yearlySaving({ monthly: 29900, yearly: 299000 })).toBe(59800);
        expect(yearlySaving({ monthly: 1000, yearly: 12000 })).toBeNull();
        expect(yearlySaving({ monthly: 1000 })).toBeNull();
        expect(yearlySaving(null)).toBeNull();
    });

    it('gives every standing a tone', () => {
        expect(standingTone('read_only')).toBe('bad');
        expect(standingTone('past_due')).toBe('warn');
        expect(standingTone('active')).toBe('ok');
        expect(standingTone('something-new')).toBe('neutral');
    });

    it('stops asking once the gateway settled a payment', () => {
        expect(paymentSettled('pending')).toBe(false);
        expect(['succeeded', 'failed', 'cancelled', 'expired', 'review'].every(paymentSettled)).toBe(true);
    });

    it('counts whole days left, never below zero', () => {
        const now = Date.parse('2026-10-05T09:00:00Z');
        expect(daysUntil('2026-10-19T09:00:00Z', now)).toBe(14);
        expect(daysUntil('2026-10-05T10:00:00Z', now)).toBe(1);
        expect(daysUntil('2026-10-01T00:00:00Z', now)).toBe(0);
        expect(daysUntil(null, now)).toBeNull();
    });
});
