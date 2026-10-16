<script setup>
import { computed, ref } from 'vue';
import { ArrowRight, Lock, MessageSquare, PenLine, Play, Plus, Sparkles, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feedbackApi } from '../api';
import { useFeedbackSetup } from '../setup';
import { roundTone } from '../lib';
import RoundDialog from '../components/RoundDialog.vue';

/**
 * Feedback rounds of the unit: open a draft (who rates is fixed then), close
 * an open one, change dates or questions while allowed, and open a round's
 * results. Ratings come in through the portal, anonymously.
 */
const api = feedbackApi(currentOrganization().id);
const setup = useFeedbackSetup();
const list = useResource(() => api.rounds());
const rounds = computed(() => list.data.value?.data ?? []);
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });

const editing = ref(null);
const dialogOpen = ref(false);
function edit(round = null) {
    editing.value = round;
    dialogOpen.value = true;
}
function saved() {
    dialogOpen.value = false;
    list.reload();
}

const busy = ref(null);
async function step(round, action) {
    const opening = action === 'open';
    const yes = await confirmAction({
        title: t(`teacher_feedback.rounds.${action}_title`, { name: round.name }),
        message: opening ? t('teacher_feedback.rounds.open_text', { who: t(`teacher_feedback.who.${setup.rules.value.who_rates ?? 'students'}`) }) : t('teacher_feedback.rounds.close_text'),
        confirmLabel: t(`teacher_feedback.rounds.${action}`),
    });
    if (!yes) return;
    busy.value = round.id;
    try {
        await (opening ? api.open(round.id, round.version) : api.close(round.id, round.version));
        toast.success(t(opening ? 'teacher_feedback.rounds.opened' : 'teacher_feedback.rounds.closed'));
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('teacher_feedback.conflict') : error.message);
    } finally {
        busy.value = null;
        list.reload();
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('teacher_feedback.rounds.title')" :description="t('teacher_feedback.rounds.text')">
            <template v-if="setup.can('manage')" #actions>
                <AppButton variant="primary" :icon="Plus" @click="edit()">{{ t('teacher_feedback.rounds.new') }}</AppButton>
            </template>
        </PageHeader>

        <p class="mb-4 flex items-start gap-2 rounded-xl bg-brand-soft px-4 py-3 text-[13px] text-brand-text">
            <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ t('teacher_feedback.rounds.privacy', { count: formatNumber(setup.rules.value.min_responses ?? 5) }) }}
        </p>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <div v-else-if="!rounds.length" class="card">
            <EmptyState :icon="Sparkles" :title="t('teacher_feedback.rounds.empty_title')" :text="t('teacher_feedback.rounds.empty_text')">
                <AppButton v-if="setup.can('manage')" variant="primary" :icon="Plus" @click="edit()">{{ t('teacher_feedback.rounds.new') }}</AppButton>
            </EmptyState>
        </div>
        <ul v-else class="space-y-3">
            <li v-for="round in rounds" :key="round.id" class="card p-4 sm:p-5">
                <div class="flex flex-wrap items-start gap-x-3 gap-y-2">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[15.5px] font-semibold text-fg">{{ round.name }}</h2>
                        <p class="text-[12.5px] text-muted">
                            {{ t('teacher_feedback.rounds.dates', { from: day(round.opens_on), to: day(round.closes_on) }) }}
                            <template v-if="round.who_rates"> · {{ t(`teacher_feedback.who.${round.who_rates}`) }}</template>
                            · {{ t('teacher_feedback.rounds.questions', { count: round.question_texts.length }) }}
                        </p>
                    </div>
                    <AppBadge :tone="roundTone(round.status)" dot>{{ t(`teacher_feedback.statuses.${round.status}`) }}</AppBadge>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-[13px] text-fg-2">
                    <span class="inline-flex items-center gap-1.5"><Users class="size-4 text-muted" aria-hidden="true" />{{ t('teacher_feedback.rounds.responses', { count: round.responses }) }}</span>
                    <span v-if="round.comments_waiting" class="inline-flex items-center gap-1.5 text-warn"><MessageSquare class="size-4" aria-hidden="true" />{{ t('teacher_feedback.rounds.waiting', { count: round.comments_waiting }) }}</span>
                </div>
                <div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-3">
                    <AppButton v-if="round.status !== 'draft'" size="sm" variant="primary" :icon="ArrowRight" :to="{ name: 'tfb-round', params: { id: round.id } }">{{ t('teacher_feedback.rounds.results') }}</AppButton>
                    <template v-if="setup.can('manage')">
                        <AppButton v-if="round.status === 'draft'" size="sm" variant="primary" :icon="Play" :loading="busy === round.id" @click="step(round, 'open')">{{ t('teacher_feedback.rounds.open') }}</AppButton>
                        <AppButton v-if="round.status !== 'closed'" size="sm" :icon="PenLine" @click="edit(round)">{{ t('teacher_feedback.rounds.edit') }}</AppButton>
                        <AppButton v-if="round.status === 'open'" size="sm" variant="danger-soft" :icon="Lock" :loading="busy === round.id" @click="step(round, 'close')">{{ t('teacher_feedback.rounds.close') }}</AppButton>
                    </template>
                </div>
            </li>
        </ul>

        <RoundDialog :open="dialogOpen" :round="editing" @close="dialogOpen = false" @saved="saved" @conflict="dialogOpen = false; list.reload()" />
    </div>
</template>
