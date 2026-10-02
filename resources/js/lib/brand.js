import { reactive } from 'vue';

/**
 * Brand at runtime: colors, font, images and texts come from the server for
 * the address being used; the CSS derives every shade. No per-partner builds.
 */
export const brand = reactive({
    name: '',
    primary_color: '#2B4C9B',
    secondary_color: null,
    // Thin strips under the header (its colors, e.g. the logo's) and, level with it, the sidebar's brand row.
    band_colors: [],
    side_band_color: null,
    font: null,
    support_email: null,
    support_phone: null,
    logo_url: null,
    logo_dark_url: null,
    mark_url: null,
    favicon_url: null,
    tagline: {},
    login_title: {},
    login_text: {},
    footer_text: {},
    terms_url: null,
    privacy_url: null,
    powered_by: null,
});

const COLOR = /^#[0-9A-Fa-f]{6}$/;

function channel(value) {
    const c = value / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

export function luminance(hex) {
    const n = parseInt(hex.slice(1), 16);
    return 0.2126 * channel((n >> 16) & 255) + 0.7152 * channel((n >> 8) & 255) + 0.0722 * channel(n & 255);
}

export function contrast(a, b) {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}

/** White or near-black, whichever reads better on the brand color. */
export function readableOn(hex) {
    return contrast(hex, '#FFFFFF') >= contrast(hex, '#111114') ? '#FFFFFF' : '#111114';
}

/**
 * Same checks as the server (App\Platform\Branding\Contrast): text on the
 * color must reach 4.5:1, and the color must show on a white page (3:1).
 *
 * @returns {'text'|'surface'|null}
 */
export function contrastProblem(hex, usedOnSurface = true) {
    if (!COLOR.test(hex ?? '')) return null;
    if (Math.max(contrast(hex, '#FFFFFF'), contrast(hex, '#111114')) < 4.5) return 'text';
    if (usedOnSurface && contrast(hex, '#FFFFFF') < 3) return 'surface';
    return null;
}

/** A translated brand text (e.g. "login_title") in the language, falling back to English, then any. */
export function brandText(field, locale) {
    const texts = brand[field] ?? {};
    return texts[locale] ?? texts.en ?? Object.values(texts)[0] ?? '';
}

export function taglineFor(locale) {
    return brandText('tagline', locale);
}

export function applyBrand(next) {
    if (!next) return;
    const color = COLOR.test(next.primary_color ?? '') ? next.primary_color : '#2B4C9B';

    Object.assign(brand, next, { primary_color: color });
    const root = document.documentElement.style;
    root.setProperty('--brand', color);
    root.setProperty('--brand-fg', readableOn(color));
    if (next.font) root.setProperty('--font-sans', next.font);
    else root.removeProperty('--font-sans');
    if (next.name) document.title = next.name;
}
