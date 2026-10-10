<script setup>
import OwnFields from '@/components/OwnFields.vue';

/**
 * The company's own fields of a kind of CRM record (contact, deal, quote,
 * quote line) as inputs: the shared OwnFields. v-model is {key: text}; the
 * parent turns it into API values with fieldsToApi().
 */
defineProps({
    fields: { type: Array, required: true },
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    prefix: { type: String, default: 'extra' },
    currency: { type: String, default: null },
    compact: Boolean,
});
const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <OwnFields :fields="fields" :model-value="modelValue" :errors="errors" :prefix="prefix" :currency="currency" :compact="compact" @update:model-value="emit('update:modelValue', $event)" />
</template>
