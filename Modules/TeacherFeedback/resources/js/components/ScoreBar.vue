<script setup>
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';
import { scoreTone, starsText } from '../lib';

/** An average out of five: the number, always, and a bar beside it. */
const props = defineProps({ centi: { type: Number, default: null }, label: { type: String, default: '' }, large: Boolean });
const width = computed(() => `${Math.max(0, Math.min(100, ((props.centi ?? 0) / 500) * 100))}%`);
const bar = computed(() => ({ ok: 'bg-ok', warn: 'bg-warn', bad: 'bg-bad', neutral: 'bg-faint' })[scoreTone(props.centi)]);
</script>

<template>
    <div class="flex items-center gap-3">
        <span v-if="label" class="min-w-0 flex-1 truncate text-[13px] text-fg-2">{{ label }}</span>
        <span class="h-2 rounded-full bg-subtle" :class="large ? 'w-40' : 'w-24'" aria-hidden="true">
            <span class="block h-2 rounded-full" :class="bar" :style="{ width }" />
        </span>
        <span class="tabular w-8 text-end font-semibold text-fg" :class="large ? 'text-[18px]' : 'text-[13px]'">{{ starsText(centi, (value) => formatNumber(Number(value), { minimumFractionDigits: 1 })) }}</span>
    </div>
</template>
