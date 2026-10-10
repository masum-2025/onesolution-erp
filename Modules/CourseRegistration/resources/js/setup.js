import { computed, ref } from 'vue';
import { currentOrganization } from '@/lib/session';
import { readPref, writePref } from '@/lib/storage';
import { textIn } from '@/lib/texts';
import { registrationApi } from './api';

/**
 * What every course registration screen needs once (rules, sessions,
 * classes, campuses, teachers, what the reader may do), fetched once per
 * organization and shared. The chosen session is shared too and kept per
 * browser, so moving between screens stays in the same session.
 */
const cache = new Map();
const SESSION_KEY = 'crs.session';

function remembered() {
    return readPref(SESSION_KEY);
}

export function useRegistrationSetup() {
    const id = currentOrganization().id;
    if (!cache.has(id)) {
        const state = { data: ref(null), error: ref(null), loading: ref(false), sessionId: ref(remembered() ?? '') };
        state.reload = async () => {
            state.loading.value = true;
            state.error.value = null;
            try {
                state.data.value = (await registrationApi(id).setup(state.sessionId.value || null)).data;
                const sessions = state.data.value.sessions ?? [];
                if (!sessions.some((session) => session.id === state.sessionId.value)) {
                    state.sessionId.value = sessions.find((session) => session.status === 'open')?.id ?? sessions[0]?.id ?? '';
                    if (state.sessionId.value) state.data.value = (await registrationApi(id).setup(state.sessionId.value)).data;
                }
            } catch (error) {
                state.error.value = error;
            } finally {
                state.loading.value = false;
            }
        };
        state.choose = (sessionId) => {
            state.sessionId.value = sessionId;
            writePref(SESSION_KEY, sessionId);
            return state.reload();
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
        window: computed(() => data.value?.window ?? null),
        session: computed(() => data.value?.sessions?.find((session) => session.id === state.sessionId.value) ?? null),
        sessionName: (sessionId) => textIn(data.value?.sessions?.find((session) => session.id === sessionId)?.name),
        level: (levelId) => data.value?.levels?.find((level) => level.id === levelId) ?? null,
        /** "BSc CSE · Semester 1" */
        levelText: (levelId) => {
            const level = data.value?.levels?.find((item) => item.id === levelId);
            return level ? `${textIn(level.program.name)} · ${textIn(level.name)}` : '';
        },
        campusName: (unitId) => data.value?.campuses?.find((campus) => campus.id === unitId)?.name ?? '',
        teacherName: (employeeId) => data.value?.teachers?.find((teacher) => teacher.id === employeeId)?.name ?? '',
    };
}
