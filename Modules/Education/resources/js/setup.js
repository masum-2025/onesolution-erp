import { computed, ref } from 'vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { educationApi } from './api';

/**
 * What every education screen needs once (programs and their levels, lists,
 * years and sessions, campuses, teachers, own fields, what the reader may
 * do), fetched once per organization and shared; reload() after the
 * structure changes.
 */
const cache = new Map();

export function useEducationSetup() {
    const id = currentOrganization().id;
    if (!cache.has(id)) {
        const state = { data: ref(null), error: ref(null), loading: ref(false) };
        state.reload = async () => {
            state.loading.value = true;
            state.error.value = null;
            try {
                state.data.value = (await educationApi(id).setup()).data;
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
    const find = (kind, key) => data.value?.[kind]?.find((item) => item.id === key) ?? null;

    return {
        ...state,
        can: (ability) => data.value?.can?.[ability] === true,
        ready: computed(() => (data.value?.programs?.length ?? 0) > 0),
        program: (programId) => find('programs', programId),
        level: (levelId) => find('levels', levelId),
        session: (sessionId) => find('sessions', sessionId),
        levelsOf: (programId) => (data.value?.levels ?? []).filter((level) => level.program_id === programId).sort((a, b) => a.sequence - b.sequence),
        /** Sessions a program is taught in (its kind: year, semester or term), newest first. */
        sessionsOf: (programId) => (data.value?.sessions ?? []).filter((session) => session.kind === find('programs', programId)?.progression),
        sessionText: (sessionId) => textIn(find('sessions', sessionId)?.name),
        /** A level's place for sorting: its program's order, then its sequence. */
        rank: (levelId) => {
            const level = find('levels', levelId);
            if (!level) return Number.MAX_SAFE_INTEGER;
            const programIndex = (data.value?.programs ?? []).findIndex((program) => program.id === level.program_id);
            return programIndex * 1000 + level.sequence;
        },
        list: (kind) => (data.value?.lists ?? []).filter((item) => item.kind === kind && item.is_active),
        listName: (kind, key) => textIn(data.value?.lists?.find((item) => item.kind === kind && (item.key === key || item.id === key))?.name) || key || '',
        campusName: (unitId) => data.value?.campuses?.find((campus) => campus.id === unitId)?.name ?? '',
        teacherName: (employeeId) => data.value?.teachers?.find((teacher) => teacher.id === employeeId)?.name ?? '',
        fields: (entity) => data.value?.fields?.[entity] ?? [],
        /** What a program calls its levels and sections ("Class"/"Semester", "Section"/"Batch"). */
        levelWord: (programId, fallback) => textIn(find('programs', programId)?.level_label) || fallback,
        sectionWord: (programId, fallback) => textIn(find('programs', programId)?.section_label) || fallback,
        /** The level of a level id with its program's name, for pickers ("Secondary · Class 6"). */
        levelText: (levelId) => {
            const level = find('levels', levelId);
            if (!level) return '';
            return `${textIn(find('programs', level.program_id)?.name)} · ${textIn(level.name)}`;
        },
    };
}

/** Forget a cached set-up (after a preset or structure change elsewhere). */
export function forgetEducationSetup() {
    cache.clear();
}
