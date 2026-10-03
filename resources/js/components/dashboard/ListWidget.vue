<script setup>
import { ChevronRight, CircleAlert, CircleCheck, Dot, TriangleAlert } from 'lucide-vue-next';
import { formatDate } from '@/lib/format';

/**
 * A short list of things (latest changes, things to do). Each line's tone has
 * its own icon as well as its color; lines with a screen open it.
 */
defineProps({
    data: { type: Object, required: true },
});

const TONES = {
    good: { icon: CircleCheck, class: 'bg-ok-soft text-ok' },
    warn: { icon: TriangleAlert, class: 'bg-warn-soft text-warn' },
    bad: { icon: CircleAlert, class: 'bg-bad-soft text-bad' },
};
const toneOf = (item) => TONES[item.tone] ?? { icon: Dot, class: 'bg-subtle text-muted' };
</script>

<template>
    <ul class="-mx-2 space-y-0.5">
        <li v-for="(item, index) in data.items" :key="index">
            <component
                :is="item.path ? 'RouterLink' : 'div'"
                :to="item.path ?? undefined"
                class="group flex items-center gap-3 rounded-xl px-2 py-2"
                :class="item.path && 'transition-colors hover:bg-subtle'"
            >
                <span class="grid size-8 shrink-0 place-items-center rounded-lg" :class="toneOf(item).class" aria-hidden="true">
                    <component :is="toneOf(item).icon" class="size-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13.5px] font-medium text-fg">{{ item.label }}</span>
                    <span v-if="item.meta || item.date" class="mt-0.5 block truncate text-[12.5px] text-muted">{{ [item.meta, item.date && formatDate(item.date, { dateStyle: 'medium', timeZone: 'UTC' })].filter(Boolean).join(' · ') }}</span>
                </span>
                <ChevronRight v-if="item.path" class="size-4 shrink-0 text-faint transition group-hover:translate-x-0.5 group-hover:text-fg-2 rtl:rotate-180" aria-hidden="true" />
            </component>
        </li>
    </ul>
</template>
