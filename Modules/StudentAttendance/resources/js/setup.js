import { computed, ref } from 'vue';
import { currentOrganization } from '@/lib/session';
import { attendanceApi } from './api';

/**
 * What every attendance screen needs once (what the person may do, the
 * rules, their sections with subjects, the periods), fetched once per
 * organization and shared.
 */
const cache = new Map();

export function useAttendanceSetup() {
    const id = currentOrganization().id;
    if (!cache.has(id)) {
        const state = { data: ref(null), error: ref(null), loading: ref(false) };
        state.reload = async () => {
            state.loading.value = true;
            state.error.value = null;
            try {
                state.data.value = (await attendanceApi(id).setup()).data;
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
        subjects: computed(() => data.value?.subjects ?? {}),
        periods: computed(() => data.value?.periods ?? []),
        campuses: computed(() => data.value?.campuses ?? []),
        levels: computed(() => data.value?.levels ?? []),
        institution: computed(() => data.value?.institution ?? null),
        campusName: (unitId) => data.value?.campuses?.find((campus) => campus.id === unitId)?.name ?? '',
    };
}
