<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Check, PauseCircle, Undo2, X } from 'lucide-vue-next';
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
import { installmentTone, loanTone } from '../lib';

/**
 * One loan or advance: what was lent, what is left, the months recovered,
 * planned, held back and still due; approve or reject it (someone else
 * than who asked), withdraw it, or hold a month back while its payroll is
 * still a draft. The server checks each step again.
 */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const route = useRoute();
const record = useResource(() => payroll.loan(route.params.id));
const loan = computed(() => record.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: loan.value?.currency });
const month = (period) => formatDate(`${period}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const share = computed(() => (loan.value?.principal_minor ? Math.round((loan.value.recovered_minor * 100) / loan.value.principal_minor) : 0));
const busy = ref(null);

async function decide(step) {
    const confirmed = await confirmAction({
        title: t(`payroll.loan.confirm.${step}_title`, { name: loan.value.employee_name }),
        message: t(`payroll.loan.confirm.${step}_text`, { amount: money(loan.value.principal_minor) }),
        confirmLabel: t(`payroll.loan.steps.${step}`),
        reason: step === 'reject' ? 'required' : step === 'approve' ? 'optional' : 'none',
        danger: step !== 'approve',
    });
    if (!confirmed) return;
    busy.value = step;
    try {
        await payroll.loanStep(loan.value.id, step, { base_version: loan.value.version, ...(confirmed.reason ? { note: confirmed.reason } : {}) });
        toast.success(t(`payroll.loan.done.${step}`));
        record.reload();
    } catch (error) {
        if (error.code === 'version_conflict') record.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

// Holding a month back.
const skipping = ref(null);
const reason = ref('');
const skipError = ref(null);
function askSkip(row) {
    skipping.value = row;
    reason.value = '';
    skipError.value = null;
}
async function skip() {
    busy.value = 'skip';
    skipError.value = null;
    try {
        await payroll.skipMonth(loan.value.id, { period: skipping.value.period, reason: reason.value.trim() });
        toast.success(t('payroll.loan.skipped', { month: month(skipping.value.period) }));
        skipping.value = null;
        record.reload();
    } catch (error) {
        skipError.value = error.errors?.reason?.[0] ?? error.message;
    } finally {
        busy.value = null;
    }
}
async function unskip(row) {
    try {
        await payroll.unskipMonth(loan.value.id, row.period);
        toast.success(t('payroll.loan.unskipped', { month: month(row.period) }));
        record.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'payroll-loans' }" :icon="ArrowLeft" class="mb-3">{{ t('payroll.loans.title') }}</AppButton>
        <SkeletonRows v-if="record.loading.value && !loan" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="loan">
            <PageHeader :title="loan.employee_name" :description="t('payroll.loan.text', { kind: t(`payroll.loan_kinds.${loan.kind}`), date: day(loan.paid_out_on) })">
                <template #eyebrow>
                    <span class="mb-2 flex items-center gap-2">
                        <AppBadge :tone="loanTone(loan.status)" dot>{{ t(`payroll.loan_status.${loan.status}`) }}</AppBadge>
                        <span class="font-mono text-[12px] text-muted" dir="ltr">{{ loan.employee_code }}</span>
                    </span>
                </template>
                <template #actions>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="loan.can.approve" variant="primary" :icon="Check" :loading="busy === 'approve'" @click="decide('approve')">{{ t('payroll.loan.steps.approve') }}</AppButton>
                        <AppButton v-if="loan.can.reject" variant="ghost" :icon="X" @click="decide('reject')">{{ t('payroll.loan.steps.reject') }}</AppButton>
                        <AppButton v-if="loan.can.cancel" variant="ghost" :icon="X" :loading="busy === 'cancel'" @click="decide('cancel')">{{ t('payroll.loan.steps.cancel') }}</AppButton>
                    </div>
                </template>
            </PageHeader>

            <p v-if="loan.decision_note" class="mb-4 rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('payroll.loan.note', { note: loan.decision_note }) }}</p>

            <section class="card mb-5 p-5">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div><p class="text-[12.5px] text-muted">{{ t('payroll.loan.principal') }}</p><p class="tabular text-[18px] font-semibold">{{ money(loan.principal_minor) }}</p></div>
                    <div><p class="text-[12.5px] text-muted">{{ t('payroll.loan.installment') }}</p><p class="tabular text-[18px] font-semibold">{{ money(loan.installment_minor) }}</p><p class="text-[12px] text-muted">{{ t('payroll.loan.times', { count: formatNumber(loan.installments) }) }}</p></div>
                    <div><p class="text-[12.5px] text-muted">{{ t('payroll.loan.recovered') }}</p><p class="tabular text-[18px] font-semibold">{{ money(loan.recovered_minor) }}</p></div>
                    <div><p class="text-[12.5px] text-muted">{{ t('payroll.loan.left') }}</p><p class="tabular text-[18px] font-semibold text-brand-text">{{ money(loan.balance_minor) }}</p></div>
                </div>
                <div class="mt-4 h-2 overflow-hidden rounded-full bg-subtle" role="progressbar" :aria-valuenow="share" aria-valuemin="0" aria-valuemax="100" :aria-label="t('payroll.loan.recovered')">
                    <div class="h-full rounded-full bg-ok" :style="{ inlineSize: `${share}%` }" />
                </div>
                <p v-if="loan.reason" class="mt-3 text-[13px] text-muted">{{ t('payroll.pay.reason') }}: {{ loan.reason }}</p>
            </section>

            <section class="card">
                <header class="border-b border-line px-5 py-3">
                    <h2 class="text-[14.5px] font-semibold">{{ t('payroll.loan.schedule') }}</h2>
                    <p class="text-[12.5px] text-muted">{{ t('payroll.loan.schedule_text') }}</p>
                </header>
                <p v-if="!loan.schedule.length" class="px-5 py-4 text-[13px] text-muted">{{ t('payroll.loan.no_schedule') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="row in loan.schedule" :key="row.period" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-2.5 text-[13.5px]">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ month(row.period) }}</span>
                            <span v-if="row.reason" class="block text-[12px] text-muted">{{ row.reason }}</span>
                        </span>
                        <AppBadge :tone="installmentTone(row.status)">{{ t(`payroll.installment_status.${row.status}`) }}</AppBadge>
                        <span class="tabular w-28 text-end">{{ row.status === 'skipped' ? '—' : money(row.amount_minor) }}</span>
                        <span class="w-9">
                            <AppButton v-if="loan.can.skip && ['due', 'planned'].includes(row.status)" size="icon-sm" variant="ghost" :icon="PauseCircle" :aria-label="t('payroll.loan.skip')" @click="askSkip(row)" />
                            <AppButton v-else-if="loan.can.skip && row.status === 'skipped'" size="icon-sm" variant="ghost" :icon="Undo2" :aria-label="t('payroll.loan.unskip')" @click="unskip(row)" />
                        </span>
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="skipping !== null" :title="t('payroll.loan.skip_title', { month: skipping ? month(skipping.period) : '' })" :description="t('payroll.loan.skip_text')" :icon="PauseCircle" @close="skipping = null">
            <form id="payroll-skip" novalidate @submit.prevent="skip">
                <AppField v-slot="{ id }" :label="t('payroll.pay.reason')" :error="skipError">
                    <input :id="id" v-model="reason" class="field-input" maxlength="300" :placeholder="t('payroll.loan.skip_hint')" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="skipping = null">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-skip" :loading="busy === 'skip'" :disabled="reason.trim().length < 5">{{ t('payroll.loan.skip') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
