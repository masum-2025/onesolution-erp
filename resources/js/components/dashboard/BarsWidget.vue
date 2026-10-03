<script setup>
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';

/**
 * Horizontal bars for one measure (largest first): one hue, rounded ends, the
 * value written at the end of each bar, so nothing depends on reading a scale.
 */
const props = defineProps({
    data: { type: Object, required: true },
});

const max = computed(() => Math.max(1, ...props.data.items.map((item) => item.value)));
</script>

<template>
    <ul class="space-y-3">
        <li v-for="item in data.items" :key="item.label" class="grid grid-cols-[minmax(0,9rem)_minmax(0,1fr)_auto] items-center gap-3">
            <span class="truncate text-[13px] text-fg-2" :title="item.label">{{ item.label }}</span>
            <span class="h-2.5 overflow-hidden rounded-full bg-subtle" aria-hidden="true">
                <span class="block h-full rounded-full bg-brand transition-[width] duration-500 ease-[var(--ease-soft)]" :style="{ width: `${Math.max(3, (item.value / max) * 100)}%` }" />
            </span>
            <span class="tabular text-end text-[13px] font-semibold text-fg">{{ formatNumber(item.value) }}</span>
        </li>
    </ul>
</template>
