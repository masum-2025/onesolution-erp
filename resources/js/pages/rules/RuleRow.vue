<script setup>
import { computed } from 'vue';
import { CalendarClock, ChevronRight, Hourglass, Lock, Ruler } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import { formatBounds, formatRuleValue } from '@/lib/ruleValues';
import { t } from '@/lib/i18n';

const props = defineProps({
    rule: { type: Object, required: true },
});

defineEmits(['open']);

const source = computed(() => {
    if (props.rule.own.value) return { kind: 'self' };
    if (!props.rule.source) return { kind: 'default' };
    return { kind: 'inherited', name: props.rule.source.name, level: props.rule.source.level };
});

const bounds = computed(() => formatBounds(props.rule, props.rule.constraints));
</script>

<template>
    <button
        type="button"
        class="group flex w-full flex-col gap-2 px-4 py-3.5 text-start transition-colors hover:bg-subtle/70 sm:flex-row sm:items-center sm:gap-4 sm:px-5"
        @click="$emit('open', rule.key)"
    >
        <span class="min-w-0 flex-1">
            <span class="block truncate text-[13.5px] font-medium text-fg group-hover:text-brand-text">{{ rule.label }}</span>
            <span class="mt-0.5 block truncate text-[12.5px] text-muted">{{ rule.description }}</span>
        </span>
        <span class="flex min-w-0 flex-wrap items-center gap-1.5 sm:max-w-[55%] sm:flex-nowrap sm:justify-end">
            <AppBadge v-if="rule.own.pending_approval.length" tone="warn" :icon="Hourglass">{{ t('rules.row.pending') }}</AppBadge>
            <AppBadge v-if="rule.own.scheduled.length" tone="brand" :icon="CalendarClock">{{ t('rules.row.scheduled') }}</AppBadge>
            <AppBadge v-if="bounds" tone="outline" :icon="Ruler" class="hidden md:inline-flex" :title="bounds">{{ bounds }}</AppBadge>
            <AppBadge v-if="rule.locked_by" tone="neutral" :icon="Lock" :title="t('rules.locked_by', { name: rule.locked_by.name ?? '' })">{{ t('rules.row.locked') }}</AppBadge>
            <AppBadge v-else-if="rule.locked_here" tone="brand" :icon="Lock">{{ t('rules.row.locked_here') }}</AppBadge>
            <SourceBadge v-bind="source" class="hidden lg:inline-flex" />
            <span class="tabular max-w-[16rem] truncate rounded-lg bg-subtle px-2.5 py-1 text-[13px] font-semibold text-fg ring-1 ring-line ring-inset">
                {{ formatRuleValue(rule, rule.value) }}
            </span>
            <ChevronRight class="hidden size-4 shrink-0 text-faint transition group-hover:translate-x-0.5 sm:block rtl:rotate-180" aria-hidden="true" />
        </span>
    </button>
</template>
