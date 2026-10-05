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
import { settlementSections } from '../lib';

/** A final settlement, printable on the company's branding: for payroll staff, the person in the app, or in the portal. */
const route = useRoute();
const mode = route.meta.slip;
const record = useResource(() => {
    if (mode === 'portal') return portalPayrollApi.settlement(route.params.id);
    const payroll = payrollApi(currentOrganization().id);
    return mode === 'mine' ? payroll.mySettlement(route.params.id) : payroll.settlement(route.params.id);
});
const settlement = computed(() => record.data.value?.data ?? null);
const sections = computed(() => settlementSections(settlement.value?.lines));
const back = { staff: { name: 'payroll-settlement', params: { id: route.params.id } }, mine: { name: 'payroll-me' }, portal: { name: 'payroll-portal' } }[mode];
const money = (amount) => formatMoney({ amount, currency: settlement.value?.currency });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const printPage = () => window.print();
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <div class="mb-4 flex items-center justify-between print:hidden">
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="back">{{ t('payroll.common.back') }}</AppButton>
            <AppButton v-if="settlement" size="sm" :icon="Printer" @click="printPage">{{ t('payroll.slip.print') }}</AppButton>
        </div>
        <SkeletonRows v-if="record.loading.value && !settlement" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <article v-else-if="settlement" class="card p-6 sm:p-8 print:border-0 print:p-0 print:shadow-none">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-5">
                <div class="flex items-center gap-3">
                    <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-10 w-auto" />
                    <div class="text-[16px] font-semibold">{{ settlement.company ?? brand.name }}</div>
                </div>
                <div class="text-end">
                    <div class="text-[20px] font-semibold tracking-wide text-brand-text">{{ t('payroll.settlement.title') }}</div>
                    <div class="text-[13.5px]">{{ day(settlement.left_on) }}</div>
                </div>
            </header>

            <div class="grid gap-3 py-5 text-[13px] sm:grid-cols-2">
                <div>
                    <div class="text-[12px] text-muted">{{ t('payroll.run.employee') }}</div>
                    <div class="mt-1 text-[15px] font-medium">{{ settlement.employee_name }}</div>
                    <div class="font-mono text-[12px] text-muted" dir="ltr">{{ settlement.employee_code }}</div>
                </div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 sm:justify-self-end">
                    <dt class="text-muted">{{ t('payroll.settlement.joined') }}</dt><dd>{{ day(settlement.joined_on) }}</dd>
                    <dt class="text-muted">{{ t('payroll.settlement.left') }}</dt><dd>{{ day(settlement.left_on) }}</dd>
                    <dt class="text-muted">{{ t('payroll.bonus.service_label') }}</dt><dd class="tabular">{{ t('payroll.settlement.service', { years: formatNumber(settlement.service_years), months: formatNumber(settlement.service_months) }) }}</dd>
                    <template v-if="settlement.paid_on"><dt class="text-muted">{{ t('payroll.slip.paid_on') }}</dt><dd>{{ day(settlement.paid_on) }}</dd></template>
                </dl>
            </div>

            <div class="grid grid-cols-1 gap-6 border-t border-line pt-5 sm:grid-cols-2">
                <section v-for="part in ['earnings', 'deductions']" :key="part">
                    <h2 class="mb-2 text-[13px] font-semibold text-muted">{{ t(part === 'earnings' ? 'payroll.slip.earnings' : 'payroll.slip.deductions') }}</h2>
                    <ul class="divide-y divide-line text-[13.5px]">
                        <li v-for="line in sections[part]" :key="line.id" class="flex justify-between gap-3 py-1.5"><span>{{ line.name }}</span><span class="tabular">{{ money(line.amount_minor) }}</span></li>
                        <li v-if="part === 'deductions' && settlement.tax_minor" class="flex justify-between gap-3 py-1.5"><span>{{ t('payroll.slip.tax') }}</span><span class="tabular">{{ money(settlement.tax_minor) }}</span></li>
                        <li v-if="!sections[part].length && !(part === 'deductions' && settlement.tax_minor)" class="py-1.5 text-muted">—</li>
                    </ul>
                </section>
            </div>

            <div class="mt-6 flex items-center justify-between rounded-xl bg-brand-soft px-5 py-4 print:border print:border-line">
                <span class="text-[14px] font-semibold">{{ t('payroll.slip.net') }}</span>
                <span class="tabular text-[22px] font-semibold text-brand-text">{{ money(settlement.net_minor) }}</span>
            </div>
            <p v-for="line in sections.info" :key="line.id" class="mt-3 text-[12.5px] text-muted">{{ line.name }}: {{ money(line.amount_minor) }}</p>
            <p class="mt-5 text-[11.5px] text-muted">{{ t('payroll.slip.footer') }}</p>
        </article>
    </div>
</template>
