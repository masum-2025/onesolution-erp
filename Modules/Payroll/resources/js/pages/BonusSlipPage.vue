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
import { percentText } from '../lib';

/** One's own festival bonus, printable on the company's branding (in the app or the portal). */
const route = useRoute();
const portal = route.meta.slip === 'portal';
const record = useResource(() => (portal ? portalPayrollApi.bonus(route.params.line) : payrollApi(currentOrganization().id).myBonus(route.params.line)));
const line = computed(() => record.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: line.value?.currency });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const printPage = () => window.print();
</script>

<template>
    <div class="mx-auto max-w-2xl">
        <div class="mb-4 flex items-center justify-between print:hidden">
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="{ name: portal ? 'payroll-portal' : 'payroll-me' }">{{ t('payroll.common.back') }}</AppButton>
            <AppButton v-if="line" size="sm" :icon="Printer" @click="printPage">{{ t('payroll.slip.print') }}</AppButton>
        </div>
        <SkeletonRows v-if="record.loading.value && !line" :rows="4" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <article v-else-if="line" class="card p-6 sm:p-8 print:border-0 print:p-0 print:shadow-none">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-5">
                <div class="flex items-center gap-3">
                    <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-10 w-auto" />
                    <div class="text-[16px] font-semibold">{{ line.company ?? brand.name }}</div>
                </div>
                <div class="text-end">
                    <div class="text-[20px] font-semibold tracking-wide text-brand-text">{{ line.title }}</div>
                    <div class="text-[13.5px]">{{ day(line.bonus_on) }}</div>
                </div>
            </header>

            <div class="grid gap-3 py-5 text-[13px] sm:grid-cols-2">
                <div>
                    <div class="text-[12px] text-muted">{{ t('payroll.run.employee') }}</div>
                    <div class="mt-1 text-[15px] font-medium">{{ line.employee_name }}</div>
                    <div class="font-mono text-[12px] text-muted" dir="ltr">{{ line.employee_code }}</div>
                </div>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 sm:justify-self-end">
                    <dt class="text-muted">{{ t('payroll.pay.basic') }}</dt><dd class="tabular">{{ money(line.basic_minor) }}</dd>
                    <dt class="text-muted">{{ t('payroll.bonus.rate') }}</dt><dd class="tabular">{{ percentText(line.rate_bp) }}%</dd>
                    <dt class="text-muted">{{ t('payroll.bonus.service_label') }}</dt><dd class="tabular">{{ t('payroll.bonus.months', { months: formatNumber(line.service_months) }) }}</dd>
                    <template v-if="line.paid_on"><dt class="text-muted">{{ t('payroll.slip.paid_on') }}</dt><dd>{{ day(line.paid_on) }}</dd></template>
                </dl>
            </div>

            <dl class="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1.5 border-t border-line pt-5 text-[14px]">
                <dt>{{ t('payroll.bonus.gross') }}</dt><dd class="tabular text-end">{{ money(line.gross_minor) }}</dd>
                <dt>{{ t('payroll.slip.tax') }}</dt><dd class="tabular text-end">{{ money(line.tax_minor) }}</dd>
            </dl>
            <div class="mt-5 flex items-center justify-between rounded-xl bg-brand-soft px-5 py-4 print:border print:border-line">
                <span class="text-[14px] font-semibold">{{ t('payroll.slip.net') }}</span>
                <span class="tabular text-[22px] font-semibold text-brand-text">{{ money(line.net_minor) }}</span>
            </div>
            <p class="mt-5 text-[11.5px] text-muted">{{ t('payroll.slip.footer') }}</p>
        </article>
    </div>
</template>
