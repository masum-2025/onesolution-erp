import { computed, ref } from 'vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { examsApi } from './api';

/**
 * What every exam screen needs once (grading schemes, sessions, classes,
 * subjects, campuses, teachers to pick, rules, what the reader may do),
 * fetched once per organization and shared.
 */
const cache = new Map();

export function useExamsSetup() {
    const id = currentOrganization().id;
    if (!cache.has(id)) {
        const state = { data: ref(null), error: ref(null), loading: ref(false) };
        state.reload = async () => {
            state.loading.value = true;
            state.error.value = null;
            try {
                state.data.value = (await examsApi(id).setup()).data;
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
    const find = (list, itemId) => data.value?.[list]?.find((item) => item.id === itemId) ?? null;

    return {
        ...state,
        can: (ability) => data.value?.can?.[ability] === true,
        rules: computed(() => data.value?.rules ?? {}),
        today: computed(() => data.value?.today ?? ''),
        scheme: (schemeId) => find('schemes', schemeId),
        schemeName: (schemeId) => textIn(find('schemes', schemeId)?.name) || '—',
        sessionName: (sessionId) => textIn(find('sessions', sessionId)?.name) || '—',
        /** "Secondary · Class 6": the program shows when there is more than one. */
        levelName: (levelId) => {
            const level = find('levels', levelId);
            if (!level) return '—';
            const programs = new Set((data.value?.levels ?? []).map((item) => item.program_id));
            return programs.size > 1 ? `${textIn(level.program?.name)} · ${textIn(level.name)}` : textIn(level.name);
        },
        campusName: (unitId) => find('campuses', unitId)?.name ?? '',
        teacherName: (teacherId) => find('teachers', teacherId)?.name ?? '',
    };
}
