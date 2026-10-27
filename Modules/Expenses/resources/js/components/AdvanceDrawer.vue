<script setup>
import { computed, ref, watch } from 'vue';
import { Check, Undo2, Wallet, X } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { advanceTone, amountText, monthOf, monthsFrom, monthText } from '../lib';

/**
 * One advance: what for, how much is still owed, where it stands, its
 * decisions and movements (paid out, settled by claims, handed back, from
 * a salary). Its employee takes it back before anyone decided; those who
 * may decide approve or reject it (never their own); the finance people
 * pay it out and settle what is left. The server says what one may do.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    advanceId: { type: String, default: null },
    // true: one's own (me/advances)
    own: { type: Boolean, default: false },
    payroll: { type: Boolean, default: false },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'changed']);

const item = ref(null);
const error = ref(null);
const busy = ref(false);
const method = ref('cash');
const period = ref(monthOf());
const can = computed(() => item.value?.can ?? {});

async function load() {
    error.value = null;
    try {
        item.value = (await (props.own ? props.calls.myAdvance(props.advanceId) : props.calls.advance(props.advanceId))).data;
    } catch (failure) {
        error.value = failure;
    }
}
watch(() => [props.open, props.advanceId], ([open]) => {
    item.value = null;
    method.value = 'cash';
    if (open && props.advanceId) load();
});

async function act(work, done) {
    busy.value = true;
    try {
        item.value = (await work()).data;
        toast.success(done);
        emit('changed');
    } catch (failure) {
        if (failure.code === 'version_conflict') load();
        toast.error(failure.errors?.period?.[0] ?? failure.errors?.method?.[0] ?? failure.message);
    } finally {
        busy.value = false;
    }
}

async function decide(step) {
    const confirmed = await confirmAction({
        title: t(`expenses.advance.${step}_title`, { number: item.value.number }),
        message: t('expenses.advance.decide_text', { name: item.value.employee.name, amount: amountText(item.value.amount_minor, item.value.currency) }),
        confirmLabel: t(`expenses.advance.${step}`), reason: step === 'reject' ? 'required' : 'optional', danger: step === 'reject',
    });
    if (confirmed) act(() => props.calls.decideAdvance(item.value.id, step, { base_version: item.value.version, ...(confirmed.reason ? { note: confirmed.reason } : {}) }), t(`expenses.advance.${step}_done`));
}
async function withdraw() {
    const confirmed = await confirmAction({ title: t('expenses.advance.withdraw_title'), message: item.value.purpose, confirmLabel: t('expenses.advance.withdraw'), danger: true });
    if (confirmed) act(() => props.calls.withdrawAdvance(item.value.id, { base_version: item.value.version }), t('expenses.advance.withdrawn'));
}
const pay = () => act(() => props.calls.payAdvance(item.value.id, { base_version: item.value.version, method: method.value }), t('expenses.advance.paid'));
const settle = () => act(() => props.calls.settleAdvance(item.value.id, { base_version: item.value.version, method: method.value, ...(method.value === 'payroll' ? { period: period.value } : {}) }),
    t(method.value === 'payroll' ? 'expenses.advance.to_salary' : 'expenses.advance.returned'));

const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const methods = (withSalary) => ['cash', 'bank', ...(withSalary && props.payroll ? ['payroll'] : [])].map((value) => ({ value, label: t(`expenses.pay.${value}`) }));
</script>

<template>
    <AppDrawer :open="open" :title="item?.number ?? t('expenses.advance.title')" width="sm:max-w-[560px]" @close="emit('close')">
        <ErrorState v-if="error" compact class="m-5" :error="error" @retry="load" />
        <SkeletonRows v-else-if="!item" class="px-5 py-5 sm:px-6" :rows="4" />
        <div v-else class="space-y-5 px-5 py-5 sm:px-6">
            <header class="space-y-1">
                <p class="text-[15px] font-semibold">{{ item.purpose }}</p>
                <p class="text-[13px] text-muted">{{ item.employee.name }}<template v-if="item.settle_by"> · {{ t('expenses.advance.settle_by_on', { date: day(item.settle_by) }) }}</template></p>
                <p class="flex flex-wrap items-center gap-2 pt-1">
                    <AppBadge :tone="advanceTone(item.status)">{{ t(`expenses.advance_status.${item.status}`) }}</AppBadge>
                    <AppBadge v-if="item.overdue" tone="bad">{{ t('expenses.advance.overdue') }}</AppBadge>
                    <span v-if="item.recover_period" class="text-[12.5px] text-muted">{{ t('expenses.advance.from_salary', { month: monthText(item.recover_period) }) }}</span>
                </p>
            </header>
            <div class="grid gap-3 sm:grid-cols-2">
                <p class="rounded-xl bg-subtle px-4 py-3"><span class="block text-[12.5px] text-muted">{{ t('expenses.advance.amount_label') }}</span><span class="tabular text-[18px] font-semibold">{{ amountText(item.amount_minor, item.currency) }}</span></p>
                <p class="rounded-xl bg-subtle px-4 py-3"><span class="block text-[12.5px] text-muted">{{ t('expenses.advance.owed') }}</span><span class="tabular text-[18px] font-semibold">{{ amountText(item.outstanding_minor, item.currency) }}</span></p>
            </div>

            <section v-if="item.moves.length" :aria-label="t('expenses.advance.moves')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('expenses.advance.moves') }}</h3>
                <ol class="space-y-1 border-s border-line ps-4 text-[13px]">
                    <li v-for="(move, index) in item.moves" :key="index">
                        <span class="font-medium">{{ t(`expenses.advance.move_${move.kind}`, { number: move.claim_number ?? '' }) }}</span>
                        · <span class="tabular">{{ amountText(move.amount_minor, item.currency) }}</span>
                        <span class="text-muted"> · {{ formatDateTime(move.at) }}</span>
                    </li>
                </ol>
            </section>
            <section v-if="item.approvals.length" :aria-label="t('expenses.detail.decisions')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('expenses.detail.decisions') }}</h3>
                <ol class="space-y-2">
                    <li v-for="entry in item.approvals" :key="entry.step + entry.at" class="rounded-lg border border-line px-3 py-2 text-[13px]">
                        <span class="font-medium">{{ t('expenses.detail.step', { step: entry.step }) }}: {{ t(`expenses.detail.decision_${entry.decision}`) }}</span> · {{ entry.by }}
                        <p v-if="entry.note" class="text-muted">“{{ entry.note }}”</p>
                    </li>
                </ol>
            </section>

            <section v-if="can.pay || can.settle" class="space-y-3 rounded-xl border border-line p-3">
                <p class="text-[13px] font-semibold">{{ can.pay ? t('expenses.advance.pay_title') : t('expenses.advance.settle_title') }}</p>
                <AppSegmented v-model="method" block :label="t('expenses.pay.method')" :options="methods(can.settle)" />
                <AppField v-if="method === 'payroll'" v-slot="{ id }" :label="t('expenses.pay.period')">
                    <select :id="id" v-model="period" class="field-input">
                        <option v-for="month in monthsFrom(monthOf(), 4)" :key="month" :value="month">{{ monthText(month) }}</option>
                    </select>
                </AppField>
                <AppButton variant="primary" :icon="Wallet" :loading="busy" @click="can.pay ? pay() : settle()">
                    {{ can.pay ? t('expenses.advance.pay', { amount: amountText(item.amount_minor, item.currency) }) : t('expenses.advance.settle', { amount: amountText(item.outstanding_minor, item.currency) }) }}
                </AppButton>
            </section>
        </div>
        <template v-if="item && (can.decide || can.withdraw)" #footer>
            <div class="flex w-full flex-wrap justify-end gap-2 border-t border-line px-5 py-3.5 pb-[max(0.875rem,env(safe-area-inset-bottom))] sm:px-6">
                <AppButton v-if="can.withdraw" variant="danger-soft" :icon="Undo2" :loading="busy" @click="withdraw">{{ t('expenses.advance.withdraw') }}</AppButton>
                <template v-if="can.decide">
                    <AppButton variant="danger-soft" :icon="X" :loading="busy" @click="decide('reject')">{{ t('expenses.advance.reject') }}</AppButton>
                    <AppButton variant="primary" :icon="Check" :loading="busy" @click="decide('approve')">{{ t('expenses.advance.approve') }}</AppButton>
                </template>
            </div>
        </template>
    </AppDrawer>
</template>
