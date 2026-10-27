<script setup>
import { i18n } from '@/lib/i18n';
import { nameIn } from '../lib';

/** One rating on the cycle's scale: a button per step (its value and name), the chosen one marked; pressed again, cleared. */
const props = defineProps({
    modelValue: { type: Number, default: null },
    scale: { type: Array, required: true },
    label: { type: String, required: true },
    disabled: Boolean,
});
const emit = defineEmits(['update:modelValue']);

function choose(value) {
    emit('update:modelValue', props.modelValue === value ? null : value);
}
</script>

<template>
    <div class="flex flex-wrap gap-1.5" role="radiogroup" :aria-label="label">
        <button
            v-for="step in scale"
            :key="step.value"
            type="button"
            role="radio"
            :aria-checked="modelValue === step.value"
            :disabled="disabled"
            :title="nameIn(step.name, i18n.locale)"
            class="min-w-10 rounded-lg border px-2.5 py-1.5 text-[13px] font-medium transition"
            :class="modelValue === step.value ? 'border-brand bg-brand text-white' : 'border-line hover:bg-subtle'"
            @click="choose(step.value)"
        >
            <span class="tabular">{{ step.value }}</span>
            <span class="ms-1 hidden font-normal sm:inline">{{ nameIn(step.name, i18n.locale) }}</span>
        </button>
    </div>
</template>
