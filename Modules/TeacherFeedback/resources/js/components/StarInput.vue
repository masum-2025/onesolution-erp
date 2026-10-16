<script setup>
import { Star } from 'lucide-vue-next';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * One to five stars for a question: real radio buttons (keyboard and screen
 * readers work), drawn as stars, with the number spoken.
 */
const props = defineProps({ modelValue: { type: Number, default: null }, label: { type: String, required: true }, name: { type: String, required: true } });
const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <fieldset class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5">
        <legend class="float-start min-w-0 flex-1 text-[13.5px] text-fg">{{ label }}</legend>
        <div class="flex items-center gap-0.5">
            <label v-for="value in 5" :key="value" class="cursor-pointer rounded-md p-1 focus-within:ring-2 focus-within:ring-brand/60">
                <input class="sr-only" type="radio" :name="name" :value="value" :checked="modelValue === value" @change="emit('update:modelValue', value)" />
                <span class="sr-only">{{ t('teacher_feedback.stars', { count: formatNumber(value) }) }}</span>
                <Star class="size-6 transition" :class="(modelValue ?? 0) >= value ? 'fill-warn text-warn' : 'text-faint'" aria-hidden="true" />
            </label>
        </div>
    </fieldset>
</template>
