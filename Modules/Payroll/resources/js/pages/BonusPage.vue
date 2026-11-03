<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Calculator, Check, Download, Gift, PenLine, Send, Trash2, Wallet, X } from 'lucide-vue-next';
import { DateInput, MoneyInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { saveBankFile } from '../bank';
import { percentText, runTone, sortBonusLines } from '../lib';

/**
 * One festival bonus: totals, every employee (paid first; why the others
 * get nothing), leaving someone out or setting an amount by hand while a
 * draft, and the steps this reader may take now (the server checks each
 * again): calculate, send, approve or send back (someone else), mark paid,
 * take the bank file.
 */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const route = useRoute();
const router = useRouter();
const record = useResource(() => payroll.bonus(route.params.id));
const bonus = computed(() => record.data.value?.data ?? null);
const lines = computed(() => sortBonusLines(bonus.value?.lines));
const money = (amount) => formatMoney({ amount, currency: bonus.value?.currency });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const busy = ref(null);

// Export and print (payroll.export): the bonus of each person, as the list shows it.
const exported = computed(() => (bonus.value ? {
    module: 'payroll',
    page: 'payroll.bonus',
    columns: [
        { key: 'employee_code', label: t('payroll.export.code') },
        { key: 'employee_name', label: t('payroll.export.employee') },
        { key: 'service_months', label: t('payroll.export.service_months'), type: 'number' },
        { key: 'basic_minor', label: t('payroll.export.basic'), type: 'money', currency: bonus.value.currency },
        { key: 'gross_minor', label: t('payroll.export.gross'), type: 'money', currency: bonus.value.currency },
        { key: 'tax_minor', label: t('payroll.slip.tax'), type: 'money', currency: bonus.value.currency },
        { key: 'net_minor', label: t('payroll.export.net'), type: 'money', currency: bonus.value.currency },
        { key: 'not_paid_reason', label: t('payroll.export.not_paid'), value: (item) => (item.not_paid_reason ? t(`payroll.bonus.reasons.${item.not_paid_reason}`) : '') },
    ],
    rows: lines.value,
} : null));

async function step(name, body = {}) {
    busy.value = name;
    try {
        await payroll.bonusStep(bonus.value.id, name, { base_version: bonus.value.version, ...body });
        toast.success(t(`payroll.run.done.${name}`));
        record.reload();
        return true;
    } catch (error) {
        if (error.code === 'version_conflict') record.reload();
        toast.error(error.message);
        return false;
    } finally {
        busy.value = null;
    }
}

async function confirmStep(name) {
    const confirmed = await confirmAction({
        title: t(`payroll.bonus.confirm.${name}_title`, { title: bonus.value.title }),
        message: t(`payroll.run.confirm.${name}_text`, { amount: money(bonus.value.net_minor) }),
        confirmLabel: t(`payroll.run.steps.${name}`),
        reason: name === 'reject' ? 'required' : 'none',
        danger: name === 'reject',
    });
    if (confirmed) await step(name, name === 'reject' ? { reason: confirmed.reason } : {});
}

const paying = ref(false);
const paidOn = ref('');
async function pay() {
    if (await step('pay', { paid_on: paidOn.value })) paying.value = false;
}

async function remove() {
    const confirmed = await confirmAction({ title: t('payroll.bonus.confirm.delete_title', { title: bonus.value.title }), message: t('payroll.run.confirm.delete_text'), confirmLabel: t('payroll.run.steps.delete'), danger: true });
    if (!confirmed) return;
    try {
        await payroll.deleteBonus(bonus.value.id, bonus.value.version);
        toast.success(t('payroll.run.done.delete'));
        router.push({ name: 'payroll-bonuses' });
    } catch (error) {
        toast.error(error.message);
    }
}

// One line by hand.
const editing = ref(null);
const line = reactive({ excluded: false, byHand: false, amount_minor: null });
const lineError = ref(null);
function edit(item) {
    Object.assign(line, { excluded: item.excluded, byHand: item.override_minor !== null, amount_minor: item.override_minor });
    lineError.value = null;
    editing.value = item;
}
async function saveLine() {
    const amount = line.byHand ? line.amount_minor : null;
    if (line.byHand && amount === null) {
        lineError.value = t('payroll.run.bad_amount');
        return;
    }
    busy.value = 'line';
    try {
        await payroll.changeBonusLine(bonus.value.id, editing.value.id, { excluded: line.excluded, override_minor: amount });
        editing.value = null;
        record.reload();
    } catch (error) {
        lineError.value = error.errors?.override_minor?.[0] ?? error.message;
    } finally {
        busy.value = null;
    }
}

async function bankFile() {
    busy.value = 'bank';
    try {
        const { data } = await payroll.bonusBankFile(bonus.value.id);
        const missing = saveBankFile(data, `bonus-${data.period}.csv`);
        toast.success(missing ? t('payroll.bank.missing', { count: formatNumber(missing) }) : t('payroll.bank.done'));
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <PageFrame :title="bonus ? bonus.title : ''" :description="bonus ? t('payroll.bonus.text', { date: day(bonus.bonus_on), percent: percentText(bonus.rate_bp) }) : ''" :resource="record" :export="exported" :skeleton-rows="6" width="full">
        <template #before>
            <AppButton variant="ghost" size="sm" :to="{ name: 'payroll-bonuses' }" :icon="ArrowLeft" class="mb-3">{{ t('payroll.bonuses.title') }}</AppButton>
        </template>
        <template #eyebrow>
            <span class="mb-2 flex"><AppBadge :tone="runTone(bonus.status)" dot>{{ t(`payroll.status.${bonus.status}`) }}</AppBadge></span>
        </template>
        <template #actions>
            <div class="flex flex-wrap gap-2">
                <AppButton v-if="bonus.can.calculate" variant="secondary" :icon="Calculator" :loading="busy === 'calculate'" @click="step('calculate')">{{ t('payroll.run.steps.calculate') }}</AppButton>
                <AppButton v-if="bonus.can.submit" variant="primary" :icon="Send" @click="confirmStep('submit')">{{ t('payroll.run.steps.submit') }}</AppButton>
                <AppButton v-if="bonus.can.approve" variant="primary" :icon="Check" @click="confirmStep('approve')">{{ t('payroll.run.steps.approve') }}</AppButton>
                <AppButton v-if="bonus.can.reject" variant="ghost" :icon="X" @click="confirmStep('reject')">{{ t('payroll.run.steps.reject') }}</AppButton>
                <AppButton v-if="bonus.can.pay" variant="primary" :icon="Wallet" @click="paidOn = new Date().toISOString().slice(0, 10); paying = true">{{ t('payroll.run.steps.pay') }}</AppButton>
                <AppButton v-if="bonus.can.bank_file" variant="secondary" :icon="Download" :loading="busy === 'bank'" @click="bankFile">{{ t('payroll.bank.take') }}</AppButton>
                <AppButton v-if="bonus.can.delete" variant="ghost" :icon="Trash2" :aria-label="t('payroll.run.steps.delete')" @click="remove" />
            </div>
        </template>
        <template v-if="bonus">

            <p v-if="bonus.reject_reason && bonus.status === 'draft'" class="mb-4 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="status">{{ t('payroll.run.sent_back', { reason: bonus.reject_reason }) }}</p>
            <p v-if="bonus.status === 'draft' && !bonus.calculated_at" class="mb-4 rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted" role="status">{{ t('payroll.bonus.needs_calculation') }}</p>

            <section class="card mb-5 grid grid-cols-2 gap-4 p-5 sm:grid-cols-4">
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.bonus.paid_people') }}</p><p class="tabular text-[18px] font-semibold">{{ formatNumber(bonus.employees) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.bonus.gross') }}</p><p class="tabular text-[18px] font-semibold">{{ money(bonus.gross_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.tax') }}</p><p class="tabular text-[18px] font-semibold">{{ money(bonus.tax_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.net') }}</p><p class="tabular text-[18px] font-semibold text-brand-text">{{ money(bonus.net_minor) }}</p></div>
            </section>

            <section class="card">
                <p v-if="!lines.length" class="px-5 py-6 text-center text-[13.5px] text-muted">{{ t('payroll.bonus.no_lines') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in lines" :key="item.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ item.employee_name }} <span class="whitespace-nowrap font-mono text-[11.5px] text-muted" dir="ltr">{{ item.employee_code }}</span></span>
                            <span class="block text-[12.5px] text-muted">
                                {{ t('payroll.bonus.service', { months: formatNumber(item.service_months) }) }} · {{ t('payroll.bonus.basic', { amount: money(item.basic_minor) }) }}
                                <template v-if="item.override_minor !== null && !item.excluded"> · {{ t('payroll.bonus.by_hand') }}</template>
                            </span>
                        </span>
                        <AppBadge v-if="item.not_paid_reason" tone="neutral">{{ t(`payroll.bonus.reasons.${item.not_paid_reason}`) }}</AppBadge>
                        <span v-else class="tabular w-40 text-end text-[13px]">
                            <span class="block font-semibold">{{ money(item.net_minor) }}</span>
                            <span v-if="item.tax_minor" class="block text-[11.5px] text-muted">{{ t('payroll.bonus.after_tax', { gross: money(item.gross_minor), tax: money(item.tax_minor) }) }}</span>
                        </span>
                        <AppButton v-if="bonus.can.change" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('payroll.bonus.change_line')" @click="edit(item)" />
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="editing !== null" :title="editing?.employee_name ?? ''" :description="t('payroll.bonus.change_text')" :icon="Gift" @close="editing = null">
            <form id="payroll-bonus-line" class="grid gap-4" novalidate @submit.prevent="saveLine">
                <AppSwitch v-model="line.excluded" :label="t('payroll.bonus.leave_out')" show-label />
                <template v-if="!line.excluded">
                    <AppSwitch v-model="line.byHand" :label="t('payroll.bonus.set_by_hand')" show-label />
                    <AppField v-if="line.byHand" v-slot="{ id }" :label="t('payroll.run.amount')" :error="lineError">
                        <MoneyInput :id="id" v-model="line.amount_minor" :currency="bonus?.currency" />
                    </AppField>
                </template>
                <p v-if="lineError && !line.byHand" class="text-[12.5px] text-bad">{{ lineError }}</p>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-bonus-line" :loading="busy === 'line'">{{ t('payroll.common.save') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="paying" :title="t('payroll.run.steps.pay')" :description="t('payroll.run.pay_text', { amount: bonus ? money(bonus.net_minor) : '' })" :icon="Wallet" @close="paying = false">
            <form id="payroll-bonus-pay" novalidate @submit.prevent="pay">
                <AppField v-slot="{ id }" :label="t('payroll.run.paid_on')">
                    <DateInput :id="id" v-model="paidOn" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="paying = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-bonus-pay" :loading="busy === 'pay'" :disabled="!paidOn">{{ t('payroll.run.steps.pay') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
