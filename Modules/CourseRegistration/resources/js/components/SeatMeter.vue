<script setup>
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { seats } from '../lib';

/**
 * Seats of an offered subject: a bar, taken/seats, and a word (seats
 * left, only a few left, full) with how many wait; never colour alone.
 */
const props = defineProps({ offering: { type: Object, required: true } });
const state = computed(() => seats(props.offering));
const bars = { ok: 'bg-ok', warn: 'bg-warn', bad: 'bg-bad' };
const texts = { ok: 'text-ok', warn: 'text-warn', bad: 'text-bad' };
</script>

<template>
    <div class="min-w-0">
        <div class="h-1.5 overflow-hidden rounded-full bg-subtle" role="progressbar" :aria-valuenow="state.taken" aria-valuemin="0" :aria-valuemax="state.capacity">
            <div class="h-full rounded-full" :class="bars[state.tone]" :style="{ inlineSize: `${state.percent}%` }" />
        </div>
        <p class="mt-1 flex flex-wrap items-center justify-between gap-x-2 text-[12px]">
            <span class="tabular text-fg-2">{{ state.taken }}/{{ state.capacity }}</span>
            <span class="font-medium" :class="texts[state.tone]">
                {{ t(`course_registration.seats.${state.state}`, { count: state.left }) }}<template v-if="state.waiting"> · {{ t('course_registration.seats.waiting', { count: state.waiting }) }}</template>
            </span>
        </p>
    </div>
</template>
