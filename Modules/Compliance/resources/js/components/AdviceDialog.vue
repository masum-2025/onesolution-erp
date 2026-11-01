<script setup>
import { reactive, ref, watch } from 'vue';
import { Send } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { newOpId } from '@/lib/billing';
import { formatMoney, minorToDecimalString, currencyDigits } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { PAYMENT_METHODS, amountToMinor, localToday } from '../lib';

/**
 * Tell the provider an invoice was paid: how much, when, how and the bank
 * or cheque reference. It moves no money: the provider checks it arrived
 * and records it, or answers that it did not.
 */
const props = defineProps({
    open: Boolean,
    invoice: { type: Object, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'sent']);

const form = reactive({ amount: '', paid_on: '', method: 'bank', reference: '', note: '' });
const errors = ref({});
const saving = ref(false);
const today = localToday();
// One dialog, one advice: sending it twice (a slow network) is the same advice.
let opId = null;
watch(() => props.open, (open) => {
    if (!open || !props.invoice) return;
    Object.assign(form, { amount: minorToDecimalString(props.invoice.open_minor, currencyDigits(props.invoice.currency_code)), paid_on: today, method: 'bank', reference: '', note: '' });
    errors.value = {};
    opId = newOpId();
});
const money = (amount) => formatMoney({ amount, currency: props.invoice?.currency_code });

async function send() {
    const amount = amountToMinor(form.amount, props.invoice.currency_code);
    errors.value = amount === null ? { amount_minor: [t('compliance.advices.amount_needed')] } : {};
    if (amount === null) return;
    saving.value = true;
    try {
        const { data } = await props.calls.adviseInvoice(props.invoice.id, {
            amount_minor: amount, paid_on: form.paid_on, method: form.method,
            reference: form.reference.trim() || null, note: form.note.trim() || null, op_id: opId,
        });
        toast.success(t('compliance.advices.sent', { from: props.invoice.from }));
        emit('sent', data);
        emit('close');
    } catch (error) {
        // The amount or the day refused: shown at that field.
        const at = { advice_more_than_owed: 'amount_minor', invoice_nothing_owed: 'amount_minor', advice_in_future: 'paid_on' }[error.code];
        errors.value = at ? { [at]: [error.message] } : (error.errors ?? {});
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="t('compliance.advices.title')" :description="invoice ? t('compliance.advices.text', { from: invoice.from, number: invoice.number ?? '', open: money(invoice.open_minor) }) : ''" @close="emit('close')">
        <form v-if="invoice" id="compliance-advice" class="space-y-4" novalidate @submit.prevent="send">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('compliance.advices.amount', { currency: invoice.currency_code })" :error="fieldError('amount_minor')">
                    <input :id="id" v-model="form.amount" inputmode="decimal" autocomplete="off" class="field-input tabular" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('compliance.advices.paid_on')" :error="fieldError('paid_on')">
                    <input :id="id" v-model="form.paid_on" type="date" :max="today" class="field-input" />
                </AppField>
            </div>
            <AppField v-slot="{ id }" :label="t('compliance.advices.method')" :error="fieldError('method')">
                <select :id="id" v-model="form.method" class="field-input">
                    <option v-for="method in PAYMENT_METHODS" :key="method" :value="method">{{ t(`compliance.advices.methods.${method}`) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('compliance.advices.reference')" :hint="t('compliance.advices.reference_hint')" :error="fieldError('reference')" optional>
                <input :id="id" v-model="form.reference" maxlength="100" class="field-input" dir="auto" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('compliance.advices.note')" :error="fieldError('note')" optional>
                <textarea :id="id" v-model="form.note" rows="2" maxlength="500" class="field-input"></textarea>
            </AppField>
            <p class="rounded-lg bg-subtle px-3 py-2 text-[12.5px] text-muted">{{ t('compliance.advices.no_money_moves') }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('compliance.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="compliance-advice" :icon="Send" :loading="saving">{{ t('compliance.advices.send') }}</AppButton>
        </template>
    </AppDialog>
</template>
