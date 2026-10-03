<script setup>
import { computed, ref } from 'vue';
import { formatNumber } from '@/lib/format';

/**
 * A small trend line (one series, oldest first) under a number. 2px line in
 * the highlight color with a soft area below; hover or focus shows each point.
 * A visually hidden table carries the same numbers for screen readers.
 */
const props = defineProps({
    series: { type: Array, required: true },
    labels: { type: Array, default: () => [] },
    label: { type: String, required: true },
});

const W = 120;
const H = 36;
const PAD = 3;
const active = ref(null);

const points = computed(() => {
    const values = props.series;
    const max = Math.max(...values);
    const min = Math.min(...values);
    const span = max - min || 1;
    const step = values.length > 1 ? (W - PAD * 2) / (values.length - 1) : 0;
    return values.map((value, index) => ({
        x: PAD + index * step,
        // A flat series sits at mid-height instead of on the floor.
        y: max === min ? H / 2 : PAD + (1 - (value - min) / span) * (H - PAD * 2),
        value,
    }));
});

// Drawing coordinates only (one decimal is enough for a 120-wide line).
const round = (value) => Math.round(value * 10) / 10;
const line = computed(() => points.value.map((point, index) => `${index ? 'L' : 'M'}${round(point.x)},${round(point.y)}`).join(' '));
const area = computed(() => {
    const list = points.value;
    if (!list.length) return '';
    return `${line.value} L${round(list.at(-1).x)},${H} L${round(list[0].x)},${H} Z`;
});

function onMove(event) {
    const box = event.currentTarget.getBoundingClientRect();
    const x = ((event.clientX - box.left) / box.width) * W;
    let nearest = 0;
    points.value.forEach((point, index) => {
        if (Math.abs(point.x - x) < Math.abs(points.value[nearest].x - x)) nearest = index;
    });
    active.value = nearest;
}

const tip = computed(() => {
    if (active.value === null) return null;
    const point = points.value[active.value];
    return { left: `${(point.x / W) * 100}%`, text: `${props.labels[active.value] ? `${props.labels[active.value]} · ` : ''}${formatNumber(point.value)}` };
});
</script>

<template>
    <div v-if="series.length > 1" class="relative">
        <svg
            :viewBox="`0 0 ${W} ${H}`"
            class="block h-9 w-[7.5rem] overflow-visible text-brand"
            preserveAspectRatio="none"
            aria-hidden="true"
            @pointermove="onMove"
            @pointerleave="active = null"
        >
            <path :d="area" fill="currentColor" opacity="0.12" />
            <path :d="line" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            <template v-if="active !== null">
                <line :x1="points[active].x" :x2="points[active].x" y1="0" :y2="H" stroke="var(--c-line-strong)" stroke-width="1" vector-effect="non-scaling-stroke" />
                <circle :cx="points[active].x" :cy="points[active].y" r="3.5" fill="currentColor" stroke="var(--c-surface)" stroke-width="2" vector-effect="non-scaling-stroke" />
            </template>
            <circle v-else :cx="points.at(-1).x" :cy="points.at(-1).y" r="3" fill="currentColor" stroke="var(--c-surface)" stroke-width="2" vector-effect="non-scaling-stroke" />
        </svg>
        <span
            v-if="tip"
            class="tabular pointer-events-none absolute bottom-full mb-1 -translate-x-1/2 rounded-md bg-fg px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap text-canvas shadow-pop rtl:translate-x-1/2"
            :style="{ insetInlineStart: tip.left }"
            aria-hidden="true"
        >
            {{ tip.text }}
        </span>
        <table class="sr-only">
            <caption>{{ label }}</caption>
            <tbody>
                <tr v-for="(value, index) in series" :key="index">
                    <th scope="row">{{ labels[index] ?? index + 1 }}</th>
                    <td>{{ formatNumber(value) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
