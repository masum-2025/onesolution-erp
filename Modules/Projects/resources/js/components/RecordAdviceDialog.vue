<script setup>
import { reactive, ref, watch } from 'vue';
import { BookCheck } from 'lucide-vue-next';
import { DateInput } from '@/ui';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { formatDate, formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * The client says it paid: once the money is seen in the bank or the cash
 * box, record it as a receipt in Accounting (into this account, on this
 * day). Accounting's approval rules apply; the client is told.
 */
const props = defineProps({
    open: Boolean,
    advice: { type: Object, default: null },
    accounts: { type: Array, default: () => [] },
    projectId: { type: String, required: true },
    today: { type: String, default: '' },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'recorded']);

const form = reactive({ account_id: '', settled_on: '' });
const errors = ref({});
const saving = ref(false);
watch(() => props.open, (open) => {
    if (!open || !props.advice) return;
    // The client's day unless it is later than today here.
    const day = props.today && props.advice.paid_on > props.today ? props.today : props.advice.paid_on;
    Object.assign(form, { account_id: props.accounts.length === 1 ? props.accounts[0].id : '', settled_on: day });
    errors.value = {};
});

async function record() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await props.calls.decideAdvice(props.projectId, props.advice.id, 'record', { base_version: props.advice.version, ...form });
        toast.success(t('projects.advices.recorded'));
        emit('recorded', data);
        emit('close');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="t('projects.advices.record_title')"
        :description="advice ? t('projects.advices.record_text', { from: advice.from, amount: formatMoney({ amount: advice.amount_minor, currency: advice.currency_code }), date: formatDate(advice.paid_on) }) : ''" @close="emit('close')">
        <form v-if="advice" id="projects-record-advice" class="space-y-4" novalidate @submit.prevent="record">
            <AppField v-slot="{ id }" :label="t('projects.advices.account')" :hint="t('projects.advices.account_hint')" :error="fieldError('account_id')">
                <select :id="id" v-model="form.account_id" class="field-input">
                    <option value="" disabled>{{ t('projects.advices.choose_account') }}</option>
                    <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.advices.settled_on')" :error="fieldError('settled_on')">
                <DateInput :id="id" v-model="form.settled_on" :max="today || undefined" />
            </AppField>
            <p v-if="advice.reference" class="text-[12.5px] text-muted">{{ t('projects.advices.reference', { reference: advice.reference }) }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('projects.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="projects-record-advice" :icon="BookCheck" :loading="saving">{{ t('projects.advices.record') }}</AppButton>
        </template>
    </AppDialog>
</template>
