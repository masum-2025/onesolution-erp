import { computed, ref } from 'vue';
import { currentOrganization } from '@/lib/session';
import { lecturesApi } from './api';

/**
 * What every lecture screen needs once (what the reader may do, the rules,
 * the open session's sections and the subjects they may post for), fetched
 * once per organization and shared.
 */
const cache = new Map();

export function useLecturesSetup() {
    const id = currentOrganization().id;
    if (!cache.has(id)) {
        const state = { data: ref(null), error: ref(null), loading: ref(false) };
        state.reload = async () => {
            state.loading.value = true;
            state.error.value = null;
            try {
                state.data.value = (await lecturesApi(id).setup()).data;
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
        today: computed(() => data.value?.today ?? ''),
        sections: computed(() => data.value?.sections ?? []),
        // Sections with at least one subject the person may post for.
        postable: computed(() => (data.value?.sections ?? []).map((section) => ({ ...section, subjects: section.subjects.filter((subject) => subject.may_post) })).filter((section) => section.subjects.length)),
    };
}
