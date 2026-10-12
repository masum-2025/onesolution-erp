<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Layers, Megaphone } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { resultSummary } from '../lib';
import { marksShown } from '../labels';
import ResultsTable from '../components/ResultsTable.vue';

/**
 * One combination: its exams and weights, the final results worked out
 * from them (again on each visit while a draft), and publishing them once
 * every exam in it is published.
 */
const route = useRoute();
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();

const record = useResource(() => api.combinations());
const combination = computed(() => record.data.value?.data.find((row) => row.id === route.params.id) ?? null);
const results = useResource(() => api.combinationResults(route.params.id));
const exams = useResource(() => api.exams());
const examName = (id) => textIn(exams.data.value?.data.find((exam) => exam.id === id)?.name) || '—';
const missing = computed(() => record.data.value && !combination.value);

const publishing = ref(false);
async function publish() {
    const summary = resultSummary(results.data.value?.data ?? []);
    const confirmed = await confirmAction({
        title: t('education_exams.results.publish_title'),
        message: t('education_exams.combinations.publish_text'),
        details: [t('education_exams.results.summary', { students: formatNumber(summary.students), passed: formatNumber(summary.passed), failed: formatNumber(summary.failed) })],
        confirmLabel: t('education_exams.results.publish'),
    });
    if (!confirmed) return;
    publishing.value = true;
    try {
        await api.publishCombination(combination.value.id, combination.value.version);
        toast.success(t('education_exams.results.published'));
        await record.reload();
        results.reload();
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') record.reload();
    } finally {
        publishing.value = false;
    }
}
</script>

<template>
    <div>
        <RouterLink :to="{ name: 'exams-combinations' }" class="mb-3 inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-fg">
            <ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" />{{ t('education_exams.combinations.title') }}
        </RouterLink>

        <SkeletonRows v-if="record.loading.value && !record.data.value" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />
        <div v-else-if="missing" class="card"><EmptyState :icon="Layers" :title="t('education_exams.combinations.not_found')" /></div>
        <template v-else-if="combination">
            <PageHeader :title="textIn(combination.name)" :description="`${setup.sessionName(combination.session_id)} · ${setup.levelName(combination.level_id)} · ${setup.schemeName(combination.grading_scheme_id)}`">
                <template #actions>
                    <AppBadge :tone="combination.status === 'published' ? 'ok' : 'neutral'" dot>{{ t(`education_exams.combinations.statuses.${combination.status}`) }}</AppBadge>
                    <AppButton v-if="combination.status !== 'published' && setup.can('publish')" variant="primary" :icon="Megaphone" :loading="publishing" :disabled="!results.data.value?.data.length" @click="publish">{{ t('education_exams.results.publish') }}</AppButton>
                </template>
            </PageHeader>
            <ul class="mb-4 flex flex-wrap gap-2">
                <li v-for="item in combination.exams" :key="item.exam_id" class="rounded-full border border-line bg-surface px-3 py-1 text-[12.5px] text-fg">
                    <RouterLink :to="{ name: 'exams-exam', params: { id: item.exam_id } }" class="hover:underline">{{ examName(item.exam_id) }}</RouterLink>
                    <span class="tabular ms-1 font-semibold">{{ marksShown(item.weight_bp) }}%</span>
                </li>
            </ul>
            <SkeletonRows v-if="results.loading.value && !results.data.value" :rows="6" />
            <ErrorState v-else-if="results.error.value" :error="results.error.value" @retry="results.reload()" />
            <div v-else-if="!results.data.value?.data.length" class="card">
                <EmptyState :icon="Layers" compact :title="t('education_exams.combinations.no_results')" :text="t('education_exams.combinations.no_results_text')" />
            </div>
            <ResultsTable v-else :rows="results.data.value.data" :meta="results.data.value.meta" />
        </template>
    </div>
</template>
