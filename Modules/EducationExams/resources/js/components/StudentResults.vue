<script setup>
import { computed, watch } from 'vue';
import { FileText, GraduationCap, ShieldOff } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { marksShown, pointShown } from '../labels';

/**
 * A student's published results, newest first, each with its subjects and
 * report card, and the cumulative grade point. Shown on the transcript page
 * and as the results tab of the student's page in Education. A teacher who
 * may not see this student's whole result is told so (the server answers
 * "not found" for them).
 */
const props = defineProps({
    studentId: { type: String, required: true },
    // On the transcript page the student's name heads the results; in a tab the student's page already shows it.
    named: { type: Boolean, default: false },
});
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();

const transcript = useResource(() => api.transcript(props.studentId), { immediate: false });
watch(() => props.studentId, () => transcript.reload(), { immediate: true });
const data = computed(() => transcript.data.value?.data ?? null);
const subjectLabel = (id) => {
    const subject = setup.data.value?.subjects.find((item) => item.id === id);
    return subject ? `${textIn(subject.name)} (${subject.code})` : '—';
};
const published = (iso) => (iso ? formatDate(iso, { dateStyle: 'medium' }) : '');
</script>

<template>
    <div>
        <SkeletonRows v-if="transcript.loading.value && !data" :rows="6" />
        <div v-else-if="transcript.error.value?.status === 404 && !named" class="card">
            <EmptyState :icon="ShieldOff" compact :title="t('education_exams.transcript.not_yours')" :text="t('education_exams.transcript.not_yours_text')" />
        </div>
        <ErrorState v-else-if="transcript.error.value" :error="transcript.error.value" @retry="transcript.reload()" />
        <template v-else-if="data">
            <section class="card mb-5 flex flex-wrap items-center gap-x-6 gap-y-3 px-5 py-4">
                <span v-if="named" class="min-w-0 flex-1">
                    <span class="block text-[17px] font-semibold text-fg">{{ data.student.name }}</span>
                    <span class="block text-[13px] text-muted"><span class="font-mono" dir="ltr">{{ data.student.code }}</span><template v-if="data.student.name_local"> · {{ data.student.name_local }}</template></span>
                </span>
                <span v-else class="min-w-0 flex-1 text-[13px] text-muted">{{ t('education_exams.transcript.count', { count: formatNumber(data.results.length) }) }}</span>
                <span class="text-end">
                    <span class="block text-[12px] text-muted">{{ t('education_exams.transcript.cgpa') }}</span>
                    <span class="tabular block text-[26px] font-bold leading-tight text-fg">{{ data.cgpa_centi === null ? '—' : pointShown(data.cgpa_centi) }}</span>
                </span>
            </section>
            <p v-if="data.cgpa_centi !== null" class="-mt-3 mb-4 text-[12px] text-faint">{{ t('education_exams.transcript.cgpa_how') }}</p>

            <div v-if="!data.results.length" class="card"><EmptyState :icon="GraduationCap" compact :title="t('education_exams.transcript.empty')" /></div>
            <div v-else class="space-y-4">
                <section v-for="result in data.results" :key="result.id" class="card overflow-hidden">
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-5 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-semibold text-fg">{{ textIn(result.name) }}</span>
                            <span class="block text-[12px] text-muted">{{ setup.sessionName(result.session_id) }} · {{ setup.levelName(result.level_id) }}<template v-if="result.published_at"> · {{ t('education_exams.transcript.published_on', { date: published(result.published_at) }) }}</template></span>
                        </span>
                        <AppBadge v-if="result.source_type === 'combination'" tone="brand">{{ t('education_exams.transcript.final') }}</AppBadge>
                        <span v-if="data.show_rank && result.rank" class="tabular text-[12.5px] text-muted">{{ t('education_exams.transcript.position', { rank: formatNumber(result.rank) }) }}</span>
                        <span class="inline-flex items-baseline gap-1.5">
                            <span class="text-[17px] font-bold" :class="result.passed ? 'text-fg' : 'text-bad'">{{ result.letter ?? '—' }}</span>
                            <span class="tabular text-[13px] text-muted">{{ pointShown(result.gpa_centi) }}</span>
                        </span>
                        <RouterLink :to="{ name: 'exams-report-cards', params: { type: result.source_type, id: result.source_id }, query: { student: data.student.id } }" class="inline-flex items-center gap-1 text-[12.5px] font-medium text-brand-text hover:underline print:hidden">
                            <FileText class="size-3.5" aria-hidden="true" />{{ t('education_exams.cards.one') }}
                        </RouterLink>
                    </header>
                    <div class="overflow-x-auto">
                        <table class="w-full text-[13px]">
                            <thead class="bg-subtle/60 text-[11.5px] text-muted">
                                <tr>
                                    <th scope="col" class="px-5 py-1.5 text-start font-medium">{{ t('education_exams.paper.subject') }}</th>
                                    <th scope="col" class="px-3 py-1.5 text-end font-medium">{{ t('education_exams.paper.marks') }}</th>
                                    <th scope="col" class="px-3 py-1.5 text-center font-medium">{{ t('education_exams.grading.letter') }}</th>
                                    <th scope="col" class="px-5 py-1.5 text-end font-medium">{{ t('education_exams.grading.point') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="subject in result.subjects" :key="subject.subject_id">
                                    <td class="px-5 py-1.5">{{ subjectLabel(subject.subject_id) }}<span v-if="subject.kind === 'optional'" class="text-[11px] text-muted"> · {{ t('education_exams.paper_kinds.optional') }}</span></td>
                                    <td class="tabular px-3 py-1.5 text-end">
                                        <span v-if="subject.absent" class="text-warn">{{ t('education_exams.entry.absent') }}</span>
                                        <template v-else>{{ marksShown(subject.marks_centi) }}<span class="text-faint">/{{ marksShown(subject.full_marks_centi) }}</span></template>
                                    </td>
                                    <td class="px-3 py-1.5 text-center font-semibold" :class="subject.passed ? 'text-fg' : 'text-bad'">{{ subject.letter ?? '—' }}</td>
                                    <td class="tabular px-5 py-1.5 text-end">{{ pointShown(subject.point_centi) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </template>
    </div>
</template>
