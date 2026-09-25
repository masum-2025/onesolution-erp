import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';
import { applyBrand } from './lib/brand';
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
    initTheme();
    await initI18n(locales, readPref('locale') ?? el.dataset.defaultLocale);

    createApp(App).use(router).mount(el);
}

boot();
