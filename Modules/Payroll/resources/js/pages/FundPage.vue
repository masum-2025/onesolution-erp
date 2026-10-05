<script setup>
import { computed } from 'vue';
import { PiggyBank } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';

/** The provident fund: what is held for each person (their share and the company's), and the total. */
const payroll = payrollApi(currentOrganization().id);
const list = useResource(() => payroll.fund());
const rows = computed(() => list.data.value?.data ?? []);
const currency = computed(() => list.data.value?.meta?.currency);
const money = (amount) => formatMoney({ amount, currency: currency.value });
const month = (period) => (period ? formatDate(`${period}-01T00:00:00Z`, { month: 'short', year: 'numeric', timeZone: 'UTC' }) : '—');
</script>

<template>
    <div>
        <PageHeader :title="t('payroll.fund.title')" :description="t('payroll.fund.text')" />

        <section v-if="rows.length" class="card mb-5 p-5">
            <p class="text-[12.5px] text-muted">{{ t('payroll.fund.total') }}</p>
            <p class="tabular text-[24px] font-semibold text-brand-text">{{ money(list.data.value?.meta?.total_minor ?? 0) }}</p>
        </section>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="PiggyBank" :title="t('payroll.fund.empty')" :text="t('payroll.fund.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.employee_id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ row.employee_name ?? '—' }} <span class="whitespace-nowrap font-mono text-[12px] text-muted" dir="ltr">{{ row.employee_code }}</span></span>
                        <span class="block text-[12.5px] text-muted">
                            {{ t('payroll.fund.shares', { own: money(row.employee_minor), company: money(row.employer_minor) }) }} · {{ t('payroll.fund.last', { month: month(row.last_period) }) }}
                        </span>
                    </span>
                    <span class="tabular w-36 text-end text-[14px] font-semibold">{{ money(row.employee_minor + row.employer_minor) }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
