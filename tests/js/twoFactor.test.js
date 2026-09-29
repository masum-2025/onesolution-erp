import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { api } from '@/lib/http';
import { finishStepUp, stepUp } from '@/lib/stepUp';
import { completeTwoFactor, login, session } from '@/lib/session';
import { initI18n } from '@/lib/i18n';
import SecondStepForm from '@/components/SecondStepForm.vue';

/*
 * Two-step sign-in in the browser (Phase 8-1): the sign-in waiting for its
 * second step, and "confirm it's you" before a sensitive action.
 */
function reply(status, body = null) {
    return Promise.resolve(new Response(body === null ? null : JSON.stringify(body), { status }));
}

beforeAll(() => initI18n(['en', 'bn'], 'en'));

beforeEach(() => {
    globalThis.fetch = vi.fn();
});

afterEach(() => {
    vi.restoreAllMocks();
    finishStepUp(false);
    session.me = null;
});

describe('signing in', () => {
    it('does not load the account while the second step is waiting', async () => {
        fetch.mockReturnValueOnce(reply(200, { two_factor: { methods: ['totp', 'recovery_code'], expires_at: '2026-10-01T10:05:00Z' } }));

        const result = await login('rahim@example.com', 'secret');

        expect(result).toEqual({ twoFactor: { methods: ['totp', 'recovery_code'], expires_at: '2026-10-01T10:05:00Z' } });
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('loads the account once the second step is done', async () => {
        fetch.mockReturnValueOnce(reply(200, { contexts: {} })).mockReturnValueOnce(reply(200, { data: { user: { id: 'U1' }, context: null } }));

        const me = await completeTwoFactor({ code: '123456' });

        expect(fetch.mock.calls[0][0]).toBe('/session/two-factor');
        expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ code: '123456' });
        expect(fetch.mock.calls[1][0]).toBe('/api/me');
        expect(me.user.id).toBe('U1');
    });
});

describe('confirming a sensitive action', () => {
    it('asks for the second step, then sends the action again', async () => {
        fetch
            .mockReturnValueOnce(reply(403, { code: 'step_up_required', message: 'Confirm', methods: ['totp'] }))
            .mockReturnValueOnce(reply(200, { ok: true }));

        const pending = api('/api/organizations/O1/roles', { method: 'POST', body: { name: 'X' } });
        await vi.waitFor(() => expect(stepUp.open).toBe(true));
        expect(stepUp.methods).toEqual(['totp']);

        finishStepUp(true);

        await expect(pending).resolves.toEqual({ ok: true });
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(fetch.mock.calls[1][1].body).toBe(fetch.mock.calls[0][1].body);
    });

    it('gives the error back when the person cancels, and never asks twice', async () => {
        fetch.mockReturnValue(reply(403, { code: 'step_up_required', message: 'Confirm', methods: ['totp'] }));

        const pending = api('/api/me/security/totp', { method: 'DELETE' }).catch((error) => error);
        await vi.waitFor(() => expect(stepUp.open).toBe(true));
        finishStepUp(false);

        expect((await pending).code).toBe('step_up_required');
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});

describe('SecondStepForm', () => {
    it('sends an app code without spaces, or a recovery code as typed', async () => {
        const form = mount(SecondStepForm, { props: { methods: ['totp', 'recovery_code'], submitLabel: 'Sign in' } });

        await form.find('input').setValue('123 456');
        await form.find('form').trigger('submit');
        expect(form.emitted('code')[0]).toEqual([{ code: '123456' }]);

        await form.findAll('button').find((button) => button.text().includes('recovery code')).trigger('click');
        await nextTick();
        await form.find('input').setValue('ABCDE-FGHJK');
        await form.find('form').trigger('submit');
        expect(form.emitted('code')[1]).toEqual([{ recovery_code: 'ABCDE-FGHJK' }]);
    });

    it('offers a passkey only when the person has one and the browser can', () => {
        const without = mount(SecondStepForm, { props: { methods: ['passkey', 'recovery_code'], submitLabel: 'Go' } });
        expect(without.text()).not.toContain('Use a passkey');

        window.PublicKeyCredential = function PublicKeyCredential() {};
        const withPasskey = mount(SecondStepForm, { props: { methods: ['passkey', 'recovery_code'], submitLabel: 'Go' } });
        expect(withPasskey.text()).toContain('Use a passkey');
        delete window.PublicKeyCredential;
    });
});
