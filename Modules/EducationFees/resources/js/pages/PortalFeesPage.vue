<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronRight, CircleCheck, CreditCard, FileText, ReceiptText, SearchX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatMoney } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { portalFeesApi } from '../api';
import { billState, newOpId } from '../lib';
import { billTitle, day } from '../labels';

/**
 * A family's fees in the portal: what the student owes, is overdue and
 * holds; unpaid bills first, each opening to what it is for, with a button
 * to pay its balance online; and the receipts of what was paid. Back from
 * the payment page it thanks them while the receipt is confirmed.
 */
const route = useRoute();
const view = useResource(() => portalFeesApi.show(route.params.record));
watch(() => route.params.record, () => view.reload());
const data = computed(() => view.data.value?.data ?? null);
const money = (minor) => formatMoney({ amount: minor ?? 0, currency: data.value?.currency });
const unpaid = computed(() => (data.value?.bills ?? []).filter((bill) => bill.status === 'open'));
const paid = computed(() => (data.value?.bills ?? []).filter((bill) => bill.status !== 'open'));
const backFromPayment = computed(() => Boolean(route.query.paid));

// One bill's details.
const opened = ref(null);
const detail = ref(null);
async function open(bill) {
    opened.value = bill;
    detail.value = null;
    detail.value = (await portalFeesApi.bill(route.params.record, bill.id).catch(() => ({ data: null }))).data;
}

// Paying online: to the gateway's page; the amount is the bill's.
const paying = ref(null);
async function pay(bill) {
    paying.value = bill.id;
    try {
        const { data: started } = await portalFeesApi.pay(route.params.record, bill.id, newOpId());
        toast.info(t('education_fees.portal.paying'));
        window.location.assign(started.checkout_url);
    } catch (error) {
        toast.error(error.message);
        paying.value = null;
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <SkeletonRows v-if="view.loading.value && !data" :rows="8" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('education_fees.portal.not_found')" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />

        <template v-else-if="data">
            <PageHeader :title="t('education_fees.portal.title_of', { name: data.student.name })" :description="[data.student.code, data.institution].filter(Boolean).join(' · ')" />

            <p v-if="backFromPayment" class="mb-4 flex items-start gap-2 rounded-xl bg-ok-soft px-4 py-3 text-[13px] text-fg">
                <CircleCheck class="mt-0.5 size-4 shrink-0 text-ok" aria-hidden="true" />{{ t('education_fees.portal.paid_back') }}
            </p>

            <dl class="mb-5 grid grid-cols-3 gap-2 sm:gap-3">
                <div class="card p-3 sm:p-4">
                    <dt class="text-[12px] text-muted">{{ t('education_fees.portal.owed') }}</dt>
                    <dd class="tabular text-[17px] font-semibold sm:text-[22px]" :class="data.owed_minor ? 'text-fg' : 'text-ok'">{{ money(data.owed_minor) }}</dd>
                </div>
                <div class="card p-3 sm:p-4">
                    <dt class="text-[12px] text-muted">{{ t('education_fees.portal.overdue') }}</dt>
                    <dd class="tabular text-[17px] font-semibold sm:text-[22px]" :class="data.overdue_minor ? 'text-bad' : 'text-fg'">{{ money(data.overdue_minor) }}</dd>
                </div>
                <div class="card p-3 sm:p-4">
                    <dt class="text-[12px] text-muted">{{ t('education_fees.portal.advance') }}</dt>
                    <dd class="tabular text-[17px] font-semibold text-fg sm:text-[22px]">{{ money(data.advance_minor) }}</dd>
                </div>
            </dl>

            <!-- Bills: unpaid first, each with its pay button. -->
            <section class="card mb-5">
                <h2 class="flex items-center gap-2 border-b border-line px-5 py-3 text-[13.5px] font-semibold text-fg"><FileText class="size-4 text-muted" aria-hidden="true" />{{ t('education_fees.portal.bills') }}</h2>
                <EmptyState v-if="!data.bills.length" :icon="FileText" :title="t('education_fees.portal.no_bills')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="bill in [...unpaid, ...paid]" :key="bill.id" class="px-5 py-3">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <button type="button" class="min-w-0 flex-1 basis-full text-start sm:basis-0" @click="open(bill)">
                                <span class="block text-[14px] font-medium text-fg">{{ billTitle(bill) }}</span>
                                <span class="block text-[12.5px] text-muted"><span class="whitespace-nowrap font-mono" dir="ltr">{{ bill.number }}</span> · {{ t('education_fees.counter.due', { date: day(bill.due_date) }) }}</span>
                            </button>
                            <AppBadge :tone="billState(bill).tone" dot>{{ t(`education_fees.bill_states.${billState(bill).key}`) }}</AppBadge>
                            <span class="tabular ms-auto text-[14px] font-semibold text-fg">{{ money(bill.status === 'open' ? bill.balance_minor : bill.total_minor) }}</span>
                        </div>
                        <div v-if="bill.status === 'open'" class="mt-2 flex flex-wrap items-center gap-2">
                            <AppButton v-if="bill.can_pay" size="sm" variant="primary" :icon="CreditCard" :loading="paying === bill.id" :disabled="paying !== null" @click="pay(bill)">
                                {{ t('education_fees.portal.pay', { amount: money(bill.balance_minor) }) }}
                            </AppButton>
                            <span v-else class="text-[12px] text-muted">{{ t('education_fees.portal.pay_off') }}</span>
                            <AppButton size="sm" variant="ghost" :icon-end="ChevronRight" @click="open(bill)">{{ t('education_fees.portal.details') }}</AppButton>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Receipts. -->
            <section class="card">
                <h2 class="flex items-center gap-2 border-b border-line px-5 py-3 text-[13.5px] font-semibold text-fg"><ReceiptText class="size-4 text-muted" aria-hidden="true" />{{ t('education_fees.portal.receipts') }}</h2>
                <p v-if="!data.receipts.length" class="px-5 py-5 text-[13px] text-muted">{{ t('education_fees.portal.no_receipts') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="receipt in data.receipts" :key="receipt.id">
                        <RouterLink :to="{ name: 'fees-portal-receipt', params: { record: route.params.record, id: receipt.id } }" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                            <span class="min-w-0 flex-1">
                                <span class="block font-mono text-[13.5px] font-medium text-fg" dir="ltr">{{ receipt.number }}</span>
                                <span class="block text-[12.5px] text-muted">{{ day(receipt.received_on) }} · {{ t(`education_fees.methods.${receipt.method}`) }}</span>
                            </span>
                            <AppBadge v-if="receipt.status === 'voided'" tone="bad">{{ t('education_fees.receipt.voided') }}</AppBadge>
                            <span class="tabular text-[14px] font-semibold" :class="receipt.status === 'voided' ? 'text-faint line-through' : 'text-fg'">{{ money(receipt.amount_minor) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </template>

        <!-- What a bill is for. -->
        <AppDialog :open="opened !== null" :title="opened ? billTitle(opened) : ''" :description="opened?.number ?? ''" :icon="FileText" @close="opened = null">
            <SkeletonRows v-if="!detail" :rows="3" />
            <div v-else class="space-y-3 text-[13.5px]">
                <ul class="divide-y divide-line rounded-xl border border-line">
                    <li v-for="(line, index) in detail.lines" :key="index" class="flex items-start justify-between gap-3 px-3 py-2">
                        <span>
                            <span class="block font-medium text-fg">{{ textIn(line.head.name) || line.head.code }}</span>
                            <span v-if="line.discount_minor" class="block text-[12px] text-muted">{{ money(line.amount_minor) }} − {{ money(line.discount_minor) }}</span>
                        </span>
                        <span class="tabular font-medium">{{ money(line.due_minor) }}</span>
                    </li>
                    <li v-for="(fine, index) in detail.fines" :key="`f${index}`" class="flex justify-between gap-3 px-3 py-2 text-muted">
                        <span>{{ fine.kind === 'fine' ? t('education_fees.portal.fines') : t('education_fees.portal.waived') }} · {{ day(fine.applied_on) }}</span>
                        <span class="tabular">{{ money(fine.amount_minor) }}</span>
                    </li>
                </ul>
                <dl class="space-y-1">
                    <div class="flex justify-between font-semibold"><dt>{{ t('education_fees.bill.total') }}</dt><dd class="tabular">{{ money(detail.total_minor) }}</dd></div>
                    <div v-for="(payment, index) in detail.payments" :key="`p${index}`" class="flex justify-between text-[12.5px] text-muted">
                        <dt>{{ t('education_fees.portal.payments') }}<template v-if="payment.number"> · <span class="font-mono" dir="ltr">{{ payment.number }}</span></template> · {{ day(payment.on) }}</dt>
                        <dd class="tabular">−{{ money(payment.amount_minor) }}</dd>
                    </div>
                    <div class="flex justify-between font-semibold"><dt>{{ t('education_fees.bill.balance') }}</dt><dd class="tabular" :class="detail.balance_minor ? 'text-fg' : 'text-ok'">{{ money(detail.balance_minor) }}</dd></div>
                </dl>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="opened = null">{{ t('education_fees.close') }}</AppButton>
                <AppButton v-if="detail?.can_pay" variant="primary" :icon="CreditCard" :loading="paying === detail.id" @click="pay(detail)">{{ t('education_fees.portal.pay', { amount: money(detail.balance_minor) }) }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
