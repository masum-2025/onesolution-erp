import { computed, ref } from 'vue';
import { currentOrganization } from '@/lib/session';
import { crmApi } from './api';

/**
 * What every CRM screen needs once (currency, pipelines, extra fields,
 * people, VAT codes), fetched once per organization and shared; reload()
 * after the set-up changes.
 */
const cache = new Map();

export function useCrmSetup() {
    const id = currentOrganization().id;
    if (!cache.has(id)) {
        const state = { data: ref(null), error: ref(null), loading: ref(false) };
        state.reload = async () => {
            state.loading.value = true;
            state.error.value = null;
            try {
                state.data.value = (await crmApi(id).setup()).data;
            } catch (error) {
                state.error.value = error;
            } finally {
                state.loading.value = false;
            }
        };
        state.reload();
        cache.set(id, state);
    }
    const state = cache.get(id);

    return {
        ...state,
        currency: computed(() => state.data.value?.currency ?? 'BDT'),
        fields: (entity) => state.data.value?.fields?.[entity] ?? [],
        personName: (userId) => state.data.value?.people?.find((person) => person.id === userId)?.name ?? '',
    };
}
