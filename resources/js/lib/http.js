import { emit } from './events';
import { i18n, t } from './i18n';

/**
 * JSON client for the app's own API. Uses the session cookie (HttpOnly) and
 * the CSRF cookie; no token is ever stored in the browser.
 */
export class ApiError extends Error {
    constructor({ status, code = null, message, errors = {}, retryAfter = null, data = null }) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.errors = errors;
        this.retryAfter = retryAfter;
        this.data = data;
    }

    /** First validation message for a field, if any. */
    field(name) {
        return this.errors?.[name]?.[0] ?? null;
    }
}

function csrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
}

function buildUrl(path, query) {
    const url = new URL(path, window.location.origin);
    Object.entries(query ?? {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value);
    });
    return url.pathname + url.search;
}

async function send(path, { method = 'GET', body, query, signal } = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Locale': i18n.locale,
    };
    const token = csrfToken();
    if (token) headers['X-XSRF-TOKEN'] = token;
    // Files go as multipart (the browser sets the boundary); everything else as JSON.
    const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
    if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json';

    return fetch(buildUrl(path, query), {
        method,
        headers,
        body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
        credentials: 'same-origin',
        signal,
    });
}

async function readJson(response) {
    const text = await response.text();
    if (!text) return null;
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}

export async function toApiError(response) {
    const data = await readJson(response);
    const retryAfter = Number(response.headers.get('Retry-After')) || null;
    const status = response.status;

    let message = data?.message;
    if (status === 429) message = t('core.errors.throttled', { seconds: retryAfter ?? 60 });
    else if (status >= 500 || !message) message = t('core.errors.server');

    return new ApiError({
        status,
        code: data?.code ?? null,
        message,
        errors: data?.errors ?? {},
        retryAfter,
        data,
    });
}

/**
 * @returns {Promise<any>} The parsed JSON body.
 * @throws {ApiError}
 */
export async function api(path, options = {}) {
    let response;
    try {
        response = await send(path, options);
        // Expired CSRF token (e.g. tab left open): refresh it once and retry.
        if (response.status === 419) {
            await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            response = await send(path, options);
        }
    } catch (error) {
        if (error?.name === 'AbortError') throw error;
        throw new ApiError({ status: 0, code: 'network', message: t('core.errors.network') });
    }

    if (response.ok) return readJson(response);

    const error = await toApiError(response);

    if (error.status === 401 && !options.silentAuth) emit('unauthenticated');
    if (error.status === 403 && error.code === 'no_context') emit('context-lost');

    throw error;
}
