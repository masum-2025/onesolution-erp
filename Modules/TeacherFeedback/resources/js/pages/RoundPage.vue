<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, SearchX, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDate } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feedbackApi } from '../api';
import { useFeedbackSetup } from '../setup';
import { roundTone } from '../lib';
import ResultRows from '../components/ResultRows.vue';

/** A round's results for who may read them, comments decided by who manages feedback. */
const api = feedbackApi(currentOrganization().id);
const setup = useFeedbackSetup();
const route = useRoute();
const view = useResource(() => api.round(route.params.id));
const data = computed(() => view.data.value?.data ?? null);
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });

async function decide(comment, decision) {
    try {
        await api.decide(data.value.id, comment.id, decision);
        toast.success(t(decision === 'approve' ? 'teacher_feedback.results.approved' : 'teacher_feedback.results.hidden'));
        view.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'tfb-rounds' }" :icon="ArrowLeft" class="mb-3">{{ t('teacher_feedback.rounds.back') }}</AppButton>
        <SkeletonRows v-if="view.loading.value && !data" :rows="5" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('teacher_feedback.results.not_found')" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <template v-else-if="data">
            <PageHeader :title="data.name" :description="t('teacher_feedback.rounds.dates', { from: day(data.opens_on), to: day(data.closes_on) })">
                <template #eyebrow>
                    <AppBadge class="mb-2" :tone="roundTone(data.status)" dot>{{ t(`teacher_feedback.statuses.${data.status}`) }}</AppBadge>
                </template>
            </PageHeader>
            <div v-if="!data.rows.length" class="card">
                <EmptyState :icon="Users" :title="t('teacher_feedback.results.empty_title')" :text="t('teacher_feedback.results.empty_text')" />
            </div>
            <ResultRows v-else :rows="data.rows" :questions="data.questions" :names="view.data.value.meta" :min-responses="setup.rules.value.min_responses ?? 5" :deciding="setup.can('manage')" @decide="decide" />
        </template>
    </div>
</template>
