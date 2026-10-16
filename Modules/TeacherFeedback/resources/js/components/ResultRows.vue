<script setup>
import { EyeOff, Check } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import { formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import ScoreBar from './ScoreBar.vue';

/**
 * A round's results, one card per teacher, subject and section: how many
 * rated, the overall average and each question's, and the comments. Below
 * the response threshold only the count shows. Who manages approves or
 * hides comments here.
 */
defineProps({
    rows: { type: Array, required: true },
    questions: { type: Array, required: true },
    names: { type: Object, default: () => ({}) },
    minResponses: { type: Number, default: 5 },
    deciding: Boolean,
    showTeacher: { type: Boolean, default: true },
});
const emit = defineEmits(['decide']);
</script>

<template>
    <ul class="space-y-3">
        <li v-for="row in rows" :key="`${row.teacher_id}|${row.subject_id}|${row.section_id}`" class="card p-4 sm:p-5">
            <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
                <div class="min-w-0 flex-1">
                    <h3 v-if="showTeacher" class="text-[15px] font-semibold text-fg">{{ names.teachers?.[row.teacher_id] ?? '—' }}</h3>
                    <p class="text-[12.5px] text-muted">
                        {{ textIn(names.subjects?.[row.subject_id]?.name) || '—' }}
                        <template v-if="names.sections?.[row.section_id]"> · {{ textIn(names.sections[row.section_id].level) }} {{ names.sections[row.section_id].name }}</template>
                        · {{ t('teacher_feedback.results.responses', { count: row.responses }) }}
                    </p>
                </div>
                <ScoreBar v-if="row.shown" :centi="row.average_centi" large />
            </div>

            <p v-if="!row.shown" class="mt-3 rounded-lg bg-subtle px-3 py-2 text-[12.5px] text-muted">
                {{ t('teacher_feedback.results.not_enough', { count: formatNumber(row.responses), min: formatNumber(minResponses) }) }}
            </p>
            <template v-else>
                <div class="mt-3 space-y-1.5">
                    <ScoreBar v-for="question in questions" :key="question.id" :centi="row.questions[question.id]" :label="textIn(question.text)" />
                </div>
                <div v-if="row.comments.length" class="mt-4 space-y-2 border-t border-line pt-3">
                    <h4 class="text-[12.5px] font-medium text-muted">{{ t('teacher_feedback.results.comments') }}</h4>
                    <blockquote v-for="comment in row.comments" :key="comment.id" class="flex flex-wrap items-start gap-2 rounded-lg bg-subtle px-3 py-2">
                        <p class="min-w-0 flex-1 whitespace-pre-line break-words text-[13px] text-fg-2">{{ comment.text }}</p>
                        <AppBadge v-if="comment.status !== 'approved'" :tone="comment.status === 'hidden' ? 'bad' : 'warn'">{{ t(`teacher_feedback.comment_statuses.${comment.status}`) }}</AppBadge>
                        <span v-if="deciding && comment.status === 'pending'" class="flex gap-1.5">
                            <AppButton size="sm" variant="primary" :icon="Check" @click="emit('decide', comment, 'approve')">{{ t('teacher_feedback.results.approve') }}</AppButton>
                            <AppButton size="sm" variant="danger-soft" :icon="EyeOff" @click="emit('decide', comment, 'hide')">{{ t('teacher_feedback.results.hide') }}</AppButton>
                        </span>
                    </blockquote>
                </div>
            </template>
        </li>
    </ul>
</template>
