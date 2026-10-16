import { computed, ref } from 'vue';
import { currentOrganization } from '@/lib/session';
import { feedbackApi } from './api';

/**
 * What every teacher feedback screen needs once (what the reader may do, the
 * rules, sessions, the ready-made questions), fetched once per organization.
 */
const cache = new Map();

export function useFeedbackSetup() {
    const id = currentOrganization().id;
    if (!cache.has(id)) {
        const state = { data: ref(null), error: ref(null), loading: ref(false) };
        state.reload = async () => {
            state.loading.value = true;
            state.error.value = null;
            try {
                state.data.value = (await feedbackApi(id).setup()).data;
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

    return {
        ...state,
        can: (ability) => data.value?.can?.[ability] === true,
        rules: computed(() => data.value?.rules ?? {}),
        sessions: computed(() => data.value?.sessions ?? []),
    };
}
