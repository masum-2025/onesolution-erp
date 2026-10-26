<script setup>
import { computed, ref, watch } from 'vue';
import { Check, FileText, PenLine, Undo2, Wallet, X } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import PayDialog from './PayDialog.vue';
import { amountText, kmText, monthText, statusTone } from '../lib';

/**
 * One claim: its lines, receipts (each opens through a short signed link,
 * fetched again whenever the claim is opened), the decisions so far and
 * where it stands. Its writer edits a draft or takes it back; people who
 * may decide the next step approve or reject (a reason needed); finance
 * pays it back. The server says what this person may do ("can").
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    claimId: { type: String, default: null },
    // true: the writer's own claim (me/claims); false: as an approver or payer
    own: { type: Boolean, default: false },
    calls: { type: Object, required: true },
    payroll: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'changed', 'edit']);

const claim = ref(null);
const error = ref(null);
const busy = ref(false);
const paying = ref(false);

async function load() {
    error.value = null;
    try {
        claim.value = (await (props.own ? props.calls.myClaim(props.claimId) : props.calls.claim(props.claimId))).data;
    } catch (failure) {
        error.value = failure;
    }
}
watch(() => [props.open, props.claimId], ([open]) => {
    claim.value = null;
    if (open && props.claimId) load();
});

const can = computed(() => claim.value?.can ?? {});
const date = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const receiptsOf = (line) => (claim.value?.receipts ?? []).filter((receipt) => receipt.line_id === line.id);
const loose = computed(() => (claim.value?.receipts ?? []).filter((receipt) => !receipt.line_id || !claim.value.lines.some((line) => line.id === receipt.line_id)));
const where = computed(() => {
    const item = claim.value;
    if (!item) return '';
    if (item.status === 'submitted') return t('expenses.detail.at_step', { step: item.steps_done + 1, steps: item.steps_needed });
    if (item.status === 'approved' && item.pay_method === 'payroll') return t('expenses.detail.with_salary', { month: monthText(item.pay_period) });
    if (item.status === 'approved') return t('expenses.detail.to_pay');
    if (item.status === 'paid') return t(`expenses.detail.paid_${item.pay_method ?? 'cash'}`);
    return '';
});

async function decide(step) {
    const item = claim.value;
    const confirmed = await confirmAction({
        title: t(`expenses.detail.${step}_title`, { number: item.number }),
        message: t('expenses.detail.decide_text', { name: item.employee.name, amount: amountText(item.total_minor, item.currency) }),
        confirmLabel: t(`expenses.detail.${step}`),
        reason: step === 'reject' ? 'required' : 'optional',
        danger: step === 'reject',
    });
    if (!confirmed) return;
    busy.value = true;
    try {
        claim.value = (await props.calls.decide(item.id, step, { base_version: item.version, ...(confirmed.reason ? { note: confirmed.reason } : {}) })).data;
        toast.success(t(`expenses.detail.${step}_done`));
        emit('changed');
    } catch (failure) {
        if (['version_conflict', 'cannot_decide'].includes(failure.code)) load();
        toast.error(failure.message);
    } finally {
        busy.value = false;
    }
}

async function withdraw() {
    const item = claim.value;
    const confirmed = await confirmAction({ title: t('expenses.detail.withdraw_title'), message: t('expenses.detail.withdraw_text', { number: item.number }), confirmLabel: t('expenses.detail.withdraw'), danger: true });
    if (!confirmed) return;
    busy.value = true;
    try {
        claim.value = (await props.calls.mine(item.id, 'withdraw', { base_version: item.version })).data;
        toast.success(t('expenses.detail.withdrawn'));
        emit('changed');
    } catch (failure) {
        if (failure.code === 'version_conflict' || failure.code === 'not_payable') load();
        toast.error(failure.message);
    } finally {
        busy.value = false;
    }
}

function paid() {
    paying.value = false;
    load();
    emit('changed');
}
</script>

<template>
    <AppDrawer :open="open" :title="claim?.number ?? claim?.title ?? t('expenses.detail.title')" width="sm:max-w-[600px]" @close="emit('close')">
        <ErrorState v-if="error" compact :error="error" @retry="load" />
        <SkeletonRows v-else-if="!claim" :rows="4" />
        <div v-else class="space-y-5">
            <header class="space-y-1">
                <p class="text-[15px] font-semibold">{{ claim.title }}</p>
                <p class="text-[13px] text-muted">
                    {{ claim.employee.name }} <span v-if="claim.employee.code" class="font-mono text-[12px]" dir="ltr">{{ claim.employee.code }}</span>
                    <template v-if="claim.submitted_at"> · {{ t('expenses.detail.sent_on', { date: formatDateTime(claim.submitted_at) }) }}</template>
                </p>
                <p class="flex flex-wrap items-center gap-2 pt-1">
                    <AppBadge :tone="statusTone(claim.status)">{{ t(`expenses.status.${claim.status}`) }}</AppBadge>
                    <span v-if="where" class="text-[12.5px] text-muted">{{ where }}</span>
                </p>
            </header>

            <ul class="divide-y divide-line rounded-xl border border-line">
                <li v-for="line in claim.lines" :key="line.id" class="grid gap-1 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <div class="min-w-0">
                        <p class="text-[14px] font-medium">{{ line.description }}</p>
                        <p class="text-[12.5px] text-muted">
                            {{ line.category }} · {{ date(line.spent_on) }}
                            <template v-if="line.mileage"> · {{ t('expenses.common.km', { km: kmText(line.distance_tenths) }) }}</template>
                        </p>
                        <p v-if="receiptsOf(line).length" class="mt-1 flex flex-wrap gap-1.5">
                            <a v-for="receipt in receiptsOf(line)" :key="receipt.id" :href="receipt.url" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1 rounded-lg bg-subtle px-2 py-0.5 text-[12px]">
                                <FileText class="size-3.5 shrink-0" aria-hidden="true" /><span class="truncate">{{ receipt.name }}</span>
                            </a>
                        </p>
                    </div>
                    <p class="tabular text-[14px] font-medium sm:text-end">{{ amountText(line.amount_minor, claim.currency) }}</p>
                </li>
                <li class="flex items-baseline justify-between bg-subtle px-4 py-3">
                    <span class="text-[13.5px] font-medium">{{ t('expenses.editor.total') }}</span>
                    <span class="tabular text-[18px] font-semibold">{{ amountText(claim.total_minor, claim.currency) }}</span>
                </li>
            </ul>
            <p v-if="loose.length" class="flex flex-wrap gap-1.5">
                <a v-for="receipt in loose" :key="receipt.id" :href="receipt.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg bg-subtle px-2 py-0.5 text-[12px]">
                    <FileText class="size-3.5" aria-hidden="true" />{{ receipt.name }}
                </a>
            </p>

            <section v-if="claim.approvals.length" :aria-label="t('expenses.detail.decisions')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('expenses.detail.decisions') }}</h3>
                <ol class="space-y-2">
                    <li v-for="item in claim.approvals" :key="`${item.step}-${item.at}`" class="rounded-lg border border-line px-3 py-2 text-[13px]">
                        <span class="font-medium">{{ t('expenses.detail.step', { step: item.step }) }}: {{ t(`expenses.detail.decision_${item.decision}`) }}</span>
                        · {{ item.by }} · <span class="text-muted">{{ formatDateTime(item.at) }}</span>
                        <p v-if="item.note" class="mt-0.5 text-muted">“{{ item.note }}”</p>
                    </li>
                </ol>
            </section>
        </div>
        <template v-if="claim" #footer>
            <div class="flex w-full flex-wrap justify-end gap-2">
                <template v-if="own">
                    <AppButton v-if="can.withdraw" variant="danger-soft" :icon="Undo2" :loading="busy" @click="withdraw">{{ t('expenses.detail.withdraw') }}</AppButton>
                    <AppButton v-if="can.edit" variant="primary" :icon="PenLine" @click="emit('edit', claim)">{{ t('expenses.detail.edit') }}</AppButton>
                </template>
                <template v-else>
                    <template v-if="can.decide">
                        <AppButton variant="danger-soft" :icon="X" :loading="busy" @click="decide('reject')">{{ t('expenses.detail.reject') }}</AppButton>
                        <AppButton variant="primary" :icon="Check" :loading="busy" @click="decide('approve')">{{ t('expenses.detail.approve') }}</AppButton>
                    </template>
                    <AppButton v-if="can.pay" variant="primary" :icon="Wallet" @click="paying = true">{{ claim.pay_method === 'payroll' ? t('expenses.detail.change_pay') : t('expenses.detail.pay') }}</AppButton>
                </template>
                <AppButton variant="ghost" @click="emit('close')">{{ t('expenses.common.close') }}</AppButton>
            </div>
        </template>
        <PayDialog :open="paying" :claim="claim" :payroll="payroll" :send="(body) => calls.pay(claim.id, body)" @close="paying = false" @paid="paid" />
    </AppDrawer>
</template>
