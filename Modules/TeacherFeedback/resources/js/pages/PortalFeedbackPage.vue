<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { CheckCircle2, Lock, Sparkles, UserX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { portalFeedbackApi } from '../api';
import { allAnswered } from '../lib';
import StarInput from '../components/StarInput.vue';

/**
 * Rating the teachers in the client's portal: each open round of the
 * student's section, one card per teacher and subject; stars for every
 * question and maybe a comment, sent once. A parent opens it from the
 * child's record. The page says plainly that ratings are kept without a name.
 */
const route = useRoute();
const view = useResource(() => portalFeedbackApi.show(route.params.record || null));
watch(() => route.params.record, () => view.reload());
const data = computed(() => view.data.value?.data ?? null);
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });

// The card being rated: "round|teacher|subject".
const openKey = ref(null);
const answers = reactive({});
const comment = ref('');
const sending = ref(false);
let opId = '';
const keyOf = (round, row) => `${round.id}|${row.teacher_id}|${row.subject_id}`;
function start(round, row) {
    openKey.value = keyOf(round, row);
    Object.keys(answers).forEach((id) => delete answers[id]);
    comment.value = '';
    opId = crypto.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
}
async function send(round, row) {
    sending.value = true;
    try {
        await portalFeedbackApi.rate({
            record: route.params.record || undefined, round_id: round.id, teacher_id: row.teacher_id, subject_id: row.subject_id,
            answers: { ...answers }, comment: comment.value.trim() || null, op_id: opId,
        });
        toast.success(t('teacher_feedback.portal.thanks'));
        openKey.value = null;
        view.reload();
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'already_rated') view.reload();
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('teacher_feedback.portal.title')" :description="data ? t('teacher_feedback.portal.text', { name: data.student.name }) : ''" />
        <p class="mb-4 flex items-start gap-2 rounded-xl bg-brand-soft px-4 py-3 text-[13px] text-brand-text">
            <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ t('teacher_feedback.portal.anonymous') }}
        </p>

        <SkeletonRows v-if="view.loading.value && !data" :rows="4" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="UserX" :title="t('teacher_feedback.portal.no_student')" :text="view.error.value.message" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <div v-else-if="!data?.rounds.length" class="card">
            <EmptyState :icon="Sparkles" :title="t('teacher_feedback.portal.none_title')" :text="t('teacher_feedback.portal.none_text')" />
        </div>
        <div v-else class="space-y-6">
            <section v-for="round in data.rounds" :key="round.id">
                <h2 class="text-[15px] font-semibold text-fg">{{ round.name }}</h2>
                <p class="mb-3 text-[12.5px] text-muted">{{ t('teacher_feedback.portal.until', { date: day(round.closes_on) }) }}</p>
                <ul class="space-y-3">
                    <li v-for="row in round.teachers" :key="keyOf(round, row)" class="card p-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="text-[14.5px] font-semibold text-fg">{{ row.teacher ?? '—' }}</p>
                                <p class="text-[12.5px] text-muted">{{ textIn(row.subject.name) }}</p>
                            </div>
                            <span v-if="row.done" class="inline-flex items-center gap-1.5 text-[13px] font-medium text-ok"><CheckCircle2 class="size-4" aria-hidden="true" />{{ t('teacher_feedback.portal.done') }}</span>
                            <AppButton v-else-if="openKey !== keyOf(round, row)" size="sm" variant="primary" @click="start(round, row)">{{ t('teacher_feedback.portal.rate') }}</AppButton>
                        </div>
                        <form v-if="!row.done && openKey === keyOf(round, row)" class="mt-4 space-y-3 border-t border-line pt-4" @submit.prevent="allAnswered(round.questions, answers) && send(round, row)">
                            <StarInput v-for="question in round.questions" :key="question.id" v-model="answers[question.id]" :name="`q-${keyOf(round, row)}-${question.id}`" :label="textIn(question.text)" />
                            <AppField v-if="data.allow_comments" v-slot="{ id, describedby }" :label="t('teacher_feedback.portal.comment')" :hint="t('teacher_feedback.portal.comment_hint')" optional>
                                <textarea :id="id" v-model="comment" class="field-input min-h-20" maxlength="1000" :aria-describedby="describedby" />
                            </AppField>
                            <div class="flex flex-wrap justify-end gap-2">
                                <AppButton variant="ghost" @click="openKey = null">{{ t('teacher_feedback.cancel') }}</AppButton>
                                <AppButton type="submit" variant="primary" :loading="sending" :disabled="!allAnswered(round.questions, answers)">{{ t('teacher_feedback.portal.send') }}</AppButton>
                            </div>
                        </form>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
