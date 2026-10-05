<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronRight, Gift, HandCoins, ReceiptText } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { payrollApi, portalPayrollApi } from '../api';
import { loanTone } from '../lib';

/**
 * The reader's own pay, in the app or the portal: payslips newest first
 * (approved months only), festival bonuses, and loans with what is left.
 */
const route = useRoute();
const portal = route.meta.portal === true;
const source = portal ? portalPayrollApi : null;
const payroll = portal ? null : payrollApi(currentOrganization().id);
const list = useResource(() => (portal ? source.slips() : payroll.mySlips()));
const bonusList = useResource(() => (portal ? source.bonuses() : payroll.myBonuses()));
const loanList = useResource(() => (portal ? source.loans() : payroll.myLoans()));
const slips = computed(() => list.data.value?.data ?? []);
const bonuses = computed(() => bonusList.data.value?.data ?? []);
const loans = computed(() => loanList.data.value?.data ?? []);
const monthName = (value) => formatDate(`${value}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const target = (slip) => (portal ? { name: 'payroll-portal-slip', params: { slip: slip.id } } : { name: 'payroll-my-slip', params: { slip: slip.id } });
const bonusTarget = (line) => (portal ? { name: 'payroll-portal-bonus', params: { line: line.id } } : { name: 'payroll-my-bonus', params: { line: line.id } });
const left = (loan) => (loan.schedule ?? []).filter((row) => ['due', 'planned'].includes(row.status)).length;
</script>

<template>
    <div class="mx-auto max-w-3xl space-y-6">
        <PageHeader :title="t('payroll.mine.title')" :description="t('payroll.mine.text')" />

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!slips.length" :icon="ReceiptText" :title="t('payroll.mine.empty')" :text="t('payroll.mine.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="slip in slips" :key="slip.id">
                    <RouterLink :to="target(slip)" class="flex items-center gap-4 px-5 py-3.5 hover:bg-surface-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><ReceiptText class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14.5px] font-semibold">{{ monthName(slip.period) }}</span>
                            <span class="block text-[12.5px] text-muted">{{ slip.paid_on ? t('payroll.mine.paid_on', { date: day(slip.paid_on) }) : t('payroll.mine.approved') }}</span>
                        </span>
                        <span class="tabular text-[14.5px] font-semibold">{{ formatMoney({ amount: slip.net_minor, currency: slip.currency }) }}</span>
                        <ChevronRight class="size-4 text-muted rtl:rotate-180" aria-hidden="true" />
                    </RouterLink>
                </li>
            </ul>
        </section>

        <section v-if="bonuses.length" class="card">
            <h2 class="border-b border-line px-5 py-3 text-[14.5px] font-semibold">{{ t('payroll.mine.bonuses') }}</h2>
            <ul class="divide-y divide-line">
                <li v-for="line in bonuses" :key="line.id">
                    <RouterLink :to="bonusTarget(line)" class="flex items-center gap-4 px-5 py-3.5 hover:bg-surface-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-ok-soft text-ok"><Gift class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14.5px] font-semibold">{{ line.title }}</span>
                            <span class="block text-[12.5px] text-muted">{{ line.paid_on ? t('payroll.mine.paid_on', { date: day(line.paid_on) }) : day(line.bonus_on) }}</span>
                        </span>
                        <span class="tabular text-[14.5px] font-semibold">{{ formatMoney({ amount: line.net_minor, currency: line.currency }) }}</span>
                        <ChevronRight class="size-4 text-muted rtl:rotate-180" aria-hidden="true" />
                    </RouterLink>
                </li>
            </ul>
        </section>

        <section v-if="loans.length" class="card">
            <h2 class="border-b border-line px-5 py-3 text-[14.5px] font-semibold">{{ t('payroll.mine.loans') }}</h2>
            <ul class="divide-y divide-line">
                <li v-for="loan in loans" :key="loan.id" class="flex items-center gap-4 px-5 py-3.5">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-subtle text-fg"><HandCoins class="size-5" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14.5px] font-semibold">{{ t(`payroll.loan_kinds.${loan.kind}`) }} · {{ formatMoney({ amount: loan.principal_minor, currency: loan.currency }) }}</span>
                        <span class="block text-[12.5px] text-muted">
                            {{ loan.status === 'closed' ? t('payroll.mine.loan_done') : t('payroll.mine.loan_left', { count: formatNumber(left(loan)), amount: formatMoney({ amount: loan.installment_minor, currency: loan.currency }) }) }}
                        </span>
                    </span>
                    <span v-if="loan.status === 'active'" class="tabular text-end text-[13px]">
                        <span class="block font-semibold">{{ formatMoney({ amount: loan.balance_minor, currency: loan.currency }) }}</span>
                        <span class="block text-[11.5px] text-muted">{{ t('payroll.loans.left') }}</span>
                    </span>
                    <AppBadge v-else :tone="loanTone(loan.status)" dot>{{ t(`payroll.loan_status.${loan.status}`) }}</AppBadge>
                </li>
            </ul>
        </section>
    </div>
</template>
