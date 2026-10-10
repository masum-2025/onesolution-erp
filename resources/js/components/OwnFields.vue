<script setup>
import AppField from './AppField.vue';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';

/**
 * A company's own fields of a kind of record as inputs, for every module
 * (CRM contacts and quotes, Education students and guardians, …).
 *
 * fields: [{ key, type, label | label_text, options: [{ value, label }], is_required }]
 * with labels as text or as texts by language. Types: text, long_text,
 * number, money, date, choice, multi_choice, yes_no. v-model is {key: value}
 * (text, or a list for multi_choice); the module turns it into API values.
 * Errors come as {"<prefix>.<key>": [message]}.
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
const label = (item) => item.label_text ?? (typeof item.label === 'string' ? item.label : textIn(item.label));
const numeric = (field) => ['number', 'money'].includes(field.type);

function toggle(field, value) {
    const chosen = new Set(props.modelValue[field.key] ?? []);
    chosen.has(value) ? chosen.delete(value) : chosen.add(value);
    set(field.key, [...chosen]);
}
</script>

<template>
    <div v-if="fields.length" class="grid gap-4" :class="compact ? 'grid-cols-1 sm:grid-cols-2' : 'sm:grid-cols-2'">
        <template v-for="field in fields" :key="field.key">
            <fieldset v-if="field.type === 'multi_choice'" class="sm:col-span-2">
                <legend class="mb-1.5 text-[13px] font-medium text-fg-2">
                    {{ label(field) }}<span v-if="!field.is_required" class="ms-1 font-normal text-muted">({{ t('core.optional') }})</span>
                </legend>
                <div class="flex flex-wrap gap-2">
                    <label v-for="option in field.options" :key="option.value"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-[13px] transition has-[:checked]:border-brand has-[:checked]:bg-brand-soft has-[:checked]:text-brand-text"
                        :class="'border-line-strong hover:bg-subtle'">
                        <input type="checkbox" class="size-3.5 accent-[var(--brand)]" :checked="(modelValue[field.key] ?? []).includes(option.value)" @change="toggle(field, option.value)" />
                        {{ label(option) }}
                    </label>
                </div>
                <p v-if="error(field.key)" class="mt-1 text-[12.5px] text-bad" role="alert">{{ error(field.key) }}</p>
            </fieldset>
            <AppField v-else v-slot="{ id, invalid, describedby }" :label="label(field)" :error="error(field.key)" :optional="!field.is_required"
                :class="field.type === 'long_text' ? 'sm:col-span-2' : ''">
                <textarea v-if="field.type === 'long_text'" :id="id" :value="modelValue[field.key] ?? ''" rows="3" class="field-input" maxlength="2000"
                    :aria-invalid="invalid || undefined" :aria-describedby="describedby" @input="set(field.key, $event.target.value)" />
                <select v-else-if="field.type === 'choice'" :id="id" :value="modelValue[field.key] ?? ''" class="field-input" :aria-invalid="invalid || undefined"
                    :aria-describedby="describedby" @change="set(field.key, $event.target.value)">
                    <option value="">—</option>
                    <option v-for="option in field.options" :key="option.value" :value="option.value">{{ label(option) }}</option>
                </select>
                <select v-else-if="field.type === 'yes_no'" :id="id" :value="modelValue[field.key] ?? ''" class="field-input" :aria-invalid="invalid || undefined"
                    :aria-describedby="describedby" @change="set(field.key, $event.target.value)">
                    <option value="">—</option>
                    <option value="yes">{{ t('core.yes') }}</option>
                    <option value="no">{{ t('core.no') }}</option>
                </select>
                <input v-else :id="id" :value="modelValue[field.key] ?? ''" class="field-input"
                    :type="field.type === 'date' ? 'date' : 'text'" :inputmode="numeric(field) ? 'decimal' : undefined"
                    :dir="numeric(field) ? 'ltr' : undefined" :class="numeric(field) ? 'tabular text-end' : ''"
                    :maxlength="field.type === 'text' ? 255 : 40" :placeholder="field.type === 'money' ? currency ?? '' : ''"
                    :aria-invalid="invalid || undefined" :aria-describedby="describedby" @input="set(field.key, $event.target.value)" />
            </AppField>
        </template>
    </div>
</template>
