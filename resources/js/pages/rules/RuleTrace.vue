<script setup>
import { CircleCheck, Lock, Ruler, TriangleAlert } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import { formatBounds, formatRuleValue } from '@/lib/ruleValues';
import { t } from '@/lib/i18n';

/**
 * How the value was decided, level by level (the server's explain() trace).
 */
const props = defineProps({
    rule: { type: Object, required: true },
});

function isSource(step) {
    if (!props.rule.source) return step.level === 'default';
    return step.level === props.rule.source.level && step.scope_id === props.rule.source.id;
}

const hasSomething = (step) => step.level === 'default' || step.set !== null || step.lock !== null || step.constrain !== null || step.note;
</script>

<template>
    <div class="px-5 py-5 sm:px-6">
        <p class="mb-5 text-[13px] leading-relaxed text-muted">{{ t('rules.trace.intro') }}</p>
        <ol class="relative space-y-1">
            <li v-for="(step, index) in rule.trace ?? []" :key="index" class="relative flex gap-3.5 pb-4 last:pb-0">
                <span v-if="index < rule.trace.length - 1" class="absolute start-[11px] top-7 bottom-0 w-px bg-line" aria-hidden="true" />
                <span
                    class="relative z-10 mt-0.5 grid size-6 shrink-0 place-items-center rounded-full ring-4 ring-raised"
                    :class="isSource(step) ? 'bg-brand text-brand-fg' : hasSomething(step) ? 'bg-subtle text-fg-2 ring-offset-0' : 'bg-surface text-faint'"
                    aria-hidden="true"
                >
                    <CircleCheck v-if="isSource(step)" class="size-3.5" />
                    <span v-else class="size-1.5 rounded-full bg-current" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="text-[13.5px] font-medium" :class="hasSomething(step) ? 'text-fg' : 'text-muted'">
                            {{ step.level === 'default' ? t('rules.trace.default') : t(`core.levels.${step.level}`) }}
                        </span>
                        <span v-if="step.name && step.level !== 'platform'" class="truncate text-[13px] text-muted">· {{ step.name }}</span>
                        <AppBadge v-if="isSource(step)" tone="brand">{{ t('rules.trace.used') }}</AppBadge>
                        <AppBadge v-if="step.country_code" tone="outline">{{ step.country_code }}</AppBadge>
                    </div>
                    <div class="mt-1.5 flex flex-wrap gap-1.5 text-[12.5px]">
                        <span v-if="step.level === 'default'" class="rounded-md bg-subtle px-2 py-0.5 text-fg-2">{{ formatRuleValue(rule, step.value) }}</span>
                        <span v-if="step.set !== null && step.set !== undefined" class="rounded-md bg-subtle px-2 py-0.5 text-fg-2">
                            {{ t('rules.trace.set', { value: formatRuleValue(rule, step.set) }) }}
                        </span>
                        <span v-if="step.lock !== null && step.lock !== undefined" class="inline-flex items-center gap-1 rounded-md bg-brand-soft px-2 py-0.5 text-brand-text">
                            <Lock class="size-3" aria-hidden="true" />
                            {{ t('rules.trace.lock', { value: formatRuleValue(rule, step.lock) }) }}
                        </span>
                        <span v-if="step.constrain" class="inline-flex items-center gap-1 rounded-md bg-subtle px-2 py-0.5 text-fg-2">
                            <Ruler class="size-3" aria-hidden="true" />
                            {{ formatBounds(rule, step.constrain) }}
                        </span>
                        <span v-if="step.note" class="inline-flex items-center gap-1 rounded-md bg-warn-soft px-2 py-0.5 text-warn">
                            <TriangleAlert class="size-3" aria-hidden="true" />
                            {{ t(`rules.trace.notes.${step.note}`) }}
                        </span>
                        <span v-if="!hasSomething(step)" class="text-faint">{{ t('rules.trace.nothing') }}</span>
                    </div>
                </div>
            </li>
        </ol>
    </div>
</template>
