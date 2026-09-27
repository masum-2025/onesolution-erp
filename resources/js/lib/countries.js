import { reactive } from 'vue';
import { api } from './http';
import { cached } from './cache';
import { on } from './events';

/**
 * The countries the platform knows (GET /api/countries, Phase 6), in the
 * current language. Loaded once, again after a language change.
 */
export const countries = reactive({ list: [] });

export async function loadCountries() {
    countries.list = await cached('countries', () => api('/api/countries').then((response) => response.data));
    return countries.list;
}

on('locale-changed', () => {
    countries.list = [];
});
