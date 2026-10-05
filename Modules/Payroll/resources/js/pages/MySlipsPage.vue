<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronRight, DoorOpen, Gift, HandCoins, PiggyBank, ReceiptText } from 'lucide-vue-next';
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
const fundRecord = useResource(() => (portal ? source.fund() : payroll.myFund()));
const settlementList = useResource(() => (portal ? source.settlements() : payroll.mySettlements()));
const fund = computed(() => fundRecord.data.value?.data ?? null);
const settlements = computed(() => settlementList.data.value?.data ?? []);
const settlementTarget = (item) => (portal ? { name: 'payroll-portal-settlement', params: { id: item.id } } : { name: 'payroll-my-settlement', params: { id: item.id } });
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

        <section v-if="fund && (fund.employee_minor || fund.employer_minor || fund.entries.length)" class="card p-5">
            <div class="flex items-center gap-4">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><PiggyBank class="size-5" aria-hidden="true" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[14.5px] font-semibold">{{ t('payroll.mine.fund') }}</span>
                    <span class="block text-[12.5px] text-muted">{{ t('payroll.fund.shares', { own: formatMoney({ amount: fund.employee_minor, currency: fund.currency }), company: formatMoney({ amount: fund.employer_minor, currency: fund.currency }) }) }}</span>
                </span>
                <span class="tabular text-[16px] font-semibold text-brand-text">{{ formatMoney({ amount: fund.employee_minor + fund.employer_minor, currency: fund.currency }) }}</span>
            </div>
            <ul v-if="fund.entries.length" class="mt-3 divide-y divide-line border-t border-line text-[13px]">
                <li v-for="entry in fund.entries.slice(0, 6)" :key="entry.id" class="flex justify-between gap-3 py-2">
                    <span>{{ entry.period ? monthName(entry.period) : t(`payroll.fund.kinds.${entry.kind}`) }}</span>
                    <span class="tabular" :class="entry.employee_minor + entry.employer_minor < 0 ? 'text-muted' : ''">{{ formatMoney({ amount: entry.employee_minor + entry.employer_minor, currency: entry.currency }) }}</span>
                </li>
            </ul>
        </section>

        <section v-if="settlements.length" class="card">
            <h2 class="border-b border-line px-5 py-3 text-[14.5px] font-semibold">{{ t('payroll.mine.settlements') }}</h2>
            <ul class="divide-y divide-line">
                <li v-for="item in settlements" :key="item.id">
                    <RouterLink :to="settlementTarget(item)" class="flex items-center gap-4 px-5 py-3.5 hover:bg-surface-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-subtle text-fg"><DoorOpen class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14.5px] font-semibold">{{ t('payroll.settlement.title') }}</span>
                            <span class="block text-[12.5px] text-muted">{{ item.paid_on ? t('payroll.mine.paid_on', { date: day(item.paid_on) }) : t('payroll.mine.approved') }}</span>
                        </span>
                        <span class="tabular text-[14.5px] font-semibold">{{ formatMoney({ amount: item.net_minor, currency: item.currency }) }}</span>
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
