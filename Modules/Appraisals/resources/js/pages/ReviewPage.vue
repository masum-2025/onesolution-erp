<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Check, CheckCheck, Plus, Save, Send, Undo2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { i18n, t } from '@/lib/i18n';
import RatingPicker from '../components/RatingPicker.vue';
import { appraisalsApi } from '../api';
import { bandName, ratingName, scoreText, stageTone, stagesOf, weightsTotal } from '../lib';

/**
 * One appraisal, for whoever may see it. Goals: written (with weights) by
 * the employee and sent, or changed and approved by the reviewer. Then each
 * goal and competency is rated on the cycle's scale with a comment: by the
 * employee (self review), then by the reviewer. The result (score and
 * band) shows once the reviewer is done; the employee acknowledges it.
 * Someone managing appraisals may send it back a step. The server says who
 * may do what ("can") and checks it again.
 */
const route = useRoute();
const org = currentOrganization();
const calls = appraisalsApi(org.id);
const view = useResource(() => calls.review(route.params.id));
const review = computed(() => view.data.value?.data ?? null);
const can = computed(() => review.value?.can ?? {});
const busy = ref(false);
const errors = ref({});

// The goals being written (kept until saved), and one's own side of the ratings.
const goals = ref([]);
const ratings = reactive({});
const summary = ref('');
const side = computed(() => (review.value?.stage === 'self' ? 'self' : 'manager'));
watch(review, (value) => {
    if (!value) return;
    goals.value = value.goals.map((goal) => ({ id: goal.id, title: goal.title, description: goal.description ?? '', weight: goal.weight }));
    for (const item of [...value.goals, ...value.competencies]) {
        ratings[item.rating_id] = { rating: item[`${side.value}_rating`] ?? null, comment: item[`${side.value}_comment`] ?? '' };
    }
    summary.value = value[`${side.value}_summary`] ?? '';
}, { immediate: true });

const total = computed(() => weightsTotal(goals.value));
const stages = computed(() => stagesOf(review.value?.cycle));
const at = computed(() => stages.value.indexOf(review.value?.stage));
const day = (value) => (value ? formatDate(`${value}T00:00:00Z`, { dateStyle: 'long', timeZone: 'UTC' }) : '');
const items = computed(() => (review.value ? [
    ...review.value.goals.map((goal) => ({ ...goal, kind: 'goal', label: goal.title })),
    ...review.value.competencies.map((competency) => ({ ...competency, kind: 'competency', label: competency.name })),
] : []));

async function run(work, done) {
    busy.value = true;
    errors.value = {};
    try {
        view.data.value = await work();
        if (done) toast.success(done);
        return true;
    } catch (error) {
        errors.value = error.errors ?? {};
        if (error.code === 'version_conflict' || error.code === 'wrong_stage') view.reload();
        if (!Object.keys(errors.value).length) toast.error(error.message);
        return false;
    } finally {
        busy.value = false;
    }
}

const saveGoals = (done = t('appraisals.review.saved')) => run(() => calls.saveGoals(review.value.id, {
    base_version: review.value.version,
    goals: goals.value.map((goal) => ({ ...(goal.id ? { id: goal.id } : {}), title: goal.title.trim(), description: goal.description.trim() || null, weight: Number.parseInt(goal.weight, 10) || 0 })),
}), done);
const saveRatings = (done = t('appraisals.review.saved')) => run(() => calls.saveRatings(review.value.id, {
    base_version: review.value.version, summary: summary.value,
    ratings: Object.entries(ratings).map(([id, value]) => ({ id, rating: value.rating, comment: value.comment || null })),
}), done);
const step = (name, note = null) => run(() => calls.stepReview(review.value.id, name, { base_version: review.value.version, ...(note ? { note } : {}) }), t(`appraisals.review.${name}_done`));

async function sendGoals(name) {
    if (await saveGoals(null)) await step(name);
}
async function sendRatings() {
    if (await saveRatings(null)) await step(side.value === 'self' ? 'submit_self' : 'submit_manager');
}
async function acknowledge() {
    const confirmed = await confirmAction({ title: t('appraisals.review.acknowledge_title'), message: t('appraisals.review.acknowledge_text'), confirmLabel: t('appraisals.review.acknowledge'), reason: 'optional', reasonLabel: t('appraisals.review.your_comment') });
    if (confirmed) await step('acknowledge', confirmed.reason || null);
}
async function sendBack() {
    const confirmed = await confirmAction({ title: t('appraisals.review.send_back_title'), message: t('appraisals.review.send_back_text'), confirmLabel: t('appraisals.review.send_back'), reason: 'required', danger: true });
    if (confirmed) await step('send_back', confirmed.reason);
}
const shown = (item, which) => item[`${which}_rating`] !== null && item[`${which}_rating`] !== undefined;
</script>

<template>
    <div class="space-y-5">
        <ErrorState v-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <SkeletonRows v-else-if="!review" :rows="6" />
        <template v-else>
            <PageHeader :title="review.employee.name" :description="`${review.cycle.name}${review.manager_name ? ' · ' + t('appraisals.rows.manager', { name: review.manager_name }) : ''}`">
                <template #actions>
                    <AppBadge :tone="stageTone(review.stage)">{{ t(`appraisals.stages.${review.stage}`) }}</AppBadge>
                    <AppButton v-if="can.send_back" variant="ghost" :icon="Undo2" :loading="busy" @click="sendBack">{{ t('appraisals.review.send_back') }}</AppButton>
                </template>
            </PageHeader>

            <!-- Where it stands. -->
            <ol class="flex flex-wrap gap-2" :aria-label="t('appraisals.review.steps')">
                <li v-for="(stage, index) in stages" :key="stage" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12.5px]"
                    :class="index < at ? 'bg-ok/10 text-ok' : index === at ? 'bg-brand text-white' : 'bg-subtle text-muted'" :aria-current="index === at ? 'step' : undefined">
                    <Check v-if="index < at" class="size-3.5" aria-hidden="true" />{{ t(`appraisals.stages.${stage}`) }}
                </li>
            </ol>
            <p v-if="review.due && !['acknowledge', 'done'].includes(review.stage)" class="text-[13px] text-muted">{{ t('appraisals.review.due', { date: day(review.due) }) }}</p>

            <!-- The result. -->
            <section v-if="review.score !== null" class="card grid gap-4 p-5 sm:grid-cols-3" :aria-label="t('appraisals.review.result')">
                <div>
                    <p class="text-[12.5px] text-muted">{{ t('appraisals.review.score') }}</p>
                    <p class="tabular text-[32px] font-semibold leading-tight">{{ scoreText(review.score) }}</p>
                    <AppBadge tone="brand">{{ bandName(review.bands, review.band, i18n.locale) }}</AppBadge>
                </div>
                <div class="text-[13px]">
                    <p>{{ t('appraisals.review.goals_part', { score: scoreText(review.goals_score), share: formatNumber(review.cycle.goal_share_percent) }) }}</p>
                    <p>{{ t('appraisals.review.competencies_part', { score: scoreText(review.competencies_score), share: formatNumber(100 - review.cycle.goal_share_percent) }) }}</p>
                </div>
                <div v-if="review.manager_summary" class="text-[13px] sm:col-span-1">
                    <p class="text-[12.5px] text-muted">{{ t('appraisals.review.manager_summary') }}</p>
                    <p class="whitespace-pre-line">{{ review.manager_summary }}</p>
                </div>
                <div v-if="can.acknowledge" class="sm:col-span-3">
                    <AppButton variant="primary" :icon="CheckCheck" :loading="busy" @click="acknowledge">{{ t('appraisals.review.acknowledge') }}</AppButton>
                </div>
                <p v-if="review.employee_comment" class="rounded-lg bg-subtle px-3 py-2 text-[13px] sm:col-span-3">{{ t('appraisals.review.employee_comment') }}: “{{ review.employee_comment }}”</p>
            </section>

            <!-- Goals. -->
            <section class="card p-5" :aria-label="t('appraisals.review.goals')">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-[15px] font-semibold">{{ t('appraisals.review.goals') }}</h2>
                    <span v-if="can.edit_goals" class="tabular text-[13px]" :class="total === 100 ? 'text-ok' : 'text-warn'">{{ t('appraisals.review.total', { total: formatNumber(total) }) }}</span>
                </div>
                <template v-if="can.edit_goals">
                    <ol class="space-y-3">
                        <li v-for="(goal, index) in goals" :key="goal.id ?? `new-${index}`" class="grid gap-2 rounded-xl border border-line p-3 sm:grid-cols-[minmax(0,1fr)_110px_auto]">
                            <div class="grid gap-2">
                                <input v-model="goal.title" class="field-input" maxlength="200" :placeholder="t('appraisals.review.goal_title')" :aria-label="t('appraisals.review.goal_title')" />
                                <textarea v-model="goal.description" rows="2" class="field-input" maxlength="2000" :placeholder="t('appraisals.review.goal_description')" :aria-label="t('appraisals.review.goal_description')"></textarea>
                            </div>
                            <label class="grid content-start gap-1 text-[12.5px] text-muted">
                                {{ t('appraisals.review.weight') }}
                                <input v-model="goal.weight" type="number" min="1" max="100" class="field-input tabular text-end" />
                            </label>
                            <AppButton size="icon-sm" variant="ghost" :icon="X" :aria-label="t('appraisals.review.remove_goal')" @click="goals.splice(index, 1)" />
                        </li>
                    </ol>
                    <p v-if="errors.goals" class="mt-2 text-[12.5px] text-bad" role="alert">{{ errors.goals[0] }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <AppButton :icon="Plus" :disabled="goals.length >= 15" @click="goals.push({ id: null, title: '', description: '', weight: Math.max(1, 100 - total) })">{{ t('appraisals.review.add_goal') }}</AppButton>
                        <AppButton :icon="Save" :loading="busy" @click="saveGoals()">{{ t('appraisals.common.save') }}</AppButton>
                        <AppButton v-if="can.submit_goals" variant="primary" :icon="Send" :loading="busy" @click="sendGoals('submit_goals')">{{ t('appraisals.review.submit_goals') }}</AppButton>
                        <AppButton v-if="can.approve_goals" variant="primary" :icon="Check" :loading="busy" @click="sendGoals('approve_goals')">{{ t('appraisals.review.approve_goals') }}</AppButton>
                    </div>
                </template>
                <p v-else-if="!review.goals.length" class="text-[13px] text-muted">{{ review.stage === 'goals' && review.goals_sent ? t('appraisals.review.goals_waiting') : t('appraisals.review.no_goals') }}</p>
            </section>

            <!-- Ratings (goals and competencies), from the self review on. -->
            <section v-if="review.stage !== 'goals'" class="card p-5" :aria-label="t('appraisals.review.ratings')">
                <h2 class="mb-1 text-[15px] font-semibold">{{ t('appraisals.review.ratings') }}</h2>
                <p v-if="can.rate" class="mb-3 text-[12.5px] text-muted">{{ t(`appraisals.review.rate_hint_${side}`) }}</p>
                <ul class="divide-y divide-line">
                    <li v-for="item in items" :key="item.rating_id" class="space-y-2 py-4">
                        <p class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-[14px] font-medium">{{ item.label }}</span>
                            <span class="text-[12px] text-muted">{{ item.kind === 'goal' ? t('appraisals.review.goal_weight', { weight: formatNumber(item.weight) }) : t('appraisals.review.competency') }}</span>
                        </p>
                        <p v-if="item.description" class="text-[12.5px] text-muted">{{ item.description }}</p>
                        <div v-for="which in ['self', 'manager']" v-show="shown(item, which) && !(can.rate && which === side)" :key="which" class="rounded-lg bg-subtle px-3 py-2 text-[13px]">
                            <span class="font-medium">{{ t(`appraisals.review.by_${which}`) }}:</span>
                            {{ item[`${which}_rating`] }} · {{ ratingName(review.scale, item[`${which}_rating`], i18n.locale) }}
                            <span v-if="item[`${which}_comment`]" class="block text-muted">“{{ item[`${which}_comment`] }}”</span>
                        </div>
                        <div v-if="can.rate && ratings[item.rating_id]" class="grid gap-2">
                            <RatingPicker v-model="ratings[item.rating_id].rating" :scale="review.scale" :label="t('appraisals.review.your_rating', { item: item.label })" :disabled="busy" />
                            <input v-model="ratings[item.rating_id].comment" class="field-input" maxlength="2000" :placeholder="t('appraisals.review.comment')" :aria-label="t('appraisals.review.comment')" />
                        </div>
                    </li>
                </ul>
                <div v-if="review.self_summary && !(can.rate && side === 'self')" class="mt-3 rounded-lg bg-subtle px-3 py-2 text-[13px]">
                    <span class="font-medium">{{ t('appraisals.review.self_summary') }}:</span> <span class="whitespace-pre-line">{{ review.self_summary }}</span>
                </div>
                <template v-if="can.rate">
                    <label class="mt-3 grid gap-1 text-[13px] font-medium">
                        {{ t(`appraisals.review.summary_${side}`) }}
                        <textarea v-model="summary" rows="3" class="field-input font-normal" maxlength="5000"></textarea>
                    </label>
                    <p v-if="errors.ratings" class="mt-2 text-[12.5px] text-bad" role="alert">{{ errors.ratings[0] }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <AppButton :icon="Save" :loading="busy" @click="saveRatings()">{{ t('appraisals.common.save') }}</AppButton>
                        <AppButton v-if="can.submit_self || can.submit_manager" variant="primary" :icon="Send" :loading="busy" @click="sendRatings">{{ t(`appraisals.review.submit_${side}`) }}</AppButton>
                    </div>
                </template>
            </section>

            <!-- History. -->
            <section v-if="review.history.length" class="card p-5" :aria-label="t('appraisals.review.history')">
                <h2 class="mb-2 text-[15px] font-semibold">{{ t('appraisals.review.history') }}</h2>
                <ol class="space-y-1 border-s border-line ps-4 text-[12.5px]">
                    <li v-for="(entry, index) in review.history" :key="index">
                        <span class="font-medium">{{ t(`appraisals.history.${entry.to}`) }}</span>
                        <span class="text-muted"> · {{ entry.by ?? '' }} · {{ formatDateTime(entry.at) }}</span>
                        <span v-if="entry.note" class="block text-muted">“{{ entry.note }}”</span>
                    </li>
                </ol>
            </section>
        </template>
    </div>
</template>
