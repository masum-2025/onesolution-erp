<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { brand } from '@/lib/brand';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { payrollApi, portalPayrollApi } from '../api';

/**
 * One payslip, printable on the company's own branding: earnings,
 * deductions and tax line by line, days worked, and net pay. The same page
 * serves payroll staff, the employee ("my payslips") and the portal.
 */
const route = useRoute();
const org = currentOrganization();
const mode = route.meta.slip;
const record = useResource(() => {
    if (mode === 'portal') return portalPayrollApi.slip(route.params.slip);
    const payroll = payrollApi(org.id);
    return mode === 'mine' ? payroll.mySlip(route.params.slip) : payroll.slip(route.params.run, route.params.slip);
});
const slip = computed(() => record.data.value?.data ?? null);
const back = computed(() => ({ staff: { name: 'payroll-run', params: { id: route.params.run } }, mine: { name: 'payroll-me' }, portal: { name: 'payroll-portal' } })[mode]);

const money = (amount) => formatMoney({ amount, currency: slip.value?.currency });
// Tax at source is listed with the deductions.
const lines = (kind) => (slip.value?.lines ?? []).filter((line) => (kind === 'earning' ? line.kind === 'earning' : ['deduction', 'tax'].includes(line.kind)));
// What the company adds on top (its provident fund share): shown, never paid out on the slip.
const employer = computed(() => (slip.value?.lines ?? []).filter((line) => line.kind === 'employer'));
const printPage = () => window.print();
const monthName = computed(() => (slip.value ? formatDate(`${slip.value.period}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' }) : ''));
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <div class="mb-4 flex items-center justify-between print:hidden">
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="back">{{ t('payroll.common.back') }}</AppButton>
            <AppButton v-if="slip" size="sm" :icon="Printer" @click="printPage">{{ t('payroll.slip.print') }}</AppButton>
        </div>
        <SkeletonRows v-if="record.loading.value && !slip" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <article v-else-if="slip" class="card p-6 sm:p-8 print:border-0 print:p-0 print:shadow-none">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-5">
                <div class="flex items-center gap-3">
                    <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-10 w-auto" />
                    <div class="text-[16px] font-semibold">{{ slip.company ?? brand.name }}</div>
                </div>
                <div class="text-end">
                    <div class="text-[20px] font-semibold tracking-wide text-brand-text">{{ t('payroll.slip.title') }}</div>
                    <div class="text-[13.5px]">{{ monthName }}</div>
                </div>
            </header>

            <div class="grid gap-3 py-5 text-[13px] sm:grid-cols-2">
                <div>
                    <div class="text-[12px] text-muted">{{ t('payroll.run.employee') }}</div>
                    <div class="mt-1 text-[15px] font-medium">{{ slip.employee_name }}</div>
                    <div class="font-mono text-[12px] text-muted" dir="ltr">{{ slip.employee_code }}</div>
                </div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 sm:justify-self-end">
                    <dt class="text-muted">{{ t('payroll.slip.days_paid') }}</dt>
                    <dd class="tabular">{{ formatNumber(slip.employed_days) }}/{{ formatNumber(slip.period_days) }}</dd>
                    <template v-if="slip.absent_days">
                        <dt class="text-muted">{{ t('payroll.slip.absent') }}</dt>
                        <dd class="tabular">{{ formatNumber(slip.absent_days) }}</dd>
                    </template>
                    <template v-if="slip.overtime_minutes">
                        <dt class="text-muted">{{ t('payroll.slip.overtime') }}</dt>
                        <dd class="tabular">{{ t('payroll.slip.hours', { hours: formatNumber(Math.floor(slip.overtime_minutes / 60)), minutes: formatNumber(slip.overtime_minutes % 60) }) }}</dd>
                    </template>
                    <template v-if="slip.paid_on">
                        <dt class="text-muted">{{ t('payroll.slip.paid_on') }}</dt>
                        <dd>{{ formatDate(`${slip.paid_on}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) }}</dd>
                    </template>
                </dl>
            </div>

            <div class="grid grid-cols-1 gap-6 border-t border-line pt-5 sm:grid-cols-2">
                <section v-for="kind in ['earning', 'deduction']" :key="kind">
                    <h2 class="mb-2 text-[13px] font-semibold text-muted">{{ t(kind === 'earning' ? 'payroll.slip.earnings' : 'payroll.slip.deductions') }}</h2>
                    <ul class="divide-y divide-line text-[13.5px]">
                        <li v-for="(line, index) in lines(kind)" :key="index" class="flex justify-between gap-3 py-1.5">
                            <span>{{ line.name }}</span>
                            <span class="tabular">{{ money(line.amount_minor) }}</span>
                        </li>
                        <li v-if="!lines(kind).length" class="py-1.5 text-muted">—</li>
                        <li class="flex justify-between gap-3 py-1.5 font-semibold">
                            <span>{{ t('payroll.slip.total') }}</span>
                            <span class="tabular">{{ money(kind === 'earning' ? slip.earnings_minor : slip.deductions_minor + slip.tax_minor) }}</span>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="mt-6 flex items-center justify-between rounded-xl bg-brand-soft px-5 py-4 print:border print:border-line">
                <span class="text-[14px] font-semibold">{{ t('payroll.slip.net') }}</span>
                <span class="tabular text-[22px] font-semibold text-brand-text">{{ money(slip.net_minor) }}</span>
            </div>
            <div v-if="employer.length" class="mt-4 text-[13px]">
                <h2 class="mb-1 text-[12.5px] font-semibold text-muted">{{ t('payroll.slip.company_adds') }}</h2>
                <p v-for="(line, index) in employer" :key="index" class="flex justify-between gap-3"><span>{{ line.name }}</span><span class="tabular">{{ money(line.amount_minor) }}</span></p>
            </div>
            <p class="mt-5 text-[11.5px] text-muted">{{ t('payroll.slip.footer') }}</p>
        </article>
    </div>
</template>
