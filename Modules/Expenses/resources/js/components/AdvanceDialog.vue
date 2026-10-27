<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { HandCoins } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { amountText, amountToMinor, newOpId } from '../lib';

/** Asking for an advance: how much, what for, by when it will be settled (else the rule's days after it is paid). Sent for approval at once. */
const props = defineProps({
    open: { type: Boolean, default: false },
    currency: { type: String, default: 'BDT' },
    // the most one may have owed or asked at a time (rule)
    max: { type: Number, default: 0 },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'sent']);

const form = reactive({ amount: '', purpose: '', settle_by: '' });
const errors = ref({});
const saving = ref(false);
let opId = newOpId();
const minor = computed(() => amountToMinor(form.amount, props.currency));

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { amount: '', purpose: '', settle_by: '' });
    errors.value = {};
    opId = newOpId();
});

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await props.calls.askAdvance({ amount_minor: minor.value, purpose: form.purpose.trim(), settle_by: form.settle_by || null, op_id: opId });
        toast.success(t('expenses.advance.sent'));
        emit('sent');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('expenses.advance.ask_title')" :description="t('expenses.advance.ask_text', { max: amountText(max, currency) })" :icon="HandCoins" @close="emit('close')">
        <form id="expenses-advance" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('expenses.advance.amount', { currency })" :error="errors.amount_minor?.[0] ?? (form.amount && minor === null ? t('expenses.advance.amount_bad') : null)">
                <input :id="id" v-model="form.amount" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('expenses.advance.settle_by')" :hint="t('expenses.advance.settle_by_hint')" :error="errors.settle_by?.[0]" optional>
                <input :id="id" v-model="form.settle_by" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('expenses.advance.purpose')" :error="errors.purpose?.[0]" class="sm:col-span-2">
                <input :id="id" v-model="form.purpose" class="field-input" maxlength="300" :placeholder="t('expenses.advance.purpose_hint')" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('expenses.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="expenses-advance" :loading="saving" :disabled="!minor || form.purpose.trim().length < 3">{{ t('expenses.advance.send') }}</AppButton>
        </template>
    </AppDialog>
</template>
