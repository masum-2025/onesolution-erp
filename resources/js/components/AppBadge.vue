<script setup>
defineProps({
    tone: { type: String, default: 'neutral' }, // neutral | brand | ok | warn | bad | outline
    icon: { type: [Object, Function], default: null },
    dot: Boolean,
});

const TONES = {
    neutral: 'bg-subtle text-fg-2 ring-line',
    brand: 'bg-brand-soft text-brand-text ring-brand/20',
    ok: 'bg-ok-soft text-ok ring-ok/20',
    warn: 'bg-warn-soft text-warn ring-warn/25',
    bad: 'bg-bad-soft text-bad ring-bad/20',
    outline: 'bg-transparent text-muted ring-line-strong',
};
</script>

<template>
    <span
        class="inline-flex h-[22px] max-w-full items-center gap-1 rounded-md px-1.5 text-[11.5px] leading-none font-medium whitespace-nowrap ring-1 ring-inset"
        :class="TONES[tone] ?? TONES.neutral"
    >
        <span v-if="dot" class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" />
        <component :is="icon" v-else-if="icon" class="size-3 shrink-0" aria-hidden="true" />
        <span class="truncate"><slot /></span>
    </span>
</template>
