import { i18n } from './i18n';

/*
 * Human names for codes (country, language, currency, timezone) from the
 * browser's Intl data, in the user's language. Unknown codes stay as codes.
 */
const cache = {};

function names(type) {
    const key = `${i18n.locale}:${type}`;
    if (!(key in cache)) {
        try {
            cache[key] = new Intl.DisplayNames([i18n.locale], { type });
        } catch {
            cache[key] = null;
        }
    }
    return cache[key];
}

function name(type, code) {
    if (!code) return '—';
    try {
        return names(type)?.of(code) ?? code;
    } catch {
        return code;
    }
}

export const countryName = (code) => name('region', code);
export const languageName = (code) => name('language', code);
export const currencyName = (code) => name('currency', code);

/** Display value for an organization setting key. */
export function settingValue(key, value) {
    if (value === null || value === undefined || value === '') return '—';
    switch (key) {
        case 'country_code':
            return countryName(value);
        case 'default_locale':
            return languageName(value);
        case 'currency_code':
            return `${currencyName(value)} (${value})`;
        case 'timezone':
            return String(value).replace(/_/g, ' ');
        default:
            return String(value);
    }
}

/** Setting source from the API ("self" | "inherited" | "platform_default") to SourceBadge kind. */
export function settingSource(entry) {
    return entry?.source === 'self' ? 'self' : entry?.source === 'inherited' ? 'inherited' : 'default';
}
