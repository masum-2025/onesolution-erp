<script setup>
import AppField from '@/components/AppField.vue';
import { t } from '@/lib/i18n';

/**
 * The company's own fields of a kind of record (contact, deal, quote, quote
 * line) as inputs. v-model is {key: text}; the parent turns it into API
 * values with fieldsToApi(). Errors come as {"<prefix>.<key>": [message]}.
 */
const props = defineProps({
    fields: { type: Array, required: true },
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    prefix: { type: String, default: 'extra' },
    currency: { type: String, default: null },
    compact: Boolean,
});
const emit = defineEmits(['update:modelValue']);
const set = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value });
const error = (key) => props.errors[`${props.prefix}.${key}`]?.[0] ?? null;
</script>

<template>
    <div v-if="fields.length" class="grid gap-4" :class="compact ? 'grid-cols-1 sm:grid-cols-2' : 'sm:grid-cols-2'">
        <AppField v-for="field in fields" :key="field.key" v-slot="{ id, invalid, describedby }" :label="field.label" :error="error(field.key)" :optional="!field.is_required"
            :class="field.type === 'long_text' ? 'sm:col-span-2' : ''">
            <textarea v-if="field.type === 'long_text'" :id="id" :value="modelValue[field.key] ?? ''" rows="3" class="field-input" maxlength="2000"
                :aria-invalid="invalid || undefined" :aria-describedby="describedby" @input="set(field.key, $event.target.value)" />
            <select v-else-if="field.type === 'choice'" :id="id" :value="modelValue[field.key] ?? ''" class="field-input" :aria-invalid="invalid || undefined"
                :aria-describedby="describedby" @change="set(field.key, $event.target.value)">
                <option value="">—</option>
                <option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <select v-else-if="field.type === 'yes_no'" :id="id" :value="modelValue[field.key] ?? ''" class="field-input" :aria-invalid="invalid || undefined"
                :aria-describedby="describedby" @change="set(field.key, $event.target.value)">
                <option value="">—</option>
                <option value="yes">{{ t('crm.common.yes') }}</option>
                <option value="no">{{ t('crm.common.no') }}</option>
            </select>
            <input v-else :id="id" :value="modelValue[field.key] ?? ''" class="field-input"
                :type="field.type === 'date' ? 'date' : 'text'" :inputmode="['number', 'money'].includes(field.type) ? 'decimal' : undefined"
                :dir="['number', 'money'].includes(field.type) ? 'ltr' : undefined" :class="['number', 'money'].includes(field.type) ? 'tabular text-end' : ''"
                :maxlength="field.type === 'text' ? 255 : 40" :placeholder="field.type === 'money' ? currency ?? '' : ''"
                :aria-invalid="invalid || undefined" :aria-describedby="describedby" @input="set(field.key, $event.target.value)" />
        </AppField>
    </div>
</template>
