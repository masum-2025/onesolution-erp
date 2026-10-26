<script setup>
import { computed, ref, watch } from 'vue';
import { Wallet } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { amountText, monthOf, monthsFrom, monthText } from '../lib';

/**
 * Paying an approved claim back: in cash or by bank now (into the books at
 * once), or with a month's salary from this month on (Payroll pays it with
 * that month; until then it may be moved or paid now instead).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    claim: { type: Object, default: null },
    // whether Payroll is on for the company
    payroll: { type: Boolean, default: false },
    // (body) => Promise
    send: { type: Function, required: true },
});
const emit = defineEmits(['close', 'paid']);

const method = ref('cash');
const period = ref(monthOf());
const errors = ref({});
const saving = ref(false);
const months = computed(() => monthsFrom(monthOf(), 4));
const methods = computed(() => ['cash', 'bank', ...(props.payroll ? ['payroll'] : [])].map((value) => ({ value, label: t(`expenses.pay.${value}`) })));

watch(() => props.open, (open) => {
    if (!open) return;
    method.value = props.claim?.pay_method === 'payroll' ? 'payroll' : 'cash';
    period.value = props.claim?.pay_period ?? monthOf();
    errors.value = {};
});

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await props.send({ base_version: props.claim.version, method: method.value, ...(method.value === 'payroll' ? { period: period.value } : {}) });
        toast.success(t(method.value === 'payroll' ? 'expenses.pay.scheduled' : 'expenses.pay.done'));
        emit('paid');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('expenses.pay.title')" :description="claim ? t('expenses.pay.text', { number: claim.number, name: claim.employee?.name, amount: amountText(claim.total_minor, claim.currency) }) : ''" :icon="Wallet" @close="emit('close')">
        <form id="expenses-pay" class="space-y-4" novalidate @submit.prevent="save">
            <AppSegmented v-model="method" block :label="t('expenses.pay.method')" :options="methods" />
            <AppField v-if="method === 'payroll'" v-slot="{ id }" :label="t('expenses.pay.period')" :hint="t('expenses.pay.period_hint')" :error="errors.period?.[0]">
                <select :id="id" v-model="period" class="field-input">
                    <option v-for="month in months" :key="month" :value="month">{{ monthText(month) }}</option>
                </select>
            </AppField>
            <p v-else class="text-[12.5px] text-muted">{{ t(`expenses.pay.${method}_hint`) }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('expenses.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="expenses-pay" :loading="saving">{{ t(method === 'payroll' ? 'expenses.pay.schedule' : 'expenses.pay.confirm') }}</AppButton>
        </template>
    </AppDialog>
</template>
