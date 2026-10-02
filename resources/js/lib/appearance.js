import { reactive } from 'vue';
import { api } from './http';
import { brand, readableOn } from './brand';
import { writePref } from './storage';

/**
 * How the app looks for this person here: template (sidebar style), highlight
 * color and readability. The server decides (an organization may lock the
 * template and color; readability is always the person's own) and sends it
 * with /api/me. Applied as data-* attributes on <html>; the CSS does the rest.
 */

// Fixed highlight colors. Each one carries white text at 4.5:1 or more and
// shows on a white page at 3:1 or more (checked in tests/js/appearance.test.js).
export const ACCENTS = {
    indigo: '#4F46E5',
    blue: '#2563EB',
    teal: '#0F766E',
    green: '#15803D',
    violet: '#7C3AED',
    rose: '#BE123C',
    orange: '#C2410C',
    slate: '#475569',
};

export const TEMPLATES = ['classic', 'light'];

export const appearance = reactive({
    template: 'classic',
    accent: 'brand',
    color_vision: 'standard',
    contrast: 'standard',
    // Full answer from the server: value, source, lock and allowed choices per setting.
    details: null,
});

/** The color an accent name stands for ("brand" = the partner's brand color). */
export function accentColor(name) {
    return ACCENTS[name] ?? brand.primary_color;
}

function setAttribute(name, value) {
    document.documentElement.setAttribute(`data-${name}`, value);
}

/** Apply the server's answer (or a choice being tried) to the page. */
export function applyAppearance(next) {
    if (!next) return;
    const value = (setting, fallback) => (typeof next[setting] === 'object' && next[setting] !== null ? next[setting].value : next[setting]) ?? fallback;

    appearance.details = next.template && typeof next.template === 'object' ? next : appearance.details;
    appearance.template = TEMPLATES.includes(value('template')) ? value('template') : 'classic';
    appearance.accent = value('accent', 'brand');
    appearance.color_vision = value('color_vision', 'standard');
    appearance.contrast = value('contrast', 'standard');

    setAttribute('shell', appearance.template);
    setAttribute('vision', appearance.color_vision);
    setAttribute('contrast', appearance.contrast);

    const color = accentColor(appearance.accent);
    const root = document.documentElement.style;
    root.setProperty('--brand', color);
    root.setProperty('--brand-fg', readableOn(color));

    // Remembered on this browser only so the next page load starts in the right look
    // (the Blade shell applies these before first paint). Not personal data.
    writePref('shell', appearance.template);
    writePref('vision', appearance.color_vision === 'standard' ? null : appearance.color_vision);
    writePref('contrast', appearance.contrast === 'standard' ? null : appearance.contrast);
}

/** Whether a setting is decided by the organization (locked) for this person. */
export function isLocked(setting) {
    return !!appearance.details?.[setting]?.locked;
}

/** Choices this person may pick for a setting here. */
export function allowedChoices(setting) {
    return appearance.details?.[setting]?.allowed ?? (setting === 'template' ? TEMPLATES : ['brand', ...Object.keys(ACCENTS)]);
}

/**
 * Save the person's own choice; null clears it (back to the organization's).
 * Returns the server message. The caller reloads /api/me for the result here.
 */
export async function saveAppearance(changes) {
    const response = await api('/api/me/appearance', { method: 'PUT', body: changes });
    return response.message;
}
