<script setup>
import { computed } from 'vue';
import { ArrowDown, ArrowRight, ArrowUp } from 'lucide-vue-next';
import Sparkline from './Sparkline.vue';
import { formatMoney, formatNumber, localeTag } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * A headline number: value, change badge, hint and trend line. The change
 * always shows an arrow and a sign (+3 / −2), never only a color, so it reads
 * the same for colour-blind people. Its tone (good/bad/neutral) comes from the
 * server, which knows whether a rise is good news.
 */
const props = defineProps({
    label: { type: String, required: true },
    data: { type: Object, required: true },
});

const value = computed(() =>
    props.data.format === 'money' ? formatMoney({ amount: props.data.value, currency: props.data.currency }) : formatNumber(props.data.value),
);

const change = computed(() => {
    const raw = props.data.change;
    if (!raw) return null;
    const sign = raw.value > 0 ? '+' : raw.value < 0 ? '−' : '±';
    return {
        text: `${sign}${formatNumber(Math.abs(raw.value))}`,
        icon: raw.direction === 'up' ? ArrowUp : raw.direction === 'down' ? ArrowDown : ArrowRight,
        tone: { good: 'bg-ok-soft text-ok', bad: 'bg-bad-soft text-bad', neutral: 'bg-subtle text-fg-2' }[raw.tone] ?? 'bg-subtle text-fg-2',
        spoken: t(`dashboard.change_${raw.direction}`, { value: formatNumber(Math.abs(raw.value)) }),
    };
});

// Month names for the trend line's points (last point = this month).
const labels = computed(() => {
    const count = props.data.series?.length ?? 0;
    const now = new Date();
    return Array.from({ length: count }, (_, index) => new Date(now.getFullYear(), now.getMonth() - (count - 1 - index), 1).toLocaleDateString(localeTag(), { month: 'short' }));
});
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex items-end gap-2.5">
            <p class="tabular text-[30px] leading-none font-semibold tracking-[-0.03em] text-fg">{{ value }}</p>
            <span v-if="change" class="tabular mb-0.5 inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[12px] font-semibold" :class="change.tone">
                <component :is="change.icon" class="size-3.5" aria-hidden="true" />
                <span aria-hidden="true">{{ change.text }}</span>
                <span class="sr-only">{{ change.spoken }}</span>
            </span>
        </div>
        <div class="mt-auto flex items-end justify-between gap-3 pt-4">
            <p v-if="data.hint" class="min-w-0 text-[12.5px] leading-snug text-muted">{{ data.hint }}</p>
            <Sparkline v-if="data.series?.length > 1" class="shrink-0" :series="data.series" :labels="labels" :label="label" />
        </div>
    </div>
</template>
