import { watch } from 'vue';

/**
 * Waits until a shared state ({ data, error, loading } refs, as the module
 * setup caches hold) has finished loading; resolves at once when it has.
 */
export function whenLoaded(state) {
    if (!state.loading.value && (state.data.value !== null || state.error.value !== null)) return Promise.resolve();
    return new Promise((resolve) => {
        const stop = watch(() => state.loading.value, (loading) => {
            if (loading) return;
            stop();
            resolve();
        });
    });
}
