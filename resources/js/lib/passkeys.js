import { api } from './http';
import { afterSignIn } from './session';
import { t } from './i18n';

/*
 * Passkeys in the browser (Phase 8-1). The WebAuthn helper library loads
 * only when a passkey is actually used. The server makes every challenge
 * and checks every answer; this only hands them to the device.
 */

export function passkeysSupported() {
    return typeof window !== 'undefined' && typeof window.PublicKeyCredential === 'function';
}

async function library() {
    return import('@simplewebauthn/browser');
}

/** The person closed the prompt or the device refused: say so plainly. */
export function passkeyErrorMessage(error) {
    if (error?.name === 'NotAllowedError' || error?.name === 'AbortError') return t('core.passkey.cancelled');
    if (error?.name === 'InvalidStateError') return t('core.passkey.already_added');
    return error?.message || t('core.passkey.failed');
}

/** Signs in with a passkey: alone, or as the second step of a password sign-in. */
export async function signInWithPasskey() {
    const options = (await api('/session/passkey/options', { method: 'POST', silentAuth: true })).data;
    const { startAuthentication } = await library();
    const credential = await startAuthentication({ optionsJSON: options });

    return afterSignIn(await api('/session/passkey', { method: 'POST', body: { credential }, silentAuth: true }));
}

/** Adds a passkey for the signed-in person. */
export async function addPasskey(name) {
    const options = (await api('/api/me/security/passkeys/options', { method: 'POST' })).data;
    const { startRegistration } = await library();
    const credential = await startRegistration({ optionsJSON: options });

    return api('/api/me/security/passkeys', { method: 'POST', body: { name, credential } });
}

/** Confirms it is you before a sensitive action. */
export async function confirmWithPasskey() {
    const options = (await api('/api/me/security/confirm/options', { method: 'POST' })).data;
    const { startAuthentication } = await library();
    const credential = await startAuthentication({ optionsJSON: options });

    return api('/api/me/security/confirm', { method: 'POST', body: { credential }, noStepUp: true });
}
