<script setup>
import { computed, ref } from 'vue';
import { ChevronDown, CircleAlert, FileText, ScrollText, Trophy } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import { formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { useExamsSetup } from '../setup';
import { normalizeNumberText, resultSummary } from '../lib';
import { marksShown, pointShown } from '../labels';

/**
 * Results by merit position: GPA, letter, failed subjects, and each
 * subject when a row opens. Positions only when the rule shows them; rows
 * the reader sees in part (some subjects, by rule) say so and show no
 * total, GPA or position. A row opens the student's transcript.
 */
const props = defineProps({
    rows: { type: Array, default: () => [] },
    meta: { type: Object, default: () => ({}) },
    source: { type: Object, default: null }, // { type: 'exam'|'combination', id }: each row links its report card
});
const setup = useExamsSetup();

const levelId = ref('');
const sectionId = ref('');
const query = ref('');
const open = ref(new Set());
const levels = computed(() => [...new Set(props.rows.map((row) => row.level_id))]);
const sections = computed(() => [...new Set(props.rows.filter((row) => !levelId.value || row.level_id === levelId.value).map((row) => row.section_id).filter(Boolean))]
    .map((id) => ({ id, name: props.meta.sections?.[id] ?? '—' })).sort((a, b) => a.name.localeCompare(b.name)));
const shown = computed(() => {
    const term = normalizeNumberText(query.value).toLowerCase();
    return props.rows.filter((row) => (!levelId.value || row.level_id === levelId.value)
        && (!sectionId.value || row.section_id === sectionId.value)
        && (!term || [row.student?.name, row.student?.name_local, row.student?.code].some((value) => String(value ?? '').toLowerCase().includes(term))));
});
const summary = computed(() => resultSummary(shown.value));
const showRank = computed(() => props.meta.show_rank === true);
const subjectLabel = (id) => {
    const subject = setup.data.value?.subjects.find((item) => item.id === id);
    return subject ? `${textIn(subject.name)} (${subject.code})` : '—';
};
function toggle(id) {
    const next = new Set(open.value);
    next.has(id) ? next.delete(id) : next.add(id);
    open.value = next;
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <label v-if="levels.length > 1" class="w-full sm:w-52">
                <span class="sr-only">{{ t('education_exams.results.level') }}</span>
                <select v-model="levelId" class="field-input" @change="sectionId = ''">
                    <option value="">{{ t('education_exams.results.all_levels') }}</option>
                    <option v-for="id in levels" :key="id" :value="id">{{ setup.levelName(id) }}</option>
                </select>
            </label>
            <label v-if="sections.length > 1" class="w-full sm:w-40">
                <span class="sr-only">{{ t('education_exams.results.section') }}</span>
                <select v-model="sectionId" class="field-input">
                    <option value="">{{ t('education_exams.results.all_sections') }}</option>
                    <option v-for="section in sections" :key="section.id" :value="section.id">{{ section.name }}</option>
                </select>
            </label>
            <label class="w-full sm:w-56">
                <span class="sr-only">{{ t('education_exams.entry.search') }}</span>
                <input v-model="query" type="search" class="field-input" :placeholder="t('education_exams.entry.search')" />
            </label>
            <span class="tabular text-[13px] text-muted sm:ms-auto">
                {{ t('education_exams.results.summary', { students: formatNumber(summary.students), passed: formatNumber(summary.passed), failed: formatNumber(summary.failed) }) }}
                <template v-if="summary.rate_bp !== null"> · {{ t('education_exams.results.rate', { rate: marksShown(summary.rate_bp) }) }}</template>
            </span>
        </div>
        <p v-if="meta.partial" class="rounded-lg bg-subtle px-4 py-2.5 text-[12.5px] text-muted">
            {{ t('education_exams.results.partial') }} <span class="text-faint">· {{ t('education_exams.from_rule') }}</span>
        </p>
        <p v-if="!showRank && rows.length" class="text-[12px] text-faint">{{ t('education_exams.results.no_rank') }}</p>

        <section class="card overflow-hidden">
            <p v-if="!shown.length" class="px-5 py-6 text-[13px] text-muted">{{ t(rows.length ? 'education_exams.entry.no_match' : 'education_exams.results.none') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="row in shown" :key="row.id">
                    <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5 text-start transition hover:bg-subtle/50" :aria-expanded="open.has(row.id)" @click="toggle(row.id)">
                        <span v-if="showRank" class="tabular inline-flex w-10 shrink-0 items-center gap-1 text-[13px] font-semibold" :class="row.rank && row.rank <= 3 ? 'text-brand-text' : 'text-muted'">
                            <Trophy v-if="row.rank && row.rank <= 3" class="size-3.5" aria-hidden="true" />{{ row.rank ? formatNumber(row.rank) : '—' }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium text-fg">{{ row.student?.name ?? '—' }}</span>
                            <span class="block text-[12px] text-muted"><span class="font-mono" dir="ltr">{{ row.student?.code }}</span> · {{ setup.levelName(row.level_id) }} {{ meta.sections?.[row.section_id] ?? '' }}<template v-if="showRank && row.section_rank"> · {{ t('education_exams.results.section_rank', { rank: formatNumber(row.section_rank) }) }}</template><span v-if="!row.partial && row.failed_subjects" class="text-bad sm:hidden"> · {{ t('education_exams.results.failed_subjects', { count: formatNumber(row.failed_subjects) }) }}</span></span>
                        </span>
                        <template v-if="!row.partial">
                            <span class="tabular hidden w-24 text-end text-[13px] text-muted sm:block">{{ marksShown(row.marks_centi) }}/{{ marksShown(row.full_marks_centi) }}</span>
                            <span v-if="row.failed_subjects" class="hidden sm:block"><AppBadge tone="bad" :icon="CircleAlert">{{ t('education_exams.results.failed_subjects', { count: formatNumber(row.failed_subjects) }) }}</AppBadge></span>
                            <span class="inline-flex w-24 items-baseline justify-end gap-1.5">
                                <span class="text-[15px] font-semibold" :class="row.passed ? 'text-fg' : 'text-bad'">{{ row.letter ?? '—' }}</span>
                                <span class="tabular text-[13px] text-muted">{{ pointShown(row.gpa_centi) }}</span>
                                <span class="sr-only">{{ t(row.passed ? 'education_exams.entry.pass' : 'education_exams.entry.fail') }}</span>
                            </span>
                        </template>
                        <AppBadge v-else tone="outline">{{ t('education_exams.results.your_subjects') }}</AppBadge>
                        <ChevronDown class="size-4 shrink-0 text-faint transition" :class="open.has(row.id) ? 'rotate-180' : ''" aria-hidden="true" />
                    </button>
                    <div v-if="open.has(row.id)" class="bg-subtle/40 px-4 pb-3 pt-1">
                        <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <li v-for="subject in row.subjects" :key="subject.subject_id" class="flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-[13px]">
                                <span class="min-w-0 flex-1 truncate" :title="subjectLabel(subject.subject_id)">
                                    {{ subjectLabel(subject.subject_id) }}
                                    <span v-if="subject.kind === 'optional'" class="text-[11px] text-muted">· {{ t('education_exams.paper_kinds.optional') }}</span>
                                </span>
                                <span v-if="subject.absent" class="text-[12px] text-warn">{{ t('education_exams.entry.absent') }}</span>
                                <span v-else class="tabular text-muted">{{ marksShown(subject.marks_centi) }}</span>
                                <span class="w-8 text-end font-semibold" :class="subject.passed ? 'text-fg' : 'text-bad'">{{ subject.letter ?? '—' }}</span>
                                <span class="tabular w-10 text-end text-[12px] text-muted">{{ pointShown(subject.point_centi) }}</span>
                            </li>
                        </ul>
                        <RouterLink v-if="!row.partial" :to="{ name: 'exams-transcript', params: { id: row.student_id } }" class="mt-2 inline-flex items-center gap-1.5 text-[12.5px] font-medium text-brand-text hover:underline">
                            <ScrollText class="size-3.5" aria-hidden="true" />{{ t('education_exams.results.open_transcript') }}
                        </RouterLink>
                        <RouterLink v-if="!row.partial && source" :to="{ name: 'exams-report-cards', params: { type: source.type, id: source.id }, query: { student: row.student_id } }" class="mt-2 ms-4 inline-flex items-center gap-1.5 text-[12.5px] font-medium text-brand-text hover:underline">
                            <FileText class="size-3.5" aria-hidden="true" />{{ t('education_exams.cards.one') }}
                        </RouterLink>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
