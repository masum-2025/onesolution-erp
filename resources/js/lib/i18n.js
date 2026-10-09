import { reactive } from 'vue';
import { writePref } from './storage';
import { emit } from './events';

/**
 * Minimal i18n: UI text lives in locales/{locale}/{namespace}.json and each
 * namespace is its own small chunk, loaded only when a screen needs it.
 * Keys look like "rules.drawer.title"; the first part is the namespace.
 *
 * On top of the files (LANG-1): wording from the database (the platform's,
 * the partner's, the group's and the company's), as a flat key => text
 * "overlay" per language, and languages added without a deploy ("db"
 * languages, whose texts come per namespace, with a file language behind
 * them). Overlays are fetched only for languages that have any, under a hash
 * the browser caches for good; with no wording there is no request at all.
 */
const loaders = import.meta.glob('../locales/*/*.json', { import: 'default' });
// A business module keeps its texts with its code: Modules/<Module>/resources/js/locales/{locale}/{namespace}.json.
const moduleLoaders = Object.fromEntries(
    Object.entries(import.meta.glob('../../../Modules/*/resources/js/locales/*/*.json', { import: 'default' })).map(([path, loader]) => [
        path.split('/locales/')[1],
        loader,
    ]),
);

export const i18n = reactive({
    locale: 'en',
    locales: ['en'],
    // code => { code, name, direction, source: 'file' | 'db', fallback }
    languages: {},
    messages: {},
    // Database wording: locale => { "ns.key": "text" }.
    overlay: {},
    hash: '0',
    // Languages that have database wording here.
    overlays: [],
});

const requested = new Set(['core']);
// What was fetched under which hash: "hash|locale" and "hash|locale|namespace".
const fetched = new Map();

/** A language added in the database: its texts come from the server, its fallback's files stand behind them. */
function isDbLanguage(locale) {
    return i18n.languages[locale]?.source === 'db';
}

function fallbackOf(locale) {
    return isDbLanguage(locale) ? i18n.languages[locale].fallback || 'en' : null;
}

async function fetchTexts(path) {
    try {
        const response = await fetch(`/api/i18n/${i18n.hash}/${path}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        return response.ok ? ((await response.json()).data ?? {}) : {};
    } catch {
        // Offline or a hiccup: the file texts still show.
        return {};
    }
}

/** Once per hash and key: the same request is never made twice, even while one is running. */
function once(memo, load) {
    if (!fetched.has(memo)) fetched.set(memo, load());
    return fetched.get(memo);
}

/** All database wording of a file language (only when it has any here). */
function loadOverlay(locale) {
    if (isDbLanguage(locale) || !i18n.overlays.includes(locale)) return Promise.resolve();
    return once(`${i18n.hash}|${locale}`, async () => {
        i18n.overlay[locale] = { ...(i18n.overlay[locale] ?? {}), ...(await fetchTexts(encodeURIComponent(locale))) };
    });
}

async function loadFile(locale, namespace) {
    i18n.messages[locale] ??= {};
    if (i18n.messages[locale][namespace]) return;

    const loader = loaders[`../locales/${locale}/${namespace}.json`] ?? moduleLoaders[`${locale}/${namespace}.json`];
    i18n.messages[locale][namespace] = loader ? await loader() : {};
}

async function loadOne(locale, namespace) {
    const fallback = fallbackOf(locale);
    if (!fallback) return Promise.all([loadFile(locale, namespace), loadOverlay(locale)]);

    // A database language: its own texts for this namespace, its fallback's file and wording behind them.
    await Promise.all([
        loadFile(fallback, namespace),
        loadOverlay(fallback),
        once(`${i18n.hash}|${locale}|${namespace}`, async () => {
            i18n.overlay[locale] = { ...(i18n.overlay[locale] ?? {}), ...(await fetchTexts(`${encodeURIComponent(locale)}/${namespace}`)) };
        }),
    ]);
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
    applyDocumentLocale(locale);
    // Data from the server (module and organization names, rule labels) was
    // translated in the old language: listeners drop caches and fetch it again.
    emit('locale-changed', locale);
}

/**
 * The languages offered here and the state of their database wording, from
 * the page or /api/me: { languages: [...], locales: [...], i18n: { hash, overlays } }.
 * A new hash (other context, changed wording) drops the old wording and loads
 * the current one for the language in use.
 */
export async function configureLanguages({ languages, locales, i18n: state } = {}) {
    if (Array.isArray(languages)) {
        i18n.languages = Object.fromEntries(languages.map((language) => [language.code, language]));
    }
    if (Array.isArray(locales) && locales.length) i18n.locales = locales;

    const hash = state?.hash ?? '0';
    const changed = hash !== i18n.hash;
    i18n.hash = hash;
    i18n.overlays = state?.overlays ?? [];
    if (changed) {
        i18n.overlay = {};
        fetched.clear();
    }

    if (!i18n.locales.includes(i18n.locale)) {
        await setLocale(i18n.locales[0], { remember: false });
    } else if (changed) {
        await loadNamespaces([]);
    }
}

/** A language's name in itself ("বাংলা", "हिन्दी"), for pickers. */
export function languageName(code) {
    return i18n.languages[code]?.name ?? code;
}

/**
 * Writing direction of a language: Arabic, Persian, Hebrew, Urdu… are right to
 * left. Layouts use logical CSS (start/end), so flipping `dir` is enough.
 */
export function direction(locale) {
    const known = i18n.languages[locale]?.direction;
    if (known) return known;
    try {
        const info = new Intl.Locale(locale);
        const textInfo = info.textInfo ?? info.getTextInfo?.();
        if (textInfo?.direction) return textInfo.direction;
    } catch {
        // Unknown tag: fall through to the list.
    }
    return ['ar', 'fa', 'he', 'ur', 'ps', 'ckb', 'dv', 'yi'].includes(String(locale).split(/[-_]/)[0]) ? 'rtl' : 'ltr';
}

function applyDocumentLocale(locale) {
    document.documentElement.lang = locale;
    document.documentElement.dir = direction(locale);
}

/**
 * Boot: the languages of the page (codes, or the page's full state with
 * languages and the wording hash), then the core texts.
 */
export async function initI18n(locales, locale, state = null) {
    i18n.locales = locales;
    if (state) {
        i18n.languages = Object.fromEntries((state.languages ?? []).map((language) => [language.code, language]));
        i18n.hash = state.i18n?.hash ?? '0';
        i18n.overlays = state.i18n?.overlays ?? [];
    }
    i18n.locale = locales.includes(locale) ? locale : locales[0];
    applyDocumentLocale(i18n.locale);
    return loadNamespaces(['core']);
}

function fileLookup(locale, key) {
    const [namespace, ...path] = key.split('.');
    let node = i18n.messages[locale]?.[namespace];
    for (const part of path) {
        if (node === undefined || node === null) return undefined;
        node = node[part];
    }
    return typeof node === 'string' ? node : undefined;
}

/** Database wording first, then the file; a database language then its fallback's. */
function lookup(locale, key) {
    const own = i18n.overlay[locale]?.[key] ?? fileLookup(locale, key);
    if (own !== undefined) return own;
    const fallback = fallbackOf(locale);
    return fallback ? (i18n.overlay[fallback]?.[key] ?? fileLookup(fallback, key)) : undefined;
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
