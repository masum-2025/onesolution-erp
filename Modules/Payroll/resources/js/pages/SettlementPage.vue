<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Calculator, Check, Download, Plus, Printer, Send, Trash2, Wallet, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { saveBankFile } from '../bank';
import { amountToMinor, runTone, settlementBasis, settlementSections } from '../lib';

/**
 * One final settlement: service, each line with how it was worked out
 * (gratuity, provident fund, loans owed), lines by hand, tax and net; and
 * the steps this reader may take now (the server checks each again).
 */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const route = useRoute();
const router = useRouter();
const record = useResource(() => payroll.settlement(route.params.id));
const settlement = computed(() => record.data.value?.data ?? null);
const sections = computed(() => settlementSections(settlement.value?.lines));
const money = (amount) => formatMoney({ amount, currency: settlement.value?.currency });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const busy = ref(null);

function basis(line) {
    const found = settlementBasis(line);
    if (!found) return line.manual ? t('payroll.settlement.by_hand') : '';
    const params = Object.fromEntries(Object.entries(found.params).map(([key, value]) => {
        if (key === 'principal') return [key, money(value)];
        if (key === 'paid_out_on') return [key, day(value)];
        return [key, typeof value === 'number' ? formatNumber(value) : value];
    }));
    return t(found.key, params);
}

async function step(name, body = {}) {
    busy.value = name;
    try {
        await payroll.settlementStep(settlement.value.id, name, { base_version: settlement.value.version, ...body });
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
        title: t(`payroll.settlement.confirm.${name}_title`, { name: settlement.value.employee_name }),
        message: t(`payroll.run.confirm.${name}_text`, { amount: money(settlement.value.net_minor) }),
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
    const confirmed = await confirmAction({ title: t('payroll.settlement.confirm.delete_title', { name: settlement.value.employee_name }), message: t('payroll.settlement.confirm.delete_text'), confirmLabel: t('payroll.run.steps.delete'), danger: true });
    if (!confirmed) return;
    try {
        await payroll.deleteSettlement(settlement.value.id, settlement.value.version);
        toast.success(t('payroll.run.done.delete'));
        router.push({ name: 'payroll-settlements' });
    } catch (error) {
        toast.error(error.message);
    }
}

// A line by hand.
const adding = ref(false);
const line = reactive({ kind: 'earning', label: '', amount: '', taxable: true });
const lineErrors = ref({});
function addLine() {
    Object.assign(line, { kind: 'earning', label: '', amount: '', taxable: true });
    lineErrors.value = {};
    adding.value = true;
}
async function saveLine() {
    const amount = amountToMinor(line.amount, settlement.value.currency);
    if (!amount) {
        lineErrors.value = { amount_minor: [t('payroll.run.bad_amount')] };
        return;
    }
    busy.value = 'line';
    try {
        await payroll.addSettlementLine(settlement.value.id, { kind: line.kind, label: line.label.trim(), amount_minor: amount, taxable: line.kind === 'earning' && line.taxable });
        adding.value = false;
        record.reload();
    } catch (error) {
        lineErrors.value = error.errors ?? {};
        if (!Object.keys(lineErrors.value).length) toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
async function removeLine(item) {
    try {
        await payroll.removeSettlementLine(settlement.value.id, item.id);
        record.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

async function bankFile() {
    busy.value = 'bank';
    try {
        const { data } = await payroll.settlementBankFile(settlement.value.id);
        const missing = saveBankFile(data, `settlement-${settlement.value.employee_code}.csv`);
        toast.success(missing ? t('payroll.bank.missing', { count: formatNumber(missing) }) : t('payroll.bank.done'));
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'payroll-settlements' }" :icon="ArrowLeft" class="mb-3">{{ t('payroll.settlements.title') }}</AppButton>
        <SkeletonRows v-if="record.loading.value && !settlement" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="settlement">
            <PageHeader :title="settlement.employee_name" :description="t('payroll.settlement.text', { joined: day(settlement.joined_on), left: day(settlement.left_on), years: formatNumber(settlement.service_years), months: formatNumber(settlement.service_months) })">
                <template #eyebrow>
                    <span class="mb-2 flex items-center gap-2">
                        <AppBadge :tone="runTone(settlement.status)" dot>{{ t(`payroll.status.${settlement.status}`) }}</AppBadge>
                        <span class="whitespace-nowrap font-mono text-[12px] text-muted" dir="ltr">{{ settlement.employee_code }}</span>
                    </span>
                </template>
                <template #actions>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="settlement.can.calculate" variant="secondary" :icon="Calculator" :loading="busy === 'calculate'" @click="step('calculate')">{{ t('payroll.run.steps.calculate') }}</AppButton>
                        <AppButton v-if="settlement.can.submit" variant="primary" :icon="Send" @click="confirmStep('submit')">{{ t('payroll.run.steps.submit') }}</AppButton>
                        <AppButton v-if="settlement.can.approve" variant="primary" :icon="Check" @click="confirmStep('approve')">{{ t('payroll.run.steps.approve') }}</AppButton>
                        <AppButton v-if="settlement.can.reject" variant="ghost" :icon="X" @click="confirmStep('reject')">{{ t('payroll.run.steps.reject') }}</AppButton>
                        <AppButton v-if="settlement.can.pay" variant="primary" :icon="Wallet" @click="paidOn = new Date().toISOString().slice(0, 10); paying = true">{{ t('payroll.run.steps.pay') }}</AppButton>
                        <AppButton v-if="settlement.can.bank_file" variant="secondary" :icon="Download" :loading="busy === 'bank'" @click="bankFile">{{ t('payroll.bank.take') }}</AppButton>
                        <AppButton variant="ghost" :icon="Printer" :to="{ name: 'payroll-settlement-print', params: { id: settlement.id } }" :aria-label="t('payroll.slip.print')" />
                        <AppButton v-if="settlement.can.delete" variant="ghost" :icon="Trash2" :aria-label="t('payroll.run.steps.delete')" @click="remove" />
                    </div>
                </template>
            </PageHeader>

            <p v-if="settlement.reject_reason && settlement.status === 'draft'" class="mb-4 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="status">{{ t('payroll.run.sent_back', { reason: settlement.reject_reason }) }}</p>
            <p v-if="settlement.net_minor < 0" class="mb-4 rounded-xl bg-bad-soft px-4 py-3 text-[13px] text-bad" role="alert">{{ t('payroll.settlement.owed', { amount: money(-settlement.net_minor) }) }}</p>

            <section class="card mb-5 grid grid-cols-2 gap-4 p-5 sm:grid-cols-4">
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.earnings') }}</p><p class="tabular text-[18px] font-semibold">{{ money(settlement.earnings_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.deductions') }}</p><p class="tabular text-[18px] font-semibold">{{ money(settlement.deductions_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.tax') }}</p><p class="tabular text-[18px] font-semibold">{{ money(settlement.tax_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.net') }}</p><p class="tabular text-[18px] font-semibold" :class="settlement.net_minor < 0 ? 'text-bad' : 'text-brand-text'">{{ money(settlement.net_minor) }}</p></div>
            </section>

            <section class="card">
                <header class="flex items-center justify-between border-b border-line px-5 py-3">
                    <h2 class="text-[14.5px] font-semibold">{{ t('payroll.settlement.lines') }}</h2>
                    <AppButton v-if="settlement.can.change" size="sm" variant="ghost" :icon="Plus" @click="addLine">{{ t('payroll.settlement.add_line') }}</AppButton>
                </header>
                <p v-if="!settlement.lines.length" class="px-5 py-4 text-[13px] text-muted">{{ t('payroll.settlement.no_lines') }}</p>
                <template v-for="part in ['earnings', 'deductions', 'info']" :key="part">
                    <ul v-if="sections[part].length" class="divide-y divide-line border-b border-line last:border-b-0">
                        <li class="bg-subtle/60 px-5 py-1.5 text-[12px] font-medium text-muted">{{ t(`payroll.settlement.sections.${part}`) }}</li>
                        <li v-for="item in sections[part]" :key="item.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-2.5 text-[13.5px]">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">{{ item.name }}<template v-if="item.taxable"> · <span class="text-[12px] font-normal text-muted">{{ t('payroll.settlement.taxable') }}</span></template></span>
                                <span v-if="basis(item)" class="block text-[12px] text-muted">{{ basis(item) }}</span>
                            </span>
                            <span class="tabular font-semibold" :class="part === 'deductions' ? 'text-bad' : part === 'info' ? 'text-muted' : ''">{{ part === 'deductions' ? '−' : '' }}{{ money(item.amount_minor) }}</span>
                            <AppButton v-if="settlement.can.change && item.manual" size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('payroll.run.remove_adjustment')" @click="removeLine(item)" />
                        </li>
                    </ul>
                </template>
            </section>
        </template>

        <AppDialog :open="adding" :title="t('payroll.settlement.add_line')" :description="t('payroll.settlement.add_line_text')" :icon="Plus" @close="adding = false">
            <form id="payroll-settlement-line" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveLine">
                <AppField v-slot="{ id }" :label="t('payroll.run.kind')">
                    <select :id="id" v-model="line.kind" class="field-input">
                        <option value="earning">{{ t('payroll.kinds.earning') }}</option>
                        <option value="deduction">{{ t('payroll.kinds.deduction') }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.run.amount')" :error="lineErrors.amount_minor?.[0]">
                    <input :id="id" v-model="line.amount" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.run.label')" :error="lineErrors.label?.[0]" class="sm:col-span-2">
                    <input :id="id" v-model="line.label" class="field-input" maxlength="120" :placeholder="t('payroll.settlement.label_hint')" />
                </AppField>
                <label v-if="line.kind === 'earning'" class="flex items-center gap-2 text-[13px] sm:col-span-2"><input v-model="line.taxable" type="checkbox" /> {{ t('payroll.run.taxable') }}</label>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="adding = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-settlement-line" :loading="busy === 'line'" :disabled="!line.label.trim() || !line.amount">{{ t('payroll.common.save') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="paying" :title="t('payroll.run.steps.pay')" :description="t('payroll.run.pay_text', { amount: settlement ? money(settlement.net_minor) : '' })" :icon="Wallet" @close="paying = false">
            <form id="payroll-settlement-pay" novalidate @submit.prevent="pay">
                <AppField v-slot="{ id }" :label="t('payroll.run.paid_on')">
                    <input :id="id" v-model="paidOn" type="date" class="field-input" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="paying = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-settlement-pay" :loading="busy === 'pay'" :disabled="!paidOn">{{ t('payroll.run.steps.pay') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
