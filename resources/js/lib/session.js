import { reactive } from 'vue';
import { api } from './http';
import { applyBrand } from './brand';
import { applyAppearance } from './appearance';
import { setLocale } from './i18n';
import { readPref } from './storage';
import { resetCaches } from './cache';

/**
 * Who is signed in and where they are working, from /api/me. The server
 * re-verifies the context on every call; `can` only shapes the UI.
 */
export const session = reactive({ ready: false, me: null });

export async function loadMe() {
    try {
        const { data } = await api('/api/me', { silentAuth: true });
        session.me = data;
        applyBrand(data.brand);
        // After the brand: a highlight color the person picked replaces the brand color.
        applyAppearance(data.appearance);

        // Language: picked on this device, else the person's profile, else the
        // organization's (own, inherited or its country's).
        const locale = data.user?.locale ?? data.context?.settings?.default_locale;
        if (!readPref('locale') && locale) await setLocale(locale, { remember: false });
    } catch (error) {
        if (error.status !== 401) throw error;
        session.me = null;
    } finally {
        session.ready = true;
    }
    return session.me;
}

/**
 * Sign in with an email, or with { phone, country_code, password } (Phase 5C).
 * With two-step sign-in (Phase 8-1) the answer is { twoFactor: { methods } }:
 * not signed in yet, see completeTwoFactor().
 */
export async function login(email, password) {
    const body = typeof email === 'object' && email !== null ? email : { email, password };
    return afterSignIn(await api('/session/login', { method: 'POST', body, silentAuth: true }));
}

/** Any sign-in answer: signed in (loads /api/me), or waiting for the second step. */
export async function afterSignIn(result) {
    if (result?.two_factor) return { twoFactor: result.two_factor };
    resetCaches();
    return loadMe();
}

/** The second step with { code } or { recovery_code }. */
export async function completeTwoFactor(body) {
    return afterSignIn(await api('/session/two-factor', { method: 'POST', body, silentAuth: true }));
}

export async function enterContext(target) {
    await api('/session/context', { method: 'POST', body: target });
    resetCaches();
    return loadMe();
}

export async function logout() {
    try {
        await api('/session/logout', { method: 'POST', silentAuth: true });
    } finally {
        session.me = null;
        resetCaches();
        // Offline data never outlives the sign-in (Phase 7-2); loaded only when there is some.
        if (hasOfflineData()) await import('./offline/index').then((module) => module.wipeAllOffline()).catch(() => null);
    }
}

/** Whether this browser keeps any offline store (a cheap check, no IndexedDB). */
export function hasOfflineData() {
    try {
        return JSON.parse(readPref('offline.stores') ?? '[]').length > 0;
    } catch {
        return false;
    }
}

export function can(ability) {
    return session.me?.can?.[ability] === true;
}

/** A client's own person (parent, employee, customer) in its portal (Phase 5C-4). */
export function isPortalMember() {
    return session.me?.context?.type === 'organization' && session.me.context.membership_type === 'portal';
}

export function currentOrganization() {
    return session.me?.context?.type === 'organization' ? session.me.context : null;
}
