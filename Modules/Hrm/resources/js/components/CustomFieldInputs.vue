<script setup>
import AppField from '@/components/AppField.vue';
import { t } from '@/lib/i18n';

/**
 * Inputs for the extra fields of a unit. v-model is { key: value }; yes/no
 * answers are true / false, everything else text (the server normalizes).
 * `errors` is the API's error bag ("custom.key" => [message]).
 */
const props = defineProps({
    fields: { type: Array, required: true },
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

function update(key, value) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}

/** Select values are strings; yes/no is kept as a real true / false. */
function yesNoValue(value) {
    return value === true ? 'yes' : value === false ? 'no' : '';
}

function fromYesNo(value) {
    return value === 'yes' ? true : value === 'no' ? false : null;
}

const errorOf = (key) => props.errors?.[`custom.${key}`]?.[0] ?? null;
</script>

<template>
    <AppField v-for="field in fields" :key="field.key" v-slot="{ id }" :label="field.label" :error="errorOf(field.key)" :optional="!field.is_required">
        <select v-if="field.type === 'choice'" :id="id" :value="modelValue[field.key] ?? ''" class="field-input" @change="update(field.key, $event.target.value)">
            <option value="">–</option>
            <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <select v-else-if="field.type === 'yes_no'" :id="id" :value="yesNoValue(modelValue[field.key])" class="field-input" @change="update(field.key, fromYesNo($event.target.value))">
            <option value="">–</option>
            <option value="yes">{{ t('hrm.custom.yes') }}</option>
            <option value="no">{{ t('hrm.custom.no') }}</option>
        </select>
        <input v-else-if="field.type === 'date'" :id="id" :value="modelValue[field.key] ?? ''" type="date" class="field-input" @input="update(field.key, $event.target.value)" />
        <input
            v-else-if="field.type === 'number'"
            :id="id"
            :value="modelValue[field.key] ?? ''"
            type="text"
            inputmode="decimal"
            dir="ltr"
            class="field-input"
            maxlength="20"
            @input="update(field.key, $event.target.value)"
        />
        <input v-else :id="id" :value="modelValue[field.key] ?? ''" class="field-input" maxlength="255" @input="update(field.key, $event.target.value)" />
    </AppField>
</template>
