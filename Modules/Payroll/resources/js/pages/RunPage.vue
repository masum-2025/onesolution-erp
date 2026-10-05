<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, ArrowLeft, Calculator, Check, Download, FileText, Plus, Send, Trash2, Wallet, X } from 'lucide-vue-next';
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
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { saveBankFile } from '../bank';
import { amountToMinor, runTone, sortSlips } from '../lib';

/**
 * One month's payroll: totals, each person's slip (problems first), one-off
 * adjustments, and the steps this reader may take now (the server checks
 * each again): calculate, send, approve (each level a different person),
 * send back, mark paid, take the bank file.
 */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const route = useRoute();
const router = useRouter();
const record = useResource(() => payroll.run(route.params.id));
const run = computed(() => record.data.value?.data ?? null);
const slips = computed(() => sortSlips(run.value?.slips));
const names = computed(() => Object.fromEntries((run.value?.slips ?? []).map((slip) => [slip.employee_id, slip.employee_name])));
const busy = ref(null);

const money = (amount) => formatMoney({ amount, currency: run.value?.currency });
const monthName = computed(() => (run.value ? formatDate(`${run.value.period}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' }) : ''));

async function step(name, body = {}, done = null) {
    busy.value = name;
    try {
        const { data } = await payroll.step(run.value.id, name, { base_version: run.value.version, ...body });
        toast.success(done ?? t(`payroll.run.done.${name}`));
        record.reload();
        return data;
    } catch (error) {
        if (error.code === 'version_conflict') record.reload();
        toast.error(error.message);
        return null;
    } finally {
        busy.value = null;
    }
}

async function confirmStep(name) {
    const confirmed = await confirmAction({
        title: t(`payroll.run.confirm.${name}_title`, { month: monthName.value }),
        message: t(`payroll.run.confirm.${name}_text`, { amount: money(run.value.net_minor) }),
        confirmLabel: t(`payroll.run.steps.${name}`),
        reason: name === 'reject' ? 'required' : 'none',
        danger: name === 'reject',
    });
    if (confirmed) await step(name, name === 'reject' ? { reason: confirmed.reason } : {});
}

// Mark paid on a day.
const paying = ref(false);
const paidOn = ref('');
async function pay() {
    if (await step('pay', { paid_on: paidOn.value })) paying.value = false;
}

async function remove() {
    const confirmed = await confirmAction({ title: t('payroll.run.confirm.delete_title', { month: monthName.value }), message: t('payroll.run.confirm.delete_text'), confirmLabel: t('payroll.run.steps.delete'), danger: true });
    if (!confirmed) return;
    try {
        await payroll.deleteRun(run.value.id, run.value.version);
        toast.success(t('payroll.run.done.delete'));
        router.push({ name: 'payroll' });
    } catch (error) {
        toast.error(error.message);
    }
}

// One-off additions and deductions.
const adjusting = ref(false);
const adjustment = reactive({ employee_id: '', kind: 'earning', label: '', amount: '', taxable: true });
const adjustErrors = ref({});
async function addAdjustment() {
    const amount = amountToMinor(adjustment.amount, run.value.currency);
    if (!amount) {
        adjustErrors.value = { amount_minor: [t('payroll.run.bad_amount')] };
        return;
    }
    busy.value = 'adjust';
    adjustErrors.value = {};
    try {
        await payroll.adjust(run.value.id, { employee_id: adjustment.employee_id, kind: adjustment.kind, label: adjustment.label.trim(), amount_minor: amount, taxable: adjustment.taxable });
        toast.success(t('payroll.run.adjusted'));
        adjusting.value = false;
        record.reload();
    } catch (error) {
        adjustErrors.value = error.errors ?? {};
        if (!Object.keys(adjustErrors.value).length) toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
async function dropAdjustment(item) {
    try {
        await payroll.removeAdjustment(run.value.id, item.id);
        record.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

// The bank file: full account numbers (a recent second step), built here as CSV.
async function bankFile() {
    busy.value = 'bank';
    try {
        const { data } = await payroll.bankFile(run.value.id);
        const missing = saveBankFile(data, `payroll-${data.period}.csv`);
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
        <AppButton variant="ghost" size="sm" :to="{ name: 'payroll' }" :icon="ArrowLeft" class="mb-3">{{ t('payroll.runs.title') }}</AppButton>
        <SkeletonRows v-if="record.loading.value && !run" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="run">
            <PageHeader :title="monthName" :description="t('payroll.run.text', { from: formatDate(`${run.period_from}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }), to: formatDate(`${run.period_to}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) })">
                <template #eyebrow>
                    <span class="mb-2 flex items-center gap-2">
                        <AppBadge :tone="runTone(run.status)" dot>{{ t(`payroll.status.${run.status}`) }}</AppBadge>
                        <span v-if="run.status === 'pending_approval'" class="text-[12.5px] text-muted">{{ t('payroll.run.levels', { done: formatNumber(run.approvals), needed: formatNumber(run.approvals_needed) }) }}</span>
                    </span>
                </template>
                <template #actions>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="run.can.calculate" variant="secondary" :icon="Calculator" :loading="busy === 'calculate'" @click="step('calculate')">{{ t('payroll.run.steps.calculate') }}</AppButton>
                        <AppButton v-if="run.can.submit" variant="primary" :icon="Send" :loading="busy === 'submit'" @click="confirmStep('submit')">{{ t('payroll.run.steps.submit') }}</AppButton>
                        <AppButton v-if="run.can.approve" variant="primary" :icon="Check" :loading="busy === 'approve'" @click="confirmStep('approve')">{{ t('payroll.run.steps.approve') }}</AppButton>
                        <AppButton v-if="run.can.reject" variant="ghost" :icon="X" @click="confirmStep('reject')">{{ t('payroll.run.steps.reject') }}</AppButton>
                        <AppButton v-if="run.can.pay" variant="primary" :icon="Wallet" @click="paidOn = new Date().toISOString().slice(0, 10); paying = true">{{ t('payroll.run.steps.pay') }}</AppButton>
                        <AppButton v-if="['approved', 'paid'].includes(run.status) && can('payroll.run')" variant="secondary" :icon="Download" :loading="busy === 'bank'" @click="bankFile">{{ t('payroll.bank.take') }}</AppButton>
                        <AppButton v-if="run.can.delete" variant="ghost" :icon="Trash2" :aria-label="t('payroll.run.steps.delete')" @click="remove" />
                    </div>
                </template>
            </PageHeader>

            <p v-if="run.reject_reason && run.status === 'draft'" class="mb-4 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="status">{{ t('payroll.run.sent_back', { reason: run.reject_reason }) }}</p>
            <p v-if="run.status === 'draft' && !run.calculated_at" class="mb-4 rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted" role="status">{{ t('payroll.run.needs_calculation') }}</p>

            <!-- Totals -->
            <section class="card mb-5 grid grid-cols-2 gap-4 p-5 sm:grid-cols-5">
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.run.people') }}</p><p class="tabular text-[18px] font-semibold">{{ formatNumber(run.employees) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.earnings') }}</p><p class="tabular text-[18px] font-semibold">{{ money(run.earnings_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.deductions') }}</p><p class="tabular text-[18px] font-semibold">{{ money(run.deductions_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.tax') }}</p><p class="tabular text-[18px] font-semibold">{{ money(run.tax_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('payroll.slip.net') }}</p><p class="tabular text-[18px] font-semibold text-brand-text">{{ money(run.net_minor) }}</p></div>
            </section>

            <!-- Slips: phones a list, wider screens the table. -->
            <section class="card mb-5 sm:hidden">
                <p v-if="!slips.length" class="px-5 py-6 text-center text-[13.5px] text-muted">{{ t('payroll.run.no_slips') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="slip in slips" :key="slip.id" :class="slip.problem ? 'bg-bad-soft/40' : ''">
                        <RouterLink :to="{ name: 'payroll-slip', params: { run: run.id, slip: slip.id } }" class="block px-4 py-3">
                            <span class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 truncate text-[14px] font-medium">{{ slip.employee_name }}</span>
                                <span class="tabular shrink-0 text-[14px] font-semibold">{{ money(slip.net_minor) }}</span>
                            </span>
                            <span class="tabular mt-0.5 block text-[12px] text-muted">
                                {{ t('payroll.run.days_short', { days: formatNumber(slip.employed_days), of: formatNumber(slip.period_days) }) }}<template v-if="slip.absent_days"> · {{ t('payroll.run.absent', { count: formatNumber(slip.absent_days) }) }}</template>
                                · {{ t('payroll.slip.deductions') }} {{ money(slip.deductions_minor + slip.tax_minor) }}
                            </span>
                            <span v-if="slip.problem" class="mt-1 flex items-start gap-1 text-[12px] text-bad"><AlertTriangle class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ t(`payroll.problems.${slip.problem}`) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>
            <section class="card mb-5 hidden overflow-x-auto sm:block">
                <table class="w-full min-w-[46rem] text-[13.5px]">
                    <thead class="border-b border-line text-[12px] text-muted">
                        <tr>
                            <th class="px-5 py-2.5 text-start font-medium">{{ t('payroll.run.employee') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('payroll.run.days') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('payroll.slip.earnings') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('payroll.slip.deductions') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('payroll.slip.tax') }}</th>
                            <th class="px-5 py-2.5 text-end font-medium">{{ t('payroll.slip.net') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-if="!slips.length"><td colspan="6" class="px-5 py-6 text-center text-muted">{{ t('payroll.run.no_slips') }}</td></tr>
                        <tr v-for="slip in slips" :key="slip.id" :class="slip.problem ? 'bg-bad-soft/40' : ''">
                            <td class="px-5 py-2.5">
                                <RouterLink :to="{ name: 'payroll-slip', params: { run: run.id, slip: slip.id } }" class="font-medium hover:underline">{{ slip.employee_name }}</RouterLink>
                                <span class="block font-mono text-[11.5px] text-muted" dir="ltr">{{ slip.employee_code }}</span>
                                <span v-if="slip.problem" class="mt-0.5 flex items-center gap-1 text-[12px] text-bad"><AlertTriangle class="size-3.5" aria-hidden="true" />{{ t(`payroll.problems.${slip.problem}`) }}</span>
                            </td>
                            <td class="tabular px-3 py-2.5 text-end text-muted">
                                {{ formatNumber(slip.employed_days) }}/{{ formatNumber(slip.period_days) }}
                                <span v-if="slip.absent_days" class="block text-[11.5px]">{{ t('payroll.run.absent', { count: formatNumber(slip.absent_days) }) }}</span>
                            </td>
                            <td class="tabular px-3 py-2.5 text-end">{{ money(slip.earnings_minor) }}</td>
                            <td class="tabular px-3 py-2.5 text-end">{{ money(slip.deductions_minor) }}</td>
                            <td class="tabular px-3 py-2.5 text-end">{{ money(slip.tax_minor) }}</td>
                            <td class="tabular px-5 py-2.5 text-end font-semibold">{{ money(slip.net_minor) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Adjustments -->
            <section v-if="run.adjustments.length || run.can.adjust" class="card">
                <header class="flex items-center justify-between border-b border-line px-5 py-3">
                    <h2 class="text-[14.5px] font-semibold">{{ t('payroll.run.adjustments') }}</h2>
                    <AppButton v-if="run.can.adjust && slips.length" size="sm" variant="ghost" :icon="Plus" @click="Object.assign(adjustment, { employee_id: slips[0].employee_id, kind: 'earning', label: '', amount: '', taxable: true }); adjustErrors = {}; adjusting = true">
                        {{ t('payroll.run.add_adjustment') }}
                    </AppButton>
                </header>
                <p v-if="!run.adjustments.length" class="px-5 py-4 text-[13px] text-muted">{{ t('payroll.run.no_adjustments') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in run.adjustments" :key="item.id" class="flex items-center gap-3 px-5 py-2.5 text-[13px]">
                        <span class="min-w-0 flex-1">{{ names[item.employee_id] ?? '—' }} · {{ item.label }}</span>
                        <span class="tabular font-medium" :class="item.kind === 'deduction' ? 'text-bad' : 'text-ok'">{{ item.kind === 'deduction' ? '−' : '+' }}{{ money(item.amount_minor) }}</span>
                        <AppButton v-if="run.can.adjust" size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('payroll.run.remove_adjustment')" @click="dropAdjustment(item)" />
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="adjusting" :title="t('payroll.run.add_adjustment')" :icon="FileText" @close="adjusting = false">
            <form id="payroll-adjust" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="addAdjustment">
                <AppField v-slot="{ id }" :label="t('payroll.run.employee')" :error="adjustErrors.employee_id?.[0]" class="sm:col-span-2">
                    <select :id="id" v-model="adjustment.employee_id" class="field-input">
                        <option v-for="slip in slips" :key="slip.employee_id" :value="slip.employee_id">{{ slip.employee_name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.run.kind')">
                    <select :id="id" v-model="adjustment.kind" class="field-input">
                        <option value="earning">{{ t('payroll.kinds.earning') }}</option>
                        <option value="deduction">{{ t('payroll.kinds.deduction') }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.run.amount')" :error="adjustErrors.amount_minor?.[0]">
                    <input :id="id" v-model="adjustment.amount" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.run.label')" :error="adjustErrors.label?.[0]" class="sm:col-span-2">
                    <input :id="id" v-model="adjustment.label" class="field-input" maxlength="120" :placeholder="t('payroll.run.label_hint')" />
                </AppField>
                <label v-if="adjustment.kind === 'earning'" class="flex items-center gap-2 text-[13px] sm:col-span-2"><input v-model="adjustment.taxable" type="checkbox" /> {{ t('payroll.run.taxable') }}</label>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="adjusting = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-adjust" :loading="busy === 'adjust'" :disabled="!adjustment.label.trim() || !adjustment.amount">{{ t('payroll.common.save') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="paying" :title="t('payroll.run.steps.pay')" :description="t('payroll.run.pay_text', { amount: run ? money(run.net_minor) : '' })" :icon="Wallet" @close="paying = false">
            <form id="payroll-pay" novalidate @submit.prevent="pay">
                <AppField v-slot="{ id }" :label="t('payroll.run.paid_on')">
                    <input :id="id" v-model="paidOn" type="date" class="field-input" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="paying = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-pay" :loading="busy === 'pay'" :disabled="!paidOn">{{ t('payroll.run.steps.pay') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
