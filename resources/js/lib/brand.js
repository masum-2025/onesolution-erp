import { reactive } from 'vue';

/**
 * Partner brand at runtime: one primary color in, readable text color and
 * every shade out (the CSS derives the shades). No per-partner builds.
 */
export const brand = reactive({ name: '', primary_color: '#4F46E5', support_email: null });

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

export function applyBrand(next) {
    if (!next) return;
    const color = /^#[0-9A-Fa-f]{6}$/.test(next.primary_color ?? '') ? next.primary_color : '#4F46E5';

    Object.assign(brand, next, { primary_color: color });
    const root = document.documentElement.style;
    root.setProperty('--brand', color);
    root.setProperty('--brand-fg', readableOn(color));
    if (next.name) document.title = next.name;
}
