<script setup>
import { computed } from 'vue';
import { Lock, Sparkles, UserX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { feedbackApi } from '../api';
import ResultRows from '../components/ResultRows.vue';

/** A teacher's own feedback, round by round: averages and approved comments, never who gave them. */
const api = feedbackApi(currentOrganization().id);
const view = useResource(() => api.mine());
const rounds = computed(() => view.data.value?.data ?? []);
const meta = computed(() => view.data.value?.meta ?? {});
</script>

<template>
    <div>
        <PageHeader :title="t('teacher_feedback.mine.title')" :description="t('teacher_feedback.mine.text')" />
        <p class="mb-4 flex items-start gap-2 rounded-xl bg-brand-soft px-4 py-3 text-[13px] text-brand-text">
            <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ t('teacher_feedback.mine.privacy', { count: formatNumber(meta.min_responses ?? 5) }) }}
        </p>

        <SkeletonRows v-if="view.loading.value && !view.data.value" :rows="4" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="Lock" :title="t('teacher_feedback.mine.off')" :text="view.error.value.message" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <section v-else-if="meta.teacher === false" class="card"><EmptyState :icon="UserX" :title="t('teacher_feedback.mine.not_teacher')" :text="t('teacher_feedback.mine.not_teacher_text')" /></section>
        <div v-else-if="!rounds.length" class="card"><EmptyState :icon="Sparkles" :title="t('teacher_feedback.mine.empty_title')" :text="t('teacher_feedback.mine.empty_text')" /></div>
        <div v-else class="space-y-6">
            <section v-for="round in rounds" :key="round.id">
                <h2 class="mb-2 text-[14.5px] font-semibold text-fg">{{ round.name }}</h2>
                <ResultRows :rows="round.rows" :questions="round.questions" :names="meta" :min-responses="meta.min_responses ?? 5" :show-teacher="false" />
            </section>
        </div>
    </div>
</template>
