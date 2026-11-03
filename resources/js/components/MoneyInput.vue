<script setup>
import { ref, watch } from 'vue';
import { amountToMinor, minorToText } from '@/lib/numbers';

/**
 * A money field: people type Bangla or Latin digits (commas fine), the
 * model is integer minor units of the currency (null while it is empty or
 * not an amount). Every module's money fields are this one.
 *
 *   <AppField v-slot="{ id }" :label="…"><MoneyInput :id="id" v-model="form.amount_minor" :currency="currency" /></AppField>
 *
 * v-model:invalid tells an empty field from one with something typed that is
 * not an amount (both are null): an optional amount is never saved as "none"
 * by mistake.
 */
const props = defineProps({
    modelValue: { type: Number, default: null },
    currency: { type: String, default: null },
    placeholder: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'update:invalid']);
// id, aria-* and the rest belong to the field itself, not its wrapper (the label points at it).
defineOptions({ inheritAttrs: false });

const text = ref(minorToText(props.modelValue, props.currency));
// Set from outside (a reset, a suggested amount): show it, unless it is what is already typed.
watch(() => props.modelValue, (value) => {
    if (amountToMinor(text.value, props.currency) !== value) {
        text.value = minorToText(value, props.currency);
        if (props.invalid) emit('update:invalid', false);
    }
});

function typed(event) {
    text.value = event.target.value;
    const amount = amountToMinor(text.value, props.currency);
    emit('update:modelValue', amount);
    const invalid = amount === null && text.value.trim() !== '';
    if (invalid !== props.invalid) emit('update:invalid', invalid);
}
</script>

<template>
    <div class="relative">
        <input v-bind="$attrs" :value="text" :aria-invalid="invalid || $attrs['aria-invalid'] || undefined" inputmode="decimal" autocomplete="off" class="field-input tabular" :class="currency ? 'pe-14' : ''" dir="ltr" :placeholder="placeholder" @input="typed" />
        <span v-if="currency" class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-[12px] text-muted" aria-hidden="true">{{ currency }}</span>
    </div>
</template>
