<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { FileText, GraduationCap, Lock, SearchX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { portalResultsApi } from '../api';
import { marksShown, pointShown } from '../labels';

/**
 * A family's results in the portal: every published result of their child,
 * newest first, with each subject and the cumulative GPA, and its report
 * card to print. Held for fees owed (rule): only that the office can help.
 */
const route = useRoute();
const api = computed(() => portalResultsApi(route.params.record));
const view = useResource(() => api.value.show());
const data = computed(() => view.data.value?.data ?? null);
const subject = (id) => data.value?.names.subjects[id] ?? null;
const published = (iso) => (iso ? formatDate(iso, { dateStyle: 'medium' }) : '');
</script>

<template>
    <div>
        <SkeletonRows v-if="view.loading.value && !data" :rows="6" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('education_exams.portal.not_found')" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <template v-else-if="data">
            <PageHeader :title="t('education_exams.portal.title_of', { name: data.student.name })" :description="[data.student.code, data.institution].filter(Boolean).join(' · ')" />

            <section v-if="data.held" class="card flex items-start gap-3 px-5 py-4">
                <Lock class="mt-0.5 size-5 shrink-0 text-warn" aria-hidden="true" />
                <div>
                    <p class="text-[14px] font-semibold text-fg">{{ t('education_exams.portal.held') }}</p>
                    <p class="text-[13px] text-muted">{{ t('education_exams.portal.held_text') }}</p>
                </div>
            </section>
            <template v-else>
                <section v-if="data.results.length" class="card mb-5 flex items-center justify-between gap-4 px-5 py-4">
                    <span class="text-[13px] text-muted">{{ t('education_exams.transcript.cgpa') }}</span>
                    <span class="tabular text-[26px] font-bold leading-tight text-fg">{{ data.cgpa_centi === null ? '—' : pointShown(data.cgpa_centi) }}</span>
                </section>
                <div v-if="!data.results.length" class="card"><EmptyState :icon="GraduationCap" :title="t('education_exams.portal.empty')" :text="t('education_exams.portal.empty_text')" /></div>
                <div v-else class="space-y-4">
                    <section v-for="result in data.results" :key="result.id" class="card overflow-hidden">
                        <header class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line px-5 py-3">
                            <span class="min-w-0 flex-1">
                                <span class="block text-[14px] font-semibold text-fg">{{ textIn(result.name) }}</span>
                                <span class="block text-[12px] text-muted">{{ textIn(data.names.sessions[result.session_id]) }} · {{ textIn(data.names.levels[result.level_id]) }}<template v-if="result.published_at"> · {{ t('education_exams.transcript.published_on', { date: published(result.published_at) }) }}</template></span>
                            </span>
                            <AppBadge v-if="result.source_type === 'combination'" tone="brand">{{ t('education_exams.transcript.final') }}</AppBadge>
                            <span v-if="data.show_rank && result.rank" class="tabular text-[12.5px] text-muted">{{ t('education_exams.transcript.position', { rank: formatNumber(result.rank) }) }}</span>
                            <span class="inline-flex items-baseline gap-1.5">
                                <span class="text-[17px] font-bold" :class="result.passed ? 'text-fg' : 'text-bad'">{{ result.letter ?? '—' }}</span>
                                <span class="tabular text-[13px] text-muted">{{ pointShown(result.gpa_centi) }}</span>
                            </span>
                            <AppButton size="sm" :icon="FileText" :to="{ name: 'exams-portal-card', params: { record: route.params.record, id: result.id } }">{{ t('education_exams.portal.report_card') }}</AppButton>
                        </header>
                        <ul class="divide-y divide-line">
                            <li v-for="row in result.subjects" :key="row.subject_id" class="flex items-center gap-3 px-5 py-2 text-[13px]">
                                <span class="min-w-0 flex-1 truncate">{{ textIn(subject(row.subject_id)?.name) }} <span class="text-[11.5px] text-muted">({{ subject(row.subject_id)?.code }})</span></span>
                                <span v-if="row.absent" class="text-[12px] text-warn">{{ t('education_exams.entry.absent') }}</span>
                                <span v-else class="tabular text-muted">{{ marksShown(row.marks_centi) }}/{{ marksShown(row.full_marks_centi) }}</span>
                                <span class="w-8 text-end font-semibold" :class="row.passed ? 'text-fg' : 'text-bad'">{{ row.letter ?? '—' }}</span>
                            </li>
                        </ul>
                    </section>
                </div>
            </template>
        </template>
    </div>
</template>
