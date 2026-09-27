import { i18n } from './i18n';

/**
 * Data labels in several languages ({ en: '…', bn: '…', ar: '…' }). Forms show
 * one field per language the app speaks, so a new language needs only its
 * translation files, never a form change. English is the fallback: required
 * where a label is required.
 */
export const FALLBACK_LOCALE = 'en';

/** Languages to show fields for: the fallback first, then the others in app order. */
export function textLocales() {
    return [FALLBACK_LOCALE, ...i18n.locales.filter((locale) => locale !== FALLBACK_LOCALE)];
}

/** A form value with a (possibly empty) text for every language. */
export function textsFor(values) {
    return Object.fromEntries(textLocales().map((locale) => [locale, values?.[locale] ?? '']));
}

/** Trimmed texts without the empty ones, as the API takes them. */
export function cleanTexts(texts) {
    return Object.fromEntries(
        Object.entries(texts ?? {})
            .map(([locale, text]) => [locale, String(text ?? '').trim()])
            .filter(([, text]) => text !== ''),
    );
}

/** The text in the current language, else the fallback, else any. */
export function textIn(texts, locale = i18n.locale) {
    if (!texts) return '';
    return texts[locale] || texts[FALLBACK_LOCALE] || Object.values(texts).find(Boolean) || '';
}
