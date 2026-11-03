<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, SearchX, Users } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
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
    <PageFrame :title="data ? data.name : ''" :description="data ? t('teacher_feedback.rounds.dates', { from: day(data.opens_on), to: day(data.closes_on) }) : ''" :resource="view" :not-found="{ icon: SearchX, title: t('teacher_feedback.results.not_found') }" :skeleton-rows="5" width="full">
        <template #before>
            <AppButton variant="ghost" size="sm" :to="{ name: 'tfb-rounds' }" :icon="ArrowLeft" class="mb-3">{{ t('teacher_feedback.rounds.back') }}</AppButton>
        </template>
        <template #eyebrow>
            <AppBadge class="mb-2" :tone="roundTone(data.status)" dot>{{ t(`teacher_feedback.statuses.${data.status}`) }}</AppBadge>
        </template>
        <template v-if="data">
            <div v-if="!data.rows.length" class="card">
                <EmptyState :icon="Users" :title="t('teacher_feedback.results.empty_title')" :text="t('teacher_feedback.results.empty_text')" />
            </div>
            <ResultRows v-else :rows="data.rows" :questions="data.questions" :names="view.data.value.meta" :min-responses="setup.rules.value.min_responses ?? 5" :deciding="setup.can('manage')" @decide="decide" />
        </template>
    </PageFrame>
</template>
