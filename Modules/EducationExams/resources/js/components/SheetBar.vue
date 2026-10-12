<script setup>
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { sheetCounts } from '../lib';

/**
 * How far an exam's mark sheets are: approved, waiting for approval, being
 * entered, sent back. The numbers are written too, never colour alone.
 */
const props = defineProps({ counts: { type: Object, default: () => ({}) } });
const c = computed(() => sheetCounts(props.counts));
const parts = computed(() => [
    { key: 'approved', class: 'bg-ok' },
    { key: 'submitted', class: 'bg-warn' },
    { key: 'returned', class: 'bg-bad' },
    { key: 'open', class: 'bg-line-strong' },
].filter((part) => c.value[part.key] > 0));
const summary = computed(() => parts.value.map((part) => `${t(`education_exams.sheet_statuses.${part.key}`)}: ${formatNumber(c.value[part.key])}`).join(', '));
</script>

<template>
    <span class="block" :title="summary">
        <span class="flex h-1.5 overflow-hidden rounded-full bg-subtle" role="img" :aria-label="summary">
            <span v-for="part in parts" :key="part.key" :class="part.class" :style="{ inlineSize: `${(c[part.key] / c.total) * 100}%` }" />
        </span>
        <span class="tabular mt-1 block text-[11.5px] text-muted">
            {{ c.total ? t('education_exams.sheets.approved_of', { done: formatNumber(c.approved), total: formatNumber(c.total) }) : t('education_exams.sheets.none_yet') }}
        </span>
    </span>
</template>
