<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Check, ClipboardList, FileText, ListPlus, Lock, LockOpen, Pencil, Play, Plus, Trash2, Undo2, UserRoundCog } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber } from '@/lib/format';
import { currentOrganization, session } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { examTone, nextStatuses, parseCenti, sheetCounts, sheetTone } from '../lib';
import { dateRange, day, marksShown, partName, subjectName } from '../labels';
import ExamDialog from '../components/ExamDialog.vue';
import PaperDialog from '../components/PaperDialog.vue';
import SheetBar from '../components/SheetBar.vue';

/**
 * One exam: its papers by class (made by hand or from the curriculum), and
 * once open for marks its mark sheets (a paper for a section): who enters
 * each, how far it is, approving or sending back what was handed in.
 */
const route = useRoute();
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();
const me = computed(() => session.me?.user?.id);

const record = useResource(() => api.exam(route.params.id));
const exam = computed(() => record.data.value?.data ?? null);
const manage = computed(() => setup.can('manage') && exam.value && exam.value.status !== 'published');

const tab = ref(route.query.tab === 'sheets' ? 'sheets' : 'papers');
const tabs = computed(() => [
    { key: 'papers', label: t('education_exams.exam.papers'), count: exam.value?.papers.length || undefined },
    { key: 'sheets', label: t('education_exams.exam.sheets'), count: sheetCounts(exam.value?.counts).total || undefined },
]);

// Papers, by class.
const byLevel = computed(() => {
    const groups = new Map();
    for (const paper of exam.value?.papers ?? []) {
        if (!groups.has(paper.level_id)) groups.set(paper.level_id, []);
        groups.get(paper.level_id).push(paper);
    }
    return [...groups.entries()].map(([levelId, papers]) => ({ levelId, papers }));
});
const partsText = (paper) => (paper.parts.length > 1 ? paper.parts.map((part) => `${partName(part)} ${marksShown(part.full_marks_centi)}`).join(' + ') : '');
const schedule = (paper) => [paper.sits_on && day(paper.sits_on), paper.starts_at?.slice(0, 5), paper.minutes && t('education_exams.paper.minutes_value', { minutes: formatNumber(paper.minutes) }), paper.room && t('education_exams.paper.room_value', { room: paper.room })].filter(Boolean).join(' · ');

const editingExam = ref(false);
const paperDialog = reactive({ open: false, paper: null });
function savedPaper() {
    paperDialog.open = false;
    record.reload();
}
async function removePaper(paper) {
    const confirmed = await confirmAction({
        title: t('education_exams.paper.delete_title'), message: t('education_exams.paper.delete_text', { subject: subjectName(paper.subject) }),
        confirmLabel: t('education_exams.paper.delete'), danger: true,
    });
    if (!confirmed) return;
    try {
        await api.deletePaper(exam.value.id, paper.id);
        toast.success(t('education_exams.paper.deleted'));
        record.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

// Papers from the curriculum.
const curriculum = reactive({ open: false, level_id: '', full: '100', pass: '33', saving: false });
function openCurriculum() {
    Object.assign(curriculum, { open: true, level_id: setup.data.value?.levels[0]?.id ?? '', full: '100', pass: '33', saving: false });
}
const curriculumReady = computed(() => curriculum.level_id && parseCenti(curriculum.full) >= 100 && parseCenti(curriculum.pass) !== null && parseCenti(curriculum.pass) <= parseCenti(curriculum.full));
async function fromCurriculum() {
    curriculum.saving = true;
    try {
        const { data } = await api.papersFromCurriculum(exam.value.id, { level_id: curriculum.level_id, full_marks_centi: parseCenti(curriculum.full), pass_marks_centi: parseCenti(curriculum.pass) });
        toast.success(data.length ? t('education_exams.paper.from_curriculum_made', { count: formatNumber(data.length) }) : t('education_exams.paper.from_curriculum_none'));
        curriculum.open = false;
        record.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        curriculum.saving = false;
    }
}

// Status: open for marks, close, open again.
const moving = ref(false);
const STATUS_ACTION = { marking: { icon: Play }, closed: { icon: Lock } };
async function move(status) {
    const reopening = status === 'marking' && exam.value.status === 'closed';
    const key = reopening ? 'reopen' : status;
    const waiting = sheetCounts(exam.value.counts);
    const confirmed = await confirmAction({
        title: t(`education_exams.status_change.${key}.title`),
        message: t(`education_exams.status_change.${key}.text`),
        details: status === 'closed' && waiting.total > waiting.approved ? [t('education_exams.status_change.closed.unfinished', { count: formatNumber(waiting.total - waiting.approved) })] : null,
        confirmLabel: t(`education_exams.status_change.${key}.confirm`),
    });
    if (!confirmed) return;
    moving.value = true;
    try {
        await api.setStatus(exam.value.id, status, exam.value.version);
        toast.success(t(`education_exams.status_change.${key}.done`));
        await record.reload();
        if (status === 'marking') {
            tab.value = 'sheets';
            sheets.reload();
        }
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') record.reload();
    } finally {
        moving.value = false;
    }
}

// Mark sheets.
const sheets = useResource(() => api.sheets(route.params.id), { immediate: false });
watch(tab, (key) => key === 'sheets' && !sheets.data.value && sheets.reload(), { immediate: true });
const sheetFilter = ref('all');
const sheetRows = computed(() => sheets.data.value?.data ?? []);
const sections = computed(() => sheets.data.value?.sections ?? {});
const paperOf = (id) => exam.value?.papers.find((paper) => paper.id === id) ?? null;
const sheetFilters = computed(() => ['all', 'open', 'returned', 'submitted', 'approved'].map((key) => ({
    key, label: t(key === 'all' ? 'education_exams.sheets.all' : `education_exams.sheet_statuses.${key}`),
    count: key === 'all' ? undefined : sheetRows.value.filter((sheet) => sheet.status === key).length || undefined,
})));
const bySection = computed(() => {
    const groups = new Map();
    for (const sheet of sheetRows.value) {
        if (sheetFilter.value !== 'all' && sheet.status !== sheetFilter.value) continue;
        if (!groups.has(sheet.section_id)) groups.set(sheet.section_id, []);
        groups.get(sheet.section_id).push(sheet);
    }
    const sectionTitle = (id) => {
        const section = sections.value[id];
        return section ? `${setup.levelName(section.level_id)} · ${section.name}` : '—';
    };
    return [...groups.entries()]
        .map(([sectionId, rows]) => ({ sectionId, title: sectionTitle(sectionId), students: sections.value[sectionId]?.students ?? 0, rows: rows.sort((a, b) => subjectName(paperOf(a.paper_id)?.subject).localeCompare(subjectName(paperOf(b.paper_id)?.subject))) }))
        .sort((a, b) => a.title.localeCompare(b.title));
});
function who(sheet) {
    const names = sheets.data.value?.teachers ?? {};
    if (sheet.teacher_id) return names[sheet.teacher_id] || setup.teacherName(sheet.teacher_id) || '—';
    const classTeacher = sections.value[sheet.section_id]?.class_teacher_id;
    const name = classTeacher ? (names[classTeacher] || setup.teacherName(classTeacher)) : '';
    return name ? t('education_exams.sheets.class_teacher_named', { name }) : t('education_exams.sheets.class_teacher');
}

const deciding = ref(null);
async function approve(sheet) {
    deciding.value = sheet.id;
    try {
        await api.approveSheet(sheet.id, sheet.version);
        toast.success(t('education_exams.sheets.approved'));
        sheets.reload();
        record.reload();
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') sheets.reload();
    } finally {
        deciding.value = null;
    }
}
async function sendBack(sheet) {
    const confirmed = await confirmAction({
        title: t('education_exams.sheets.send_back_title'), message: t('education_exams.sheets.send_back_text'),
        reason: 'required', reasonLabel: t('education_exams.sheets.send_back_note'), reasonPlaceholder: t('education_exams.sheets.send_back_placeholder'),
        confirmLabel: t('education_exams.sheets.send_back'),
    });
    if (!confirmed) return;
    deciding.value = sheet.id;
    try {
        await api.sendBackSheet(sheet.id, confirmed.reason, sheet.version);
        toast.success(t('education_exams.sheets.sent_back'));
        sheets.reload();
        record.reload();
    } catch (error) {
        toast.error(error.errors?.note?.[0] ?? error.message);
    } finally {
        deciding.value = null;
    }
}

// Naming who enters a sheet.
const assigning = reactive({ sheet: null, teacher_id: '', saving: false });
function openAssign(sheet) {
    Object.assign(assigning, { sheet, teacher_id: sheet.teacher_id ?? '', saving: false });
}
async function assign() {
    assigning.saving = true;
    try {
        await api.assignSheet(assigning.sheet.id, assigning.teacher_id || null, assigning.sheet.version);
        toast.success(t('education_exams.sheets.assigned'));
        assigning.sheet = null;
        sheets.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        assigning.saving = false;
    }
}
</script>

<template>
    <div>
        <RouterLink :to="{ name: 'exams-list' }" class="mb-3 inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-fg">
            <ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" />{{ t('education_exams.list.title') }}
        </RouterLink>

        <SkeletonRows v-if="record.loading.value && !exam" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />
        <template v-else-if="exam">
            <PageHeader :title="textIn(exam.name)" :description="[setup.sessionName(exam.session_id), t(`education_exams.kinds.${exam.kind}`), dateRange(exam.starts_on, exam.ends_on), setup.schemeName(exam.grading_scheme_id)].filter(Boolean).join(' · ')">
                <template #actions>
                    <AppBadge :tone="examTone[exam.status]" dot>{{ t(`education_exams.exam_statuses.${exam.status}`) }}</AppBadge>
                    <template v-if="manage">
                        <AppButton :icon="Pencil" @click="editingExam = true">{{ t('education_exams.exam.edit') }}</AppButton>
                        <AppButton v-for="next in nextStatuses(exam.status)" :key="next" :variant="next === 'marking' ? 'primary' : 'secondary'"
                            :icon="next === 'marking' && exam.status === 'closed' ? LockOpen : STATUS_ACTION[next].icon" :loading="moving" @click="move(next)">
                            {{ t(`education_exams.status_change.${next === 'marking' && exam.status === 'closed' ? 'reopen' : next}.button`) }}
                        </AppButton>
                    </template>
                </template>
            </PageHeader>

            <p v-if="exam.status === 'draft' && manage" class="mb-4 rounded-lg bg-brand-soft px-4 py-3 text-[13px] text-fg">{{ t('education_exams.exam.draft_hint') }}</p>
            <p v-if="setup.rules.value.moderation_required !== undefined && exam.status === 'marking'" class="mb-4 text-[12.5px] text-muted">
                {{ t(setup.rules.value.moderation_required ? 'education_exams.exam.moderated' : 'education_exams.exam.not_moderated') }}
                <span class="text-faint">· {{ t('education_exams.from_rule') }}</span>
            </p>

            <div class="mb-4 overflow-x-auto"><AppTabs v-model="tab" :tabs="tabs" :label="textIn(exam.name)" /></div>

            <!-- Papers -->
            <section v-if="tab === 'papers'" class="space-y-4">
                <div v-if="manage" class="flex flex-wrap gap-2">
                    <AppButton variant="primary" :icon="Plus" @click="Object.assign(paperDialog, { open: true, paper: null })">{{ t('education_exams.paper.new') }}</AppButton>
                    <AppButton :icon="ListPlus" @click="openCurriculum">{{ t('education_exams.paper.from_curriculum') }}</AppButton>
                </div>
                <div v-if="!exam.papers.length" class="card">
                    <EmptyState :icon="FileText" compact :title="t('education_exams.paper.empty')" :text="manage ? t('education_exams.paper.empty_text') : ''" />
                </div>
                <div v-for="group in byLevel" :key="group.levelId" class="card">
                    <header class="border-b border-line px-5 py-3">
                        <h2 class="text-[13.5px] font-semibold text-fg">{{ setup.levelName(group.levelId) }}</h2>
                    </header>
                    <ul class="divide-y divide-line">
                        <li v-for="paper in group.papers" :key="paper.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                            <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                                <span class="flex flex-wrap items-center gap-2 text-[14px] font-medium text-fg">
                                    {{ subjectName(paper.subject) }}
                                    <AppBadge v-if="paper.kind !== 'compulsory'" tone="outline">{{ t(`education_exams.paper_kinds.${paper.kind}`) }}</AppBadge>
                                </span>
                                <span class="block text-[12.5px] text-muted">
                                    <span class="tabular">{{ t('education_exams.paper.marks_line', { full: marksShown(paper.full_marks_centi), pass: paper.pass_marks_centi ? marksShown(paper.pass_marks_centi) : '—' }) }}</span>
                                    <template v-if="partsText(paper)"> · {{ partsText(paper) }}</template>
                                </span>
                                <span v-if="schedule(paper)" class="block text-[12.5px] text-muted">{{ schedule(paper) }}</span>
                            </span>
                            <span v-if="manage" class="flex gap-1">
                                <AppButton size="icon-sm" variant="ghost" :icon="Pencil" :aria-label="t('education_exams.paper.edit')" @click="Object.assign(paperDialog, { open: true, paper })" />
                                <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('education_exams.paper.delete')" @click="removePaper(paper)" />
                            </span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Mark sheets -->
            <section v-else class="space-y-4">
                <div v-if="exam.status === 'draft'" class="card">
                    <EmptyState :icon="ClipboardList" compact :title="t('education_exams.sheets.not_open')" :text="t('education_exams.sheets.not_open_text')" />
                </div>
                <template v-else>
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="min-w-0 max-w-full overflow-x-auto"><AppTabs v-model="sheetFilter" :tabs="sheetFilters" :label="t('education_exams.exam.sheets')" /></div>
                        <SheetBar :counts="exam.counts" class="ms-auto w-full sm:w-56" />
                    </div>
                    <SkeletonRows v-if="sheets.loading.value && !sheets.data.value" :rows="5" />
                    <ErrorState v-else-if="sheets.error.value" :error="sheets.error.value" @retry="sheets.reload()" />
                    <div v-else-if="!bySection.length" class="card">
                        <EmptyState :icon="ClipboardList" compact :title="t(sheetRows.length ? 'education_exams.sheets.none_here' : 'education_exams.sheets.empty')" :text="sheetRows.length ? '' : t('education_exams.sheets.empty_text')" />
                    </div>
                    <div v-for="group in bySection" :key="group.sectionId" class="card">
                        <header class="flex items-center gap-2 border-b border-line px-5 py-3">
                            <h2 class="flex-1 text-[13.5px] font-semibold text-fg">{{ group.title }}</h2>
                            <span class="tabular text-[12px] text-muted">{{ t('education_exams.sheets.students', { count: formatNumber(group.students) }) }}</span>
                        </header>
                        <ul class="divide-y divide-line">
                            <li v-for="sheet in group.rows" :key="sheet.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
                                <RouterLink :to="{ name: 'exams-sheet', params: { id: sheet.id } }" class="min-w-0 basis-full hover:underline sm:basis-0 sm:flex-1">
                                    <span class="block truncate text-[14px] font-medium text-fg">{{ subjectName(paperOf(sheet.paper_id)?.subject) }}</span>
                                    <span class="block text-[12.5px] text-muted">{{ who(sheet) }}</span>
                                    <span v-if="sheet.status === 'returned' && sheet.note" class="mt-0.5 block text-[12.5px] text-bad">{{ t('education_exams.sheets.returned_note', { note: sheet.note }) }}</span>
                                </RouterLink>
                                <AppBadge :tone="sheetTone[sheet.status]" dot>{{ t(`education_exams.sheet_statuses.${sheet.status}`) }}</AppBadge>
                                <span class="flex flex-wrap gap-1">
                                    <template v-if="sheet.status === 'submitted' && setup.can('moderate')">
                                        <span v-if="sheet.submitted_by === me" class="text-[12px] text-warn">{{ t('education_exams.sheets.own') }}</span>
                                        <template v-else>
                                            <AppButton size="sm" variant="primary" :icon="Check" :loading="deciding === sheet.id" @click="approve(sheet)">{{ t('education_exams.sheets.approve') }}</AppButton>
                                            <AppButton size="sm" variant="danger-soft" :icon="Undo2" :disabled="deciding === sheet.id" @click="sendBack(sheet)">{{ t('education_exams.sheets.send_back') }}</AppButton>
                                        </template>
                                    </template>
                                    <AppButton v-if="manage && setup.data.value?.hrm && ['open', 'returned'].includes(sheet.status)" size="icon-sm" variant="ghost" :icon="UserRoundCog" :aria-label="t('education_exams.sheets.assign')" :title="t('education_exams.sheets.assign')" @click="openAssign(sheet)" />
                                </span>
                            </li>
                        </ul>
                    </div>
                </template>
            </section>

            <ExamDialog :open="editingExam" :exam="exam" @close="editingExam = false" @saved="editingExam = false; record.reload()" />
            <PaperDialog :open="paperDialog.open" :exam-id="exam.id" :paper="paperDialog.paper" @close="paperDialog.open = false" @saved="savedPaper" />

            <AppDialog :open="curriculum.open" :title="t('education_exams.paper.from_curriculum')" :description="t('education_exams.paper.from_curriculum_text')" :icon="ListPlus" @close="curriculum.open = false">
                <form id="from-curriculum" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="curriculumReady && fromCurriculum()">
                    <AppField v-slot="{ id }" :label="t('education_exams.paper.level')" class="sm:col-span-3">
                        <select :id="id" v-model="curriculum.level_id" class="field-input">
                            <option v-for="level in setup.data.value?.levels ?? []" :key="level.id" :value="level.id">{{ setup.levelName(level.id) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education_exams.paper.full')">
                        <input :id="id" v-model="curriculum.full" inputmode="decimal" class="field-input tabular" dir="ltr" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education_exams.paper.pass')">
                        <input :id="id" v-model="curriculum.pass" inputmode="decimal" class="field-input tabular" dir="ltr" />
                    </AppField>
                </form>
                <template #footer>
                    <AppButton variant="ghost" @click="curriculum.open = false">{{ t('education_exams.cancel') }}</AppButton>
                    <AppButton variant="primary" type="submit" form="from-curriculum" :loading="curriculum.saving" :disabled="!curriculumReady">{{ t('education_exams.paper.from_curriculum_make') }}</AppButton>
                </template>
            </AppDialog>

            <AppDialog :open="assigning.sheet !== null" :title="t('education_exams.sheets.assign')" :description="t('education_exams.sheets.assign_text')" :icon="UserRoundCog" size="sm" @close="assigning.sheet = null">
                <AppField v-slot="{ id }" :label="t('education_exams.sheets.teacher')">
                    <select :id="id" v-model="assigning.teacher_id" class="field-input">
                        <option value="">{{ t('education_exams.sheets.class_teacher') }}</option>
                        <option v-for="teacher in setup.data.value?.teachers ?? []" :key="teacher.id" :value="teacher.id">{{ teacher.code ? `${teacher.name} (${teacher.code})` : teacher.name }}</option>
                    </select>
                </AppField>
                <template #footer>
                    <AppButton variant="ghost" @click="assigning.sheet = null">{{ t('education_exams.cancel') }}</AppButton>
                    <AppButton variant="primary" :loading="assigning.saving" @click="assign">{{ t('education_exams.save') }}</AppButton>
                </template>
            </AppDialog>
        </template>
    </div>
</template>
