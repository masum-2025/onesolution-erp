import { reactive } from 'vue';
import { readPref, writePref } from './storage';

/** Light / dark / system. The Blade shell applies it before first paint too. */
export const theme = reactive({ preference: 'system', dark: false });

const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function apply() {
    theme.dark = theme.preference === 'dark' || (theme.preference === 'system' && !!media?.matches);
    document.documentElement.classList.toggle('dark', theme.dark);
}

export function initTheme() {
    const stored = readPref('theme', 'system');
    theme.preference = ['light', 'dark', 'system'].includes(stored) ? stored : 'system';
    apply();
    media?.addEventListener('change', apply);
}

export function setTheme(preference) {
    theme.preference = preference;
    writePref('theme', preference === 'system' ? null : preference);
    apply();
}
