<script setup>
import { computed, watch } from 'vue';
import { Wand2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { amountText, autoAllocate, parseAmount } from '../lib';

/**
 * Open invoices (or bills) of one party with an amount field each: how much
 * of the money (or credit) pays which. "Fill oldest first" spreads the
 * available amount by due date. v-model: { [document id]: typed text }.
 */
const props = defineProps({
    documents: { type: Array, required: true },
    available: { type: Number, default: 0 },
    currency: { type: String, required: true },
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

const money = (amount) => formatMoney({ amount, currency: props.currency });
const allocated = computed(() => Object.values(props.modelValue).reduce((sum, text) => sum + (parseAmount(text, props.currency) ?? 0), 0));
const left = computed(() => props.available - allocated.value);

function set(id, text) {
    emit('update:modelValue', { ...props.modelValue, [id]: text });
}

function fillOldest() {
    const shares = autoAllocate(props.documents, props.available);
    emit('update:modelValue', Object.fromEntries(props.documents.map((document) => [document.id, amountText(shares[document.id] ?? 0, props.currency)])));
}

// New documents (another party) start empty.
watch(
    () => props.documents.map((document) => document.id).join(),
    () => emit('update:modelValue', {}),
);

/** The request body part: allocations with an amount. */
defineExpose({
    payload: () =>
        Object.entries(props.modelValue)
            .map(([document_id, text]) => ({ document_id, amount_minor: parseAmount(text, props.currency) }))
            .filter((item) => item.amount_minor),
});
</script>

<template>
    <div>
        <ul class="divide-y divide-line rounded-xl border border-line">
            <li v-for="(document, index) in documents" :key="document.id" class="grid grid-cols-[minmax(0,1fr)_9rem] items-center gap-3 px-4 py-2.5">
                <span class="min-w-0">
                    <span class="block truncate text-[13.5px] font-medium"><span class="font-mono" dir="ltr">{{ document.number }}</span> · {{ formatDate(document.issue_date) }}</span>
                    <span class="block text-[12px] text-muted">{{ t('accounting.documents.balance') }}: <span class="tabular">{{ money(document.balance_minor) }}</span> · {{ t('accounting.documents.due_date') }} {{ formatDate(document.due_date) }}</span>
                    <span v-if="errors[`allocations.${index}.amount_minor`] || errors[`allocations.${index}.document_id`]" class="block text-[12px] text-bad">
                        {{ (errors[`allocations.${index}.amount_minor`] ?? errors[`allocations.${index}.document_id`])[0] }}
                    </span>
                </span>
                <input
                    :value="modelValue[document.id] ?? ''"
                    inputmode="decimal"
                    class="field-input tabular text-end"
                    dir="ltr"
                    autocomplete="off"
                    :aria-label="`${document.number}: ${t('accounting.reports.amount')}`"
                    @input="set(document.id, $event.target.value)"
                />
            </li>
        </ul>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-[13px]" aria-live="polite">
            <AppButton size="sm" variant="ghost" :icon="Wand2" :disabled="available <= 0" @click="fillOldest">{{ t('accounting.settlements.fill_oldest') }}</AppButton>
            <span>
                <span class="text-muted">{{ t('accounting.settlements.allocated') }}:</span> <span class="tabular font-medium">{{ money(allocated) }}</span>
                <template v-if="left > 0"> · <span class="text-muted">{{ t('accounting.settlements.unallocated') }}:</span> <span class="tabular font-medium">{{ money(left) }}</span></template>
            </span>
        </div>
        <p v-if="left < 0" class="mt-1 text-[12.5px] text-bad">{{ t('accounting.settlements.more_than_amount') }}</p>
    </div>
</template>
