<script setup>
defineProps({
    options: { type: Array, required: true }, // [{ value, label, icon? }]
    modelValue: { type: [String, Number, Boolean], default: null },
    label: { type: String, required: true },
    size: { type: String, default: 'md' }, // sm | md
    block: Boolean,
    iconOnly: Boolean,
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="label"
        class="inline-flex gap-0.5 rounded-[10px] border border-line bg-subtle p-0.5"
        :class="block ? 'flex w-full' : ''"
    >
        <button
            v-for="option in options"
            :key="String(option.value)"
            type="button"
            role="radio"
            :aria-checked="modelValue === option.value"
            :aria-label="iconOnly ? option.label : undefined"
            :title="iconOnly ? option.label : undefined"
            class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg font-medium whitespace-nowrap transition-all duration-150"
            :class="[
                size === 'sm' ? 'h-7 px-2 text-[12.5px]' : 'h-8 px-3 text-[13px]',
                modelValue === option.value ? 'bg-surface text-fg shadow-[0_1px_2px_rgb(0_0_0/0.08),0_0_0_1px_var(--c-line)]' : 'text-muted hover:text-fg',
            ]"
            @click="emit('update:modelValue', option.value)"
        >
            <component :is="option.icon" v-if="option.icon" class="size-3.5 shrink-0" aria-hidden="true" />
            <span v-if="!iconOnly">{{ option.label }}</span>
        </button>
    </div>
</template>
