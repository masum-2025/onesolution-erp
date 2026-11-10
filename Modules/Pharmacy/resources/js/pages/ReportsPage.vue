<script setup>
import { computed, ref, watch } from 'vue';
import { ChartColumn } from 'lucide-vue-next';
import { DateInput, PageFrame } from '@/ui';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { pharmacyApi } from '../api';

/** Pharmacy reports: sales per day, and stock by expiry worth at MRP. */
const ph = pharmacyApi(currentOrganization().id);
const kind = ref('sales');
const today = new Date();
const iso = (date) => date.toISOString().slice(0, 10);
const from = ref(iso(new Date(today.getFullYear(), today.getMonth(), 1)));
const to = ref(iso(today));
const report = useResource(() => ph.report(kind.value === 'sales' ? { kind: 'sales', from: from.value, to: to.value } : { kind: 'expiry' }));
watch([kind, from, to], () => report.reload());
const data = computed(() => report.data.value?.data ?? { rows: [] });
const money = (amount) => formatMoney({ amount, currency: data.value.currency });
const kinds = computed(() => [{ value: 'sales', label: t('pharmacy.reports.sales') }, { value: 'expiry', label: t('pharmacy.reports.expiry') }]);
const exported = computed(() => ({
    module: 'pharmacy',
    page: `pharmacy.reports.${kind.value}`,
    columns: kind.value === 'sales'
        ? [
            { key: 'day', label: t('pharmacy.reports.day'), value: (row) => formatDate(row.day) },
            { key: 'sales', label: t('pharmacy.reports.count') },
            { key: 'controlled', label: t('pharmacy.reports.controlled') },
            { key: 'total_minor', label: t('pharmacy.reports.total'), type: 'money' },
        ]
        : [
            { key: 'state', label: t('pharmacy.stock.state'), value: (row) => t(`pharmacy.stock.states.${row.state}`) },
            { key: 'batches', label: t('pharmacy.reports.batches') },
            { key: 'worth_minor', label: t('pharmacy.reports.worth'), type: 'money' },
        ],
    rows: data.value.rows,
}));
</script>

<template>
    <PageFrame :title="t('pharmacy.reports.title')" :description="t('pharmacy.reports.text')" width="lg" :resource="report" :export="exported" guide="pharmacy.guide.reports">
        <template #top>
            <section class="card mb-4 flex flex-wrap items-end gap-3 p-4 print:hidden">
                <AppSegmented v-model="kind" :options="kinds" :label="t('pharmacy.reports.kind')" />
                <template v-if="kind === 'sales'">
                    <label class="grid gap-1 text-[12.5px] text-muted">{{ t('pharmacy.controlled.from') }}<DateInput v-model="from" /></label>
                    <label class="grid gap-1 text-[12.5px] text-muted">{{ t('pharmacy.controlled.to') }}<DateInput v-model="to" /></label>
                </template>
            </section>
        </template>
        <section class="card">
            <EmptyState v-if="!data.rows.length" :icon="ChartColumn" :title="t('pharmacy.reports.empty')" :text="t(`pharmacy.reports.empty_${kind}`)" compact />
            <table v-else class="w-full text-[13.5px]">
                <tbody class="divide-y divide-line">
                    <template v-if="kind === 'sales'">
                        <tr v-for="row in data.rows" :key="row.day">
                            <td class="px-5 py-2.5">{{ formatDate(row.day) }}</td>
                            <td class="px-5 py-2.5 text-muted">{{ t('pharmacy.reports.sales_count', { count: formatNumber(row.sales) }) }}<template v-if="row.controlled"> · {{ t('pharmacy.reports.controlled_count', { count: formatNumber(row.controlled) }) }}</template></td>
                            <td class="tabular px-5 py-2.5 text-end font-semibold">{{ money(row.total_minor) }}</td>
                        </tr>
                        <tr class="bg-subtle font-semibold"><td class="px-5 py-2.5" colspan="2">{{ t('pharmacy.reports.total') }}</td><td class="tabular px-5 py-2.5 text-end">{{ money(data.total_minor) }}</td></tr>
                    </template>
                    <template v-else>
                        <tr v-for="row in data.rows" :key="row.state">
                            <td class="px-5 py-2.5">{{ t(`pharmacy.stock.states.${row.state}`) }}</td>
                            <td class="px-5 py-2.5 text-muted">{{ t('pharmacy.reports.batch_count', { count: formatNumber(row.batches) }) }}</td>
                            <td class="tabular px-5 py-2.5 text-end font-semibold">{{ money(row.worth_minor) }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </section>
    </PageFrame>
</template>
