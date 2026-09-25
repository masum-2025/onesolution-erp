<script setup>
defineProps({
    modelValue: Boolean,
    disabled: Boolean,
    label: { type: String, required: true },
    showLabel: Boolean,
    busy: Boolean,
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <label class="inline-flex items-center gap-2.5" :class="disabled ? 'cursor-not-allowed' : 'cursor-pointer'">
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :aria-label="showLabel ? undefined : label"
            :aria-busy="busy || undefined"
            :disabled="disabled || busy"
            class="relative inline-flex h-[22px] w-[38px] shrink-0 items-center rounded-full p-0.5 transition-colors duration-200 disabled:opacity-50"
            :class="modelValue ? 'bg-brand' : 'bg-line-strong'"
            @click="emit('update:modelValue', !modelValue)"
        >
            <span
                class="size-[18px] rounded-full bg-white shadow-[0_1px_3px_rgb(0_0_0/0.25)] transition-transform duration-200 ease-[var(--ease-soft)]"
                :class="modelValue ? 'translate-x-4 rtl:-translate-x-4' : 'translate-x-0'"
            />
        </button>
        <span v-if="showLabel" class="text-[13.5px] text-fg">{{ label }}</span>
    </label>
</template>
