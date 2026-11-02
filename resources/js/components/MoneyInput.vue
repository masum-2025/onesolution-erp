<script setup>
import { ref, watch } from 'vue';
import { amountToMinor, minorToText } from '@/lib/numbers';

/**
 * A money field: people type Bangla or Latin digits (commas fine), the
 * model is integer minor units of the currency (null while it is empty or
 * not an amount). Every module's money fields are this one.
 *
 *   <AppField v-slot="{ id }" :label="…"><MoneyInput :id="id" v-model="form.amount_minor" :currency="currency" /></AppField>
 */
const props = defineProps({
    modelValue: { type: Number, default: null },
    currency: { type: String, default: null },
    placeholder: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);
// id, aria-* and the rest belong to the field itself, not its wrapper (the label points at it).
defineOptions({ inheritAttrs: false });

const text = ref(minorToText(props.modelValue, props.currency));
// Set from outside (a reset, a suggested amount): show it, unless it is what is already typed.
watch(() => props.modelValue, (value) => {
    if (amountToMinor(text.value, props.currency) !== value) text.value = minorToText(value, props.currency);
});

function typed(event) {
    text.value = event.target.value;
    emit('update:modelValue', amountToMinor(text.value, props.currency));
}
</script>

<template>
    <div class="relative">
        <input v-bind="$attrs" :value="text" inputmode="decimal" autocomplete="off" class="field-input tabular" :class="currency ? 'pe-14' : ''" dir="ltr" :placeholder="placeholder" @input="typed" />
        <span v-if="currency" class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-[12px] text-muted" aria-hidden="true">{{ currency }}</span>
    </div>
</template>
