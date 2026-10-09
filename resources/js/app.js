import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { applyBrand } from './lib/brand';
import { applySignupOptions } from './lib/identity';
import { initI18n } from './lib/i18n';
import { initTheme } from './lib/theme';
import { readPref } from './lib/storage';

/*
 * Boot: brand and theme first (no flash), then the core translations, then
 * the app. Everything else is lazy-loaded per screen.
 */
async function boot() {
    const el = document.getElementById('app');
    const locales = JSON.parse(el.dataset.locales);

    applyBrand(JSON.parse(el.dataset.brand));
    applySignupOptions(JSON.parse(el.dataset.signup ?? '{}'));
    initTheme();
    // Languages offered here and the hash of their database wording (LANG-1).
    const state = { languages: JSON.parse(el.dataset.languages ?? '[]'), i18n: JSON.parse(el.dataset.i18n ?? '{}') };
    await initI18n(locales, readPref('locale') ?? el.dataset.defaultLocale, state);

    createApp(App).use(router).mount(el);

    // The app itself opens without a connection (Phase 7-2); data offline is the encrypted store's.
    if (import.meta.env.PROD && 'serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(() => null);
    }
}

boot();
