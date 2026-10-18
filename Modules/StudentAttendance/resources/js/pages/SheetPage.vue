<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router';
import { ArrowLeft, CalendarOff, CheckCheck, CloudOff, Eye, MessageSquareText, Save, Users } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { enqueueOffline, offline, offlineRecords } from '@/lib/offline';
import { confirmAction } from '@/lib/dialogs';
import { newOpId } from '@/lib/billing';
import { formatDate, formatNumber, formatRelative } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { useAttendanceSetup } from '../setup';
import { STATUSES, countStates, nextStatus, periodAt, statusTone } from '../lib';

/**
 * One section's attendance of a day, made for a teacher's phone: students
 * in roll order, all present to start; a tap moves a student through
 * absent, late and leave; a note for anyone; "all present" puts everyone
 * back. The counts stay on top and the save button at the bottom. By
 * period, the period running now is picked and others are a tap away.
 *
 * Without a network the sheet is kept on the phone (Offline mode) and sent
 * when back. If someone saved the sheet meanwhile, theirs is fetched and
 * this person's changes are put on top of it to check and save again.
 */
const route = useRoute();
const router = useRouter();
const api = attendanceApi(currentOrganization().id);
const setup = useAttendanceSetup();
const TONE_CLASSES = {
    ok: 'bg-ok-soft text-ok ring-ok/25',
    bad: 'bg-bad-soft text-bad ring-bad/25',
    warn: 'bg-warn-soft text-warn ring-warn/30',
    brand: 'bg-brand-soft text-brand-text ring-brand/25',
};

const state = reactive({ loading: true, error: null, data: null, offline: false });
const students = ref([]);
// What was loaded, to tell what this person changed.
const loaded = ref({});
const subjectId = ref(null);
const saving = ref(false);
const noteFor = ref(null);
const noteText = ref('');

const periodId = computed(() => (typeof route.query.period === 'string' ? route.query.period : null));
const section = computed(() => state.data?.section ?? null);
const byPeriod = computed(() => state.data?.by_period ?? false);
const periods = computed(() => setup.periods.value.filter((period) => period.unit_id === section.value?.unit_id));
const mayTake = computed(() => state.data?.may_take ?? false);
const counts = computed(() => countStates(students.value));
const changed = computed(() => students.value.filter((student) => loaded.value[student.id]?.status !== student.status || (loaded.value[student.id]?.note ?? null) !== (student.note ?? null)));
const dirty = computed(() => changed.value.length > 0 || (!state.data?.sheet && students.value.length > 0 && mayTake.value));
const sectionTitle = computed(() => {
    const info = setup.sections.value.find((item) => item.id === route.params.section);
    return [textIn(info?.level ?? {}), section.value?.name ?? info?.name].filter(Boolean).join(' · ');
});
const subjectChoices = computed(() => (state.data?.my_subjects ?? []).map((id) => ({ id, name: textIn(setup.subjects.value[id]?.name ?? {}) || setup.subjects.value[id]?.code || id })));

function take(data) {
    state.data = data;
    students.value = data.students.map((student) => ({ ...student }));
    loaded.value = Object.fromEntries(data.students.map((student) => [student.id, { status: student.status, note: student.note ?? null }]));
    subjectId.value = data.sheet?.subject_id ?? (data.my_subjects?.length === 1 ? data.my_subjects[0] : null);
}

async function load() {
    state.loading = true;
    state.error = null;
    try {
        take((await api.sheet(route.params.section, route.params.date, periodId.value)).data);
        state.offline = false;
        // By period, without one chosen: the period running now (on today's sheet), else the first.
        if (state.data.by_period && !periodId.value && periods.value.length) {
            const now = new Date();
            const time = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
            const pick = route.params.date === setup.today.value ? periodAt(periods.value, time) : periods.value[0];
            if (pick) router.replace({ query: { ...route.query, period: pick.id } });
        }
    } catch (error) {
        if (error.code === 'network' && offline.enabled && (await loadKept())) return;
        state.error = error;
    } finally {
        state.loading = false;
    }
}

/** Offline: the section and its students as the phone keeps them, everyone present. */
async function loadKept() {
    const kept = (await offlineRecords('student_attendance.sheet')).find((record) => record.type === 'section' && record.id === route.params.section);
    if (!kept) return false;
    take({
        section: { id: kept.id, name: kept.name, unit_id: kept.unit_id, level_id: kept.level_id },
        by_period: kept.by_period, period: null, day_off: { weekend: false, holiday: null }, may_take: true, my_subjects: kept.subjects ?? [], sheet: null,
        students: kept.students.map((student) => ({ ...student, status: 'present', note: null })),
    });
    state.offline = true;
    return true;
}

watch(() => [route.params.section, route.params.date, periodId.value], load, { immediate: true });

function tap(student) {
    if (mayTake.value) student.status = nextStatus(student.status);
}
function allPresent() {
    for (const student of students.value) student.status = 'present';
}
function openNote(student) {
    noteFor.value = student;
    noteText.value = student.note ?? '';
}
function keepNote() {
    noteFor.value.note = noteText.value.trim() || null;
    noteFor.value = null;
}

function body() {
    return {
        period_id: byPeriod.value ? periodId.value : null,
        subject_id: byPeriod.value ? subjectId.value : null,
        marks: students.value.map((student) => ({ student_id: student.id, status: student.status, note: student.note ?? null })),
    };
}

async function save() {
    if (state.offline || globalThis.navigator?.onLine === false) return keepOffline();
    saving.value = true;
    try {
        const response = await api.save(route.params.section, route.params.date, {
            ...body(), op_id: newOpId(), ...(state.data.sheet ? { base_version: state.data.sheet.version } : {}),
        });
        take(response.data);
        toast.success(t('student_attendance.sheet.saved', { absent: formatNumber(counts.value.absent) }));
    } catch (error) {
        if (error.code === 'network') return keepOffline();
        if (error.code === 'version_conflict') return mergeTheirs();
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function keepOffline() {
    if (!offline.enabled) {
        toast.error(t('student_attendance.sheet.offline_off'));
        return;
    }
    await enqueueOffline('student_attendance.sheet', 'create', { section_id: route.params.section, date: route.params.date, ...body() }, null, state.data.sheet?.version ?? null);
    loaded.value = Object.fromEntries(students.value.map((student) => [student.id, { status: student.status, note: student.note ?? null }]));
    toast.success(t('student_attendance.sheet.kept_offline'));
}

/** Someone saved it meanwhile: fetch theirs and put this person's changes on top, to check and save again. */
async function mergeTheirs() {
    const mine = Object.fromEntries(changed.value.map((student) => [student.id, { status: student.status, note: student.note ?? null }]));
    take((await api.sheet(route.params.section, route.params.date, periodId.value)).data);
    for (const student of students.value) Object.assign(student, mine[student.id] ?? {});
    toast.info(t('student_attendance.sheet.merged'));
}

function choosePeriod(id) {
    router.replace({ query: { ...route.query, period: id } });
}

onBeforeRouteLeave(async () => {
    if (!changed.value.length) return true;
    return confirmAction({ title: t('student_attendance.sheet.leave_title'), message: t('student_attendance.sheet.leave_text'), confirmLabel: t('student_attendance.sheet.leave'), danger: true });
});
</script>

<template>
    <div class="pb-28">
        <div class="mb-4 flex items-start gap-2">
            <AppButton size="icon" variant="ghost" :icon="ArrowLeft" :to="{ name: 'student-attendance-today', query: route.params.date === setup.today.value ? {} : { date: route.params.date } }" :aria-label="t('student_attendance.sheet.back')" />
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-[20px] font-semibold tracking-tight text-fg">{{ sectionTitle || t('student_attendance.sheet.title') }}</h1>
                <p class="text-[13px] text-muted">
                    {{ formatDate(route.params.date, { dateStyle: 'full' }) }}<template v-if="state.data?.period"> · {{ textIn(state.data.period.name) }}</template>
                </p>
            </div>
        </div>

        <p v-if="state.offline" class="mb-3 flex items-center gap-2 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="status">
            <CloudOff class="size-4 shrink-0" aria-hidden="true" />{{ t('student_attendance.sheet.offline_note') }}
        </p>
        <p v-if="state.data?.day_off?.weekend || state.data?.day_off?.holiday" class="mb-3 flex items-center gap-2 rounded-xl bg-subtle px-4 py-3 text-[13px] text-fg-2" role="note">
            <CalendarOff class="size-4 shrink-0" aria-hidden="true" />
            {{ state.data.day_off.holiday ? t('student_attendance.day_off.holiday', { name: textIn(state.data.day_off.holiday) }) : t('student_attendance.day_off.weekend') }}
            {{ t('student_attendance.day_off.still') }}
        </p>

        <!-- By period: which period; a teacher of several subjects here picks theirs. -->
        <div v-if="byPeriod && periods.length" class="-mx-1 mb-3 flex gap-1.5 overflow-x-auto px-1 pb-1" role="radiogroup" :aria-label="t('student_attendance.sheet.period')">
            <button
                v-for="period in periods"
                :key="period.id"
                type="button"
                role="radio"
                :aria-checked="period.id === periodId"
                class="shrink-0 rounded-lg px-3 py-2 text-[13px] font-medium ring-1 transition-colors"
                :class="period.id === periodId ? 'bg-brand text-brand-fg ring-brand' : 'bg-surface text-fg-2 ring-line hover:bg-subtle'"
                @click="choosePeriod(period.id)"
            >
                {{ textIn(period.name) }}<span v-if="period.starts_at" class="ms-1 opacity-75">{{ period.starts_at }}</span>
            </button>
        </div>
        <AppField v-if="byPeriod && subjectChoices.length > 1" class="mb-3" :label="t('student_attendance.sheet.subject')">
            <template #default="{ id }">
                <select :id="id" v-model="subjectId" class="field-input" :disabled="!mayTake">
                    <option :value="null" disabled>{{ t('student_attendance.sheet.choose_subject') }}</option>
                    <option v-for="subject in subjectChoices" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                </select>
            </template>
        </AppField>
        <p v-if="byPeriod && !periods.length && !state.loading" class="mb-3 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn">{{ t('student_attendance.sheet.no_periods') }}</p>

        <SkeletonRows v-if="state.loading && !state.data" :rows="8" />
        <ErrorState v-else-if="state.error" :error="state.error" @retry="load()" />
        <template v-else-if="state.data">
            <p v-if="!mayTake" class="mb-3 flex items-center gap-2 rounded-xl bg-subtle px-4 py-3 text-[13px] text-fg-2" role="note">
                <Eye class="size-4 shrink-0" aria-hidden="true" />{{ t('student_attendance.sheet.read_only') }}
            </p>

            <!-- Counts, always in sight. -->
            <div class="sticky top-16 z-10 mb-3 flex flex-wrap items-center gap-1.5 rounded-xl bg-surface/95 p-2 shadow-xs ring-1 ring-line backdrop-blur" aria-live="polite">
                <span v-for="status in STATUSES" :key="status" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[12.5px] font-medium ring-1" :class="TONE_CLASSES[statusTone(status)]">
                    {{ t(`student_attendance.status.${status}`) }} {{ formatNumber(counts[status]) }}
                </span>
                <AppButton v-if="mayTake" class="ms-auto" size="sm" variant="ghost" :icon="CheckCheck" @click="allPresent">{{ t('student_attendance.sheet.all_present') }}</AppButton>
            </div>

            <section v-if="!students.length" class="card">
                <EmptyState :icon="Users" :title="t('student_attendance.sheet.no_students')" />
            </section>
            <ul v-else class="card divide-y divide-line">
                <li v-for="student in students" :key="student.id" class="flex items-center gap-2 px-2 sm:px-3">
                    <button
                        type="button"
                        class="flex min-h-14 min-w-0 flex-1 items-center gap-3 py-2 text-start"
                        :disabled="!mayTake"
                        :aria-label="`${student.name}: ${t(`student_attendance.status.${student.status}`)}. ${mayTake ? t('student_attendance.sheet.tap_to_change') : ''}`"
                        @click="tap(student)"
                    >
                        <span class="tabular grid size-9 shrink-0 place-items-center rounded-full bg-subtle text-[13px] font-semibold text-fg-2">{{ student.roll ? formatNumber(student.roll) : '–' }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium text-fg">{{ student.name }}</span>
                            <span v-if="student.name_local || student.note" class="block truncate text-[12.5px] text-muted">{{ [student.name_local, student.note].filter(Boolean).join(' · ') }}</span>
                        </span>
                        <span class="inline-flex w-24 shrink-0 items-center justify-center rounded-lg px-2 py-1.5 text-[13px] font-semibold ring-1" :class="TONE_CLASSES[statusTone(student.status)]">
                            {{ t(`student_attendance.status.${student.status}`) }}
                        </span>
                    </button>
                    <AppButton v-if="mayTake" size="icon-sm" variant="ghost" :icon="MessageSquareText" :aria-label="t('student_attendance.sheet.note_for', { name: student.name })" @click="openNote(student)" />
                </li>
            </ul>

            <p v-if="state.data.sheet" class="mt-3 text-[12.5px] text-muted">{{ t('student_attendance.sheet.last_saved', { when: formatRelative(state.data.sheet.updated_at) }) }}</p>

            <!-- Save, always at hand. -->
            <div v-if="mayTake" class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur lg:start-[272px]">
                <div class="mx-auto flex max-w-6xl items-center gap-3">
                    <p class="min-w-0 flex-1 truncate text-[13px] text-muted">
                        {{ changed.length ? t('student_attendance.sheet.changes', { count: formatNumber(changed.length) }) : state.data.sheet ? t('student_attendance.sheet.no_changes') : t('student_attendance.sheet.not_saved') }}
                    </p>
                    <AppButton variant="primary" size="lg" :icon="Save" :loading="saving" :disabled="!dirty || (byPeriod && !periodId)" @click="save">{{ t('student_attendance.sheet.save') }}</AppButton>
                </div>
            </div>
        </template>

        <AppDialog :open="!!noteFor" size="sm" :title="t('student_attendance.sheet.note_title', { name: noteFor?.name ?? '' })" @close="noteFor = null">
            <AppField :label="t('student_attendance.sheet.note')" :hint="t('student_attendance.sheet.note_hint')" optional>
                <template #default="{ id }">
                    <input :id="id" v-model="noteText" type="text" maxlength="150" class="field-input" @keydown.enter.prevent="keepNote" />
                </template>
            </AppField>
            <template #footer>
                <AppButton @click="noteFor = null">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton variant="primary" @click="keepNote">{{ t('student_attendance.sheet.keep_note') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
