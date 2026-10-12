<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute } from 'vue-router';
import { ArrowLeft, Check, CircleAlert, CloudOff, LoaderCircle, Search, Send, Undo2, UserX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber, formatRelative } from '@/lib/format';
import { currentOrganization, session } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { cellText, gradePaper, normalizeNumberText, readCell, sheetProgress, sheetTone } from '../lib';
import { marksShown, partName, pointShown, subjectName } from '../labels';

/**
 * Entering a paper's marks for one section. Each cell takes marks up to the
 * part's full marks, or "a" for absent (Bangla digits too); Enter or the
 * arrows move down and up. Marks save by themselves shortly after typing
 * (and try again when the network comes back); the grade shows as the
 * server will work it out. Handing in needs every student done; with
 * moderation someone else then approves it or sends it back with a note,
 * which they can do from this page too.
 */
const route = useRoute();
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();
const me = computed(() => session.me?.user?.id);

const sheet = ref(null);
const loading = ref(true);
const loadError = ref(null);
const cells = reactive({}); // studentId -> partId -> { text, kind, centi, absent, reason }

function fill(data, keepPending = true) {
    sheet.value = data;
    for (const student of data.students) {
        cells[student.id] ??= {};
        for (const part of data.parts) {
            // Typed and not saved yet (or not valid yet): what is on screen stays.
            if (keepPending && (pending.has(key(student.id, part.id)) || cells[student.id][part.id]?.kind === 'invalid')) continue;
            const mark = student.marks?.[part.id] ?? null;
            cells[student.id][part.id] = { text: cellText(mark), kind: mark ? (mark.absent ? 'absent' : 'marks') : 'empty', centi: mark?.marks_centi ?? null, absent: Boolean(mark?.absent), reason: null };
        }
    }
}
async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        fill((await api.sheet(route.params.id)).data, false);
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const parts = computed(() => sheet.value?.parts ?? []);
const students = computed(() => sheet.value?.students ?? []);
const editable = computed(() => Boolean(sheet.value?.mine && ['open', 'returned'].includes(sheet.value.status) && sheet.value.exam?.status === 'marking'));
const bands = computed(() => setup.scheme(sheet.value?.exam?.grading_scheme_id)?.bands ?? []);
const title = computed(() => (sheet.value ? `${subjectName(sheet.value.paper?.subject)} · ${setup.levelName(sheet.value.section?.level_id)} ${sheet.value.section?.name ?? ''}` : ''));

// Search (large classes).
const query = ref('');
const shown = computed(() => {
    const term = normalizeNumberText(query.value).toLowerCase();
    if (!term) return students.value;
    return students.value.filter((student) => [student.name, student.name_local, student.code].some((value) => String(value ?? '').toLowerCase().includes(term)));
});

// Grades as the server works them out (once a student is done).
function grade(student) {
    const row = parts.value.map((part) => ({ part, cell: cells[student.id]?.[part.id] }));
    const anyAbsent = row.some(({ cell }) => cell?.absent);
    if (!anyAbsent && row.some(({ cell }) => !cell || cell.kind === 'empty' || cell.kind === 'invalid')) return null;
    return gradePaper(row.map(({ part, cell }) => ({
        full_marks_centi: part.full_marks_centi, pass_marks_centi: part.pass_marks_centi, marks_centi: cell?.kind === 'marks' ? cell.centi : null, absent: Boolean(cell?.absent) || cell?.kind !== 'marks',
    })), sheet.value.paper?.pass_marks_centi ?? 0, bands.value);
}
const progress = computed(() => sheetProgress(students.value, parts.value, cells));
const summary = computed(() => {
    let passed = 0;
    let failed = 0;
    for (const student of students.value) {
        const result = grade(student);
        if (result) result.passed ? passed++ : failed++;
    }
    return { passed, failed };
});

// Saving: typed cells wait a moment, then go together; a failed network try waits and tries again.
const pending = new Map(); // "student:part" -> row
const pendingCount = ref(0);
const saving = ref(false);
const saveError = ref(null);
const savedAt = ref(null);
let timer = null;
let retry = null;
const key = (studentId, partId) => `${studentId}:${partId}`;

function edit(student, part, text) {
    const cell = readCell(text, part.full_marks_centi);
    cells[student.id][part.id] = { text, kind: cell.kind, centi: cell.centi, absent: cell.kind === 'absent', reason: cell.reason ?? null };
    if (cell.kind === 'invalid') {
        pending.delete(key(student.id, part.id));
    } else {
        pending.set(key(student.id, part.id), { student_id: student.id, part_id: part.id, marks_centi: cell.kind === 'marks' ? cell.centi : null, absent: cell.kind === 'absent' });
    }
    pendingCount.value = pending.size;
    schedule(1200);
}
function schedule(ms) {
    clearTimeout(timer);
    timer = setTimeout(flush, ms);
}
async function flush() {
    clearTimeout(timer);
    if (saving.value || !pending.size || !editable.value) return;
    const batch = new Map(pending);
    pending.clear();
    saving.value = true;
    try {
        const { data } = await api.saveMarks(sheet.value.id, [...batch.values()]);
        fill(data);
        saveError.value = null;
        savedAt.value = new Date().toISOString();
    } catch (error) {
        // Put back what was not typed over meanwhile; the network tries again by itself, a refusal waits for a fix.
        for (const [cellKey, row] of batch) if (!pending.has(cellKey)) pending.set(cellKey, row);
        saveError.value = error;
        if (error.status === 0 || error.status === 429 || error.status >= 500) {
            clearTimeout(retry);
            retry = setTimeout(flush, 8000);
        } else {
            toast.error(error.message);
        }
    } finally {
        saving.value = false;
        pendingCount.value = pending.size;
        if (pending.size && !saveError.value) schedule(300);
    }
}
const online = () => pending.size && flush();
onMounted(() => window.addEventListener('online', online));
function warnUnload(event) {
    if (!pending.size && !saving.value) return;
    event.preventDefault();
    event.returnValue = '';
}
onMounted(() => window.addEventListener('beforeunload', warnUnload));
onBeforeUnmount(() => {
    window.removeEventListener('online', online);
    window.removeEventListener('beforeunload', warnUnload);
    clearTimeout(timer);
    clearTimeout(retry);
});
onBeforeRouteLeave(async () => {
    if (pending.size && !saving.value) await flush();
    if (!pending.size && !saving.value) return true;
    const confirmed = await confirmAction({ title: t('education_exams.entry.leave_title'), message: t('education_exams.entry.leave_text'), confirmLabel: t('education_exams.entry.leave'), danger: true });
    return Boolean(confirmed);
});
const invalidCount = computed(() => students.value.reduce((count, student) => count + parts.value.filter((part) => cells[student.id]?.[part.id]?.kind === 'invalid').length, 0));
const saveState = computed(() => {
    if (saving.value) return { icon: LoaderCircle, spin: true, text: t('education_exams.entry.saving'), tone: 'text-muted' };
    if (saveError.value && pendingCount.value) return { icon: saveError.value.status === 0 ? CloudOff : CircleAlert, text: t(saveError.value.status === 0 ? 'education_exams.entry.offline' : 'education_exams.entry.not_saved', { count: formatNumber(pendingCount.value) }), tone: 'text-bad' };
    if (pendingCount.value) return { icon: LoaderCircle, text: t('education_exams.entry.waiting', { count: formatNumber(pendingCount.value) }), tone: 'text-muted' };
    if (savedAt.value) return { icon: Check, text: t('education_exams.entry.saved', { when: formatRelative(savedAt.value) }), tone: 'text-ok' };
    return null;
});

// Moving between cells.
function move(event, row, col, step) {
    event.preventDefault();
    document.getElementById(`cell-${row + step}-${col}`)?.focus();
}
function markRowAbsent(student) {
    const allAbsent = parts.value.every((part) => cells[student.id][part.id].absent);
    for (const part of parts.value) edit(student, part, allAbsent ? '' : 'a');
}

// Handing in, approving, sending back.
const acting = ref(false);
async function handIn() {
    await flush();
    if (pending.size || invalidCount.value) {
        toast.error(t('education_exams.entry.fix_first'));
        return;
    }
    if (progress.value.missing) {
        toast.error(t('education_exams.entry.missing', { count: formatNumber(progress.value.missing) }));
        return;
    }
    const moderated = setup.rules.value.moderation_required;
    const confirmed = await confirmAction({
        title: t('education_exams.entry.hand_in_title'),
        message: t(moderated ? 'education_exams.entry.hand_in_text' : 'education_exams.entry.hand_in_final'),
        details: [t('education_exams.entry.hand_in_counts', { passed: formatNumber(summary.value.passed), failed: formatNumber(summary.value.failed) })],
        confirmLabel: t('education_exams.entry.hand_in'),
    });
    if (!confirmed) return;
    acting.value = true;
    try {
        fill((await api.submitSheet(sheet.value.id, sheet.value.version)).data, false);
        toast.success(t(sheet.value.status === 'approved' ? 'education_exams.entry.handed_in_approved' : 'education_exams.entry.handed_in'));
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') load();
    } finally {
        acting.value = false;
    }
}
const canDecide = computed(() => sheet.value?.status === 'submitted' && setup.can('moderate') && sheet.value.submitted_by !== me.value);
async function approve() {
    acting.value = true;
    try {
        fill((await api.approveSheet(sheet.value.id, sheet.value.version)).data, false);
        toast.success(t('education_exams.sheets.approved'));
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') load();
    } finally {
        acting.value = false;
    }
}
async function sendBack() {
    const confirmed = await confirmAction({
        title: t('education_exams.sheets.send_back_title'), message: t('education_exams.sheets.send_back_text'),
        reason: 'required', reasonLabel: t('education_exams.sheets.send_back_note'), reasonPlaceholder: t('education_exams.sheets.send_back_placeholder'),
        confirmLabel: t('education_exams.sheets.send_back'),
    });
    if (!confirmed) return;
    acting.value = true;
    try {
        fill((await api.sendBackSheet(sheet.value.id, confirmed.reason, sheet.value.version)).data, false);
        toast.success(t('education_exams.sheets.sent_back'));
    } catch (error) {
        toast.error(error.errors?.note?.[0] ?? error.message);
    } finally {
        acting.value = false;
    }
}
watch(() => route.params.id, (id, old) => id && id !== old && load());

const why = computed(() => {
    const value = sheet.value;
    if (!value || editable.value) return '';
    if (value.exam?.status !== 'marking') return t('education_exams.entry.exam_not_marking');
    if (!value.mine) return t('education_exams.entry.not_yours');
    return t(`education_exams.entry.status_${value.status}`);
});
</script>

<template>
    <div>
        <RouterLink :to="sheet ? { name: 'exams-exam', params: { id: sheet.exam_id }, query: { tab: 'sheets' } } : { name: 'exams-list' }" class="mb-3 inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-fg">
            <ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" />{{ sheet ? textIn(sheet.exam?.name) : t('education_exams.list.title') }}
        </RouterLink>

        <SkeletonRows v-if="loading && !sheet" :rows="8" />
        <ErrorState v-else-if="loadError" :error="loadError" @retry="load" />
        <template v-else-if="sheet">
            <PageHeader :title="title" :description="t('education_exams.entry.marks_of', { full: marksShown(sheet.paper?.full_marks_centi), pass: sheet.paper?.pass_marks_centi ? marksShown(sheet.paper.pass_marks_centi) : '—' })">
                <template #actions>
                    <AppBadge :tone="sheetTone[sheet.status]" dot>{{ t(`education_exams.sheet_statuses.${sheet.status}`) }}</AppBadge>
                    <AppButton v-if="editable" variant="primary" :icon="Send" :loading="acting" :disabled="saving" @click="handIn">{{ t('education_exams.entry.hand_in') }}</AppButton>
                    <template v-if="canDecide">
                        <AppButton variant="primary" :icon="Check" :loading="acting" @click="approve">{{ t('education_exams.sheets.approve') }}</AppButton>
                        <AppButton variant="danger-soft" :icon="Undo2" :disabled="acting" @click="sendBack">{{ t('education_exams.sheets.send_back') }}</AppButton>
                    </template>
                </template>
            </PageHeader>

            <p v-if="sheet.status === 'returned' && sheet.note" class="mb-4 rounded-lg bg-bad-soft px-4 py-3 text-[13px] text-fg" role="alert">
                <strong class="font-semibold">{{ t('education_exams.entry.returned') }}</strong> {{ sheet.note }}
            </p>
            <p v-if="why" class="mb-4 rounded-lg bg-subtle px-4 py-3 text-[13px] text-muted">{{ why }}</p>
            <p v-if="sheet.status === 'submitted' && setup.can('moderate') && sheet.submitted_by === me" class="mb-4 text-[12.5px] text-warn">{{ t('education_exams.sheets.own') }}</p>

            <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                <label class="relative w-full sm:w-64">
                    <span class="sr-only">{{ t('education_exams.entry.search') }}</span>
                    <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                    <input v-model="query" type="search" class="field-input ps-9" :placeholder="t('education_exams.entry.search')" />
                </label>
                <span class="tabular text-[13px] text-muted">
                    {{ t('education_exams.entry.progress', { done: formatNumber(progress.done), total: formatNumber(progress.total) }) }}
                    · <span class="text-ok">{{ t('education_exams.entry.passed', { count: formatNumber(summary.passed) }) }}</span>
                    · <span class="text-bad">{{ t('education_exams.entry.failed', { count: formatNumber(summary.failed) }) }}</span>
                </span>
                <span v-if="saveState" class="ms-auto inline-flex items-center gap-1.5 text-[12.5px]" :class="saveState.tone" role="status" aria-live="polite">
                    <component :is="saveState.icon" class="size-4" :class="saveState.spin ? 'animate-spin' : ''" aria-hidden="true" />{{ saveState.text }}
                </span>
            </div>
            <p v-if="editable" class="mb-3 text-[12.5px] text-muted">{{ t('education_exams.entry.how') }}</p>

            <section class="card overflow-hidden">
                <EmptyState v-if="!students.length" :icon="UserX" compact :title="t('education_exams.entry.no_students')" :text="t('education_exams.entry.no_students_text')" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full border-collapse text-[13.5px]">
                        <thead class="bg-subtle/70 text-[12px] text-muted">
                            <tr>
                                <th scope="col" class="sticky start-0 z-10 bg-subtle px-4 py-2 text-start font-medium">{{ t('education_exams.entry.student') }}</th>
                                <th v-for="part in parts" :key="part.id" scope="col" class="px-2 py-2 text-center font-medium">
                                    <span class="block">{{ parts.length > 1 ? partName(part) : t('education_exams.paper.marks') }}</span>
                                    <span class="tabular block text-[11px] text-faint">/{{ marksShown(part.full_marks_centi) }}<template v-if="part.pass_marks_centi"> · {{ t('education_exams.entry.pass_short', { pass: marksShown(part.pass_marks_centi) }) }}</template></span>
                                </th>
                                <th v-if="parts.length > 1" scope="col" class="px-3 py-2 text-end font-medium">{{ t('education_exams.entry.total') }}</th>
                                <th scope="col" class="px-3 py-2 text-center font-medium">{{ t('education_exams.entry.grade') }}</th>
                                <th v-if="editable" scope="col" class="px-2 py-2"><span class="sr-only">{{ t('education_exams.entry.absent') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(student, row) in shown" :key="student.id" class="hover:bg-subtle/40">
                                <th scope="row" class="sticky start-0 z-10 bg-surface px-4 py-1.5 text-start font-normal">
                                    <span class="block max-w-[11rem] truncate font-medium text-fg sm:max-w-xs">{{ student.name }}</span>
                                    <span class="block font-mono text-[11.5px] text-muted" dir="ltr">{{ student.code }}</span>
                                </th>
                                <td v-for="(part, col) in parts" :key="part.id" class="px-2 py-1.5 text-center">
                                    <template v-if="editable">
                                        <input :id="`cell-${row}-${col}`" :value="cells[student.id][part.id].text" inputmode="decimal" autocomplete="off" dir="ltr"
                                            class="field-input tabular h-9 w-20 px-2 text-center"
                                            :class="cells[student.id][part.id].kind === 'invalid' ? 'border-bad ring-1 ring-bad' : cells[student.id][part.id].absent ? 'bg-warn-soft' : ''"
                                            :aria-label="`${student.name}, ${partName(part)}`"
                                            :aria-invalid="cells[student.id][part.id].kind === 'invalid' || undefined"
                                            :title="cells[student.id][part.id].reason ? t(`education_exams.entry.invalid_${cells[student.id][part.id].reason}`, { full: marksShown(part.full_marks_centi) }) : ''"
                                            @input="edit(student, part, $event.target.value)" @blur="pending.size && schedule(0)"
                                            @keydown.enter="move($event, row, col, 1)" @keydown.down="move($event, row, col, 1)" @keydown.up="move($event, row, col, -1)" />
                                        <span v-if="cells[student.id][part.id].kind === 'invalid'" class="block text-[11px] text-bad">{{ t(`education_exams.entry.invalid_${cells[student.id][part.id].reason}`, { full: marksShown(part.full_marks_centi) }) }}</span>
                                    </template>
                                    <span v-else-if="cells[student.id][part.id].absent" class="text-[12.5px] text-warn">{{ t('education_exams.entry.absent') }}</span>
                                    <span v-else class="tabular">{{ cells[student.id][part.id].kind === 'marks' ? marksShown(cells[student.id][part.id].centi) : '—' }}</span>
                                </td>
                                <td v-if="parts.length > 1" class="tabular px-3 py-1.5 text-end">{{ grade(student) ? marksShown(grade(student).marks_centi) : '' }}</td>
                                <td class="px-3 py-1.5 text-center">
                                    <span v-if="grade(student)" class="inline-flex items-center gap-1.5">
                                        <span class="font-semibold" :class="grade(student).passed ? 'text-fg' : 'text-bad'">{{ grade(student).letter ?? '—' }}</span>
                                        <span class="tabular text-[12px] text-muted">{{ pointShown(grade(student).point_centi) }}</span>
                                        <span class="sr-only">{{ t(grade(student).passed ? 'education_exams.entry.pass' : 'education_exams.entry.fail') }}</span>
                                        <CircleAlert v-if="!grade(student).passed" class="size-3.5 text-bad" aria-hidden="true" />
                                    </span>
                                    <span v-else class="text-faint">—</span>
                                </td>
                                <td v-if="editable" class="px-2 py-1.5 text-end">
                                    <AppButton size="icon-sm" :variant="parts.every((part) => cells[student.id][part.id].absent) ? 'secondary' : 'ghost'" :icon="UserX"
                                        :aria-pressed="parts.every((part) => cells[student.id][part.id].absent)" :aria-label="t('education_exams.entry.mark_absent', { name: student.name })" :title="t('education_exams.entry.mark_absent', { name: student.name })" @click="markRowAbsent(student)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="!shown.length" class="px-5 py-6 text-[13px] text-muted">{{ t('education_exams.entry.no_match') }}</p>
                </div>
            </section>
        </template>
    </div>
</template>
