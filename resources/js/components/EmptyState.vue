<script setup>
defineProps({
    icon: { type: [Object, Function], required: true },
    title: { type: String, required: true },
    text: { type: String, default: '' },
    compact: Boolean,
});
</script>

<template>
    <div class="flex flex-col items-center text-center" :class="compact ? 'px-4 py-8' : 'px-6 py-14'">
        <div class="relative mb-5" aria-hidden="true">
            <div class="absolute -inset-5 rounded-[28px] bg-[radial-gradient(closest-side,var(--brand-soft),transparent)]" />
            <div class="absolute -inset-2 rounded-[22px] border border-dashed border-line-strong/70" />
            <div class="relative grid size-14 place-items-center rounded-2xl border border-line bg-surface shadow-card">
                <component :is="icon" class="size-6 text-brand-text" />
            </div>
        </div>
        <h3 class="text-[15px] font-semibold text-fg">{{ title }}</h3>
        <p v-if="text" class="mt-1.5 max-w-sm text-[13px] leading-relaxed text-muted">{{ text }}</p>
        <div v-if="$slots.default" class="mt-5 flex flex-wrap justify-center gap-2">
            <slot />
        </div>
    </div>
</template>
