import { reactive } from 'vue';
import { api } from './http';
import { applyBrand } from './brand';
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

        // Language: the user's own choice, otherwise the organization's default.
        const orgLocale = data.context?.settings?.default_locale;
        if (!readPref('locale') && orgLocale) await setLocale(orgLocale, { remember: false });
    } catch (error) {
        if (error.status !== 401) throw error;
        session.me = null;
    } finally {
        session.ready = true;
    }
    return session.me;
}

/** Sign in with an email, or with { phone, country_code, password } (Phase 5C). */
export async function login(email, password) {
    const body = typeof email === 'object' && email !== null ? email : { email, password };
    await api('/session/login', { method: 'POST', body, silentAuth: true });
    resetCaches();
    return loadMe();
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
    }
}

export function can(ability) {
    return session.me?.can?.[ability] === true;
}

export function currentOrganization() {
    return session.me?.context?.type === 'organization' ? session.me.context : null;
}
