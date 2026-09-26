import { describe, expect, it } from 'vitest';
import { addressBody, applySignupOptions, passwordProblem, signup } from '@/lib/identity';

describe('self-serve identity helpers', () => {
    it('checks passwords the way the server does', () => {
        expect(passwordProblem('short1')).toBe(true);
        expect(passwordProblem('onlyletterslong')).toBe(true);
        expect(passwordProblem('1234567890')).toBe(true);
        expect(passwordProblem('Secret-pass-2026')).toBe(false);
    });

    it('sends an email or a phone with its country, never both', () => {
        const form = { channel: 'sms', email: 'x@example.com', phone: ' 01712 345678 ', country: 'BD' };
        expect(addressBody(form)).toEqual({ channel: 'sms', phone: '01712 345678', country_code: 'BD' });
        expect(addressBody({ ...form, channel: 'mail' })).toEqual({ channel: 'mail', email: 'x@example.com' });
    });

    it('takes the options the address offers, ignoring anything missing', () => {
        applySignupOptions(null);
        expect(signup.allowed).toBe(false);
        applySignupOptions({ allowed: true, phone: true, channels: ['mail', 'sms'] });
        expect(signup.allowed).toBe(true);
        expect(signup.channels).toEqual(['mail', 'sms']);
        expect(signup.otp_length).toBe(6);
    });
});
