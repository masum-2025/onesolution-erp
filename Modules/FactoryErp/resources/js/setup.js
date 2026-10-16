import { computed, ref } from 'vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { factoryApi } from './api';

/**
 * What every factory screen needs once (what the person may do, the rules,
 * Inventory items and units for bills of materials, own fields), fetched
 * once per organization and shared.
 */
const cache = new Map();

export function useFactorySetup() {
    const id = currentOrganization().id;
    if (!cache.has(id)) {
        const state = { data: ref(null), error: ref(null), loading: ref(false) };
        state.reload = async () => {
            state.loading.value = true;
            state.error.value = null;
            try {
                state.data.value = (await factoryApi(id).setup()).data;
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
    const data = state.data;
    const items = computed(() => Object.fromEntries((data.value?.items ?? []).map((item) => [item.id, item])));

    return {
        ...state,
        can: (ability) => data.value?.can?.[ability] === true,
        rules: computed(() => data.value?.rules ?? {}),
        fields: (entity) => data.value?.fields?.[entity] ?? [],
        currency: computed(() => data.value?.currency ?? null),
        warehouses: computed(() => data.value?.warehouses ?? []),
        warehouseName: (id) => (data.value?.warehouses ?? []).find((warehouse) => warehouse.id === id)?.name ?? '—',
        items,
        itemName: (itemId) => items.value[itemId]?.name ?? '—',
        unitCode: (itemId) => data.value?.units?.[items.value[itemId]?.unit_id]?.code ?? '',
        unitName: (itemId) => textIn(data.value?.units?.[items.value[itemId]?.unit_id]?.name) || '',
    };
}
