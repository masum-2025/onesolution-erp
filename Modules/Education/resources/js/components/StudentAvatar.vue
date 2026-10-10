<script setup>
import { computed, ref } from 'vue';
import { hue, initials } from '../lib';

/**
 * A student's photo (short-lived signed link), else their initials on a
 * steady colour from their name. Decorative: the name is always next to it.
 */
const props = defineProps({
    name: { type: String, default: '' },
    photoUrl: { type: String, default: null },
    size: { type: String, default: 'md' }, // sm | md | lg | xl
});

const failed = ref(false);
const sizes = { sm: 'size-8 text-[12px]', md: 'size-10 text-[14px]', lg: 'size-14 text-[18px]', xl: 'size-24 text-[30px]' };
// Light and dark friendly tints; contrast checked against their text colour.
const tints = [
    'bg-sky-500/15 text-sky-800 dark:text-sky-200',
    'bg-emerald-500/15 text-emerald-800 dark:text-emerald-200',
    'bg-violet-500/15 text-violet-800 dark:text-violet-200',
    'bg-amber-500/20 text-amber-900 dark:text-amber-200',
    'bg-rose-500/15 text-rose-800 dark:text-rose-200',
    'bg-teal-500/15 text-teal-800 dark:text-teal-200',
    'bg-indigo-500/15 text-indigo-800 dark:text-indigo-200',
    'bg-orange-500/15 text-orange-900 dark:text-orange-200',
];
const tint = computed(() => tints[hue(props.name, tints.length)]);
</script>

<template>
    <span class="relative inline-grid shrink-0 place-items-center overflow-hidden rounded-full font-semibold ring-1 ring-line" :class="[sizes[size], photoUrl && !failed ? '' : tint]" aria-hidden="true">
        <img v-if="photoUrl && !failed" :src="photoUrl" alt="" class="size-full object-cover" loading="lazy" @error="failed = true" />
        <template v-else>{{ initials(name) }}</template>
    </span>
</template>
