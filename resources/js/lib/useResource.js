import { ref, shallowRef } from 'vue';

/**
 * Loading / error / data state for one API read. Only the latest call
 * wins, so fast filter changes never show stale results.
 */
export function useResource(fetcher, { immediate = true, keepData = true } = {}) {
    const data = shallowRef(null);
    const error = ref(null);
    const loading = ref(false);
    let sequence = 0;

    async function load(...args) {
        const id = ++sequence;
        loading.value = true;
        error.value = null;
        if (!keepData) data.value = null;

        try {
            const result = await fetcher(...args);
            if (id === sequence) data.value = result;
        } catch (caught) {
            if (id === sequence) error.value = caught;
        } finally {
            if (id === sequence) loading.value = false;
        }
    }

    if (immediate) load();

    return { data, error, loading, reload: load };
}
