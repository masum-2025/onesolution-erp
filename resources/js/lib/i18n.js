import { reactive } from 'vue';
import { writePref } from './storage';
import { emit } from './events';

/**
 * Minimal i18n: UI text lives in locales/{locale}/{namespace}.json and each
 * namespace is its own small chunk, loaded only when a screen needs it.
 * Keys look like "rules.drawer.title"; the first part is the namespace.
 */
const loaders = import.meta.glob('../locales/*/*.json', { import: 'default' });

export const i18n = reactive({
    locale: 'en',
    locales: ['en'],
    messages: {},
});

const requested = new Set(['core']);

async function loadOne(locale, namespace) {
    i18n.messages[locale] ??= {};
    if (i18n.messages[locale][namespace]) return;

    const loader = loaders[`../locales/${locale}/${namespace}.json`];
    i18n.messages[locale][namespace] = loader ? await loader() : {};
}

export async function loadNamespaces(namespaces, locale = i18n.locale) {
    namespaces.forEach((namespace) => requested.add(namespace));
    await Promise.all([...requested].map((namespace) => loadOne(locale, namespace)));
}

export async function setLocale(locale, { remember = true } = {}) {
    if (!i18n.locales.includes(locale)) return;
    if (remember) writePref('locale', locale);
    if (locale === i18n.locale) return;

    await loadNamespaces([], locale);
    i18n.locale = locale;
    document.documentElement.lang = locale;
    // Data from the server (module and organization names, rule labels) was
    // translated in the old language: listeners drop caches and fetch it again.
    emit('locale-changed', locale);
}

export function initI18n(locales, locale) {
    i18n.locales = locales;
    i18n.locale = locales.includes(locale) ? locale : locales[0];
    document.documentElement.lang = i18n.locale;
    return loadNamespaces(['core']);
}

function lookup(locale, key) {
    const [namespace, ...path] = key.split('.');
    let node = i18n.messages[locale]?.[namespace];
    for (const part of path) {
        if (node === undefined || node === null) return undefined;
        node = node[part];
    }
    return typeof node === 'string' ? node : undefined;
}

const pluralRules = {};

/**
 * Translate a key. Params replace {name} placeholders. With a numeric
 * `count`, "key_one" / "key_other" are tried first (Intl plural rules).
 * A missing key returns the key itself, so gaps are visible, never blank.
 */
export function t(key, params = {}) {
    const locale = i18n.locale;
    let text;

    if (typeof params.count === 'number') {
        pluralRules[locale] ??= new Intl.PluralRules(locale);
        text = lookup(locale, `${key}_${pluralRules[locale].select(params.count)}`) ?? lookup(locale, `${key}_other`);
    }

    text ??= lookup(locale, key) ?? key;

    return text.replace(/\{(\w+)\}/g, (match, name) => {
        const value = params[name];
        if (value === undefined) return match;
        // Counts use the language's own digits (e.g. Bangla digits in bn).
        return typeof value === 'number' ? new Intl.NumberFormat(locale).format(value) : String(value);
    });
}

/** Whether a translation exists (used to fall back to server-provided labels). */
export function has(key) {
    return lookup(i18n.locale, key) !== undefined;
}
