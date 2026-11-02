<script setup>
import { computed } from 'vue';
import { localToday } from '@/lib/dates';

/**
 * A date field ("YYYY-MM-DD"). notFuture / notPast bound it at the reader's
 * own today (not UTC's); min and max narrow it further. Every module's date
 * fields are this one.
 *
 *   <AppField v-slot="{ id }" :label="…"><DateInput :id="id" v-model="form.paid_on" not-future /></AppField>
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    min: { type: String, default: '' },
    max: { type: String, default: '' },
    notFuture: Boolean,
    notPast: Boolean,
});
const emit = defineEmits(['update:modelValue']);
defineOptions({ inheritAttrs: false });

const today = localToday();
const lowest = computed(() => [props.min, props.notPast ? today : ''].filter(Boolean).sort().at(-1) || undefined);
const highest = computed(() => [props.max, props.notFuture ? today : ''].filter(Boolean).sort().at(0) || undefined);
</script>

<template>
    <input v-bind="$attrs" type="date" :value="modelValue" :min="lowest" :max="highest" class="field-input" @input="emit('update:modelValue', $event.target.value)" />
</template>
