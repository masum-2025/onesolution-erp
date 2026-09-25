<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <slot name="eyebrow" />
            <h1 class="text-[22px] leading-tight font-semibold tracking-[-0.02em] text-fg sm:text-[24px]">{{ title }}</h1>
            <p v-if="description" class="mt-1.5 max-w-2xl text-[13.5px] leading-relaxed text-muted">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
