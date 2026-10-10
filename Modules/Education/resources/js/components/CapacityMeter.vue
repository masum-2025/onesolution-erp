<script setup>
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { fullness } from '../lib';

/**
 * How full a section is: a bar, the numbers and a word (open, filling,
 * nearly full, full), so the meaning never rests on colour alone.
 */
const props = defineProps({
    taken: { type: Number, required: true },
    capacity: { type: Number, required: true },
    compact: Boolean,
});

const state = computed(() => fullness(props.taken, props.capacity));
const bars = { ok: 'bg-ok', brand: 'bg-brand', warn: 'bg-warn', bad: 'bg-bad' };
const texts = { ok: 'text-ok', brand: 'text-brand-text', warn: 'text-warn', bad: 'text-bad' };
</script>

<template>
    <div class="min-w-0">
        <div class="h-1.5 overflow-hidden rounded-full bg-subtle" role="progressbar" :aria-valuenow="state.taken" aria-valuemin="0" :aria-valuemax="state.capacity"
            :aria-label="t('education.capacity.label', { taken: state.taken, capacity: state.capacity })">
            <div class="h-full rounded-full transition-[inline-size] duration-500" :class="bars[state.tone]" :style="{ inlineSize: `${state.percent}%` }" />
        </div>
        <p class="mt-1 flex items-center justify-between gap-2 text-[12px]">
            <span class="tabular text-fg-2">{{ formatNumber(state.taken) }}/{{ formatNumber(state.capacity) }}</span>
            <span v-if="!compact" class="font-medium" :class="texts[state.tone]">{{ t(`education.capacity.${state.state}`, { count: state.left }) }}</span>
        </p>
    </div>
</template>
