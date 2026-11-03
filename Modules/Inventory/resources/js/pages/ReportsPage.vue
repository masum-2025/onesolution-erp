<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { BarChart3 } from 'lucide-vue-next';
import { DateInput, PageFrame } from '@/ui';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import { formatQuantity } from '../lib';

/**
 * Stock reports for the warehouses this unit sees: value on a day, what to
 * reorder (printable as a shopping list), slow movers, and one item's
 * ledger with its running balance. Each saves as CSV. Filters live in the
 * address, so a report can be bookmarked or shared with a colleague.
 */
const REPORTS = ['valuation', 'reorder', 'slow', 'ledger'];
const inventory = inventoryApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const report = ref(REPORTS.includes(route.query.report) ? route.query.report : 'valuation');
const filters = ref({
    warehouse_id: route.query.warehouse_id ?? '', as_of: route.query.as_of ?? '', days: route.query.days ?? '60',
    item_id: route.query.item_id ?? '', from: route.query.from ?? '', to: route.query.to ?? '',
});

const items = useResource(() => inventory.list('items'));
const warehouses = useResource(() => inventory.list('warehouses'));
const itemOf = (id) => (items.data.value?.data ?? []).find((item) => item.id === id);
const warehouseOf = (id) => (warehouses.data.value?.data ?? []).find((warehouse) => warehouse.id === id);
const stockItems = computed(() => (items.data.value?.data ?? []).filter((item) => item.kind === 'stock'));

function query() {
    const wanted = { warehouse_id: filters.value.warehouse_id };
    if (report.value === 'valuation') wanted.as_of = filters.value.as_of;
    if (report.value === 'slow') wanted.days = filters.value.days;
    if (report.value === 'ledger') Object.assign(wanted, { item_id: filters.value.item_id, from: filters.value.from, to: filters.value.to });
    return Object.fromEntries(Object.entries(wanted).filter(([, value]) => value !== '' && value !== null && value !== undefined));
}
const ready = computed(() => report.value !== 'ledger' || filters.value.item_id !== '');
const result = useResource(() => (ready.value ? inventory.report(report.value, query()) : Promise.resolve({ data: [], meta: {} })));
watch([report, filters], () => {
    router.replace({ query: { report: report.value, ...query() } });
    result.reload();
}, { deep: true });

const rows = computed(() => result.data.value?.data ?? []);
const meta = computed(() => result.data.value?.meta ?? {});
const money = (amount) => formatMoney({ amount, currency: meta.value.currency });
const day = (date) => (date ? formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) : '—');
const total = computed(() => rows.value.reduce((sum, row) => sum + (row.value_minor ?? 0), 0));
// What the reorder list would cost at today's average cost (an estimate; the supplier's price decides).
const orderTotal = computed(() => rows.value.reduce((sum, row) => sum + Math.floor((row.order_milli * row.unit_cost_minor + 500) / 1000), 0));
const kindLabel = (kind) => t(`inventory.reports.kinds.${kind}`);

// Export and print: the report shown, as its table reads (the total as the last row).
const exported = computed(() => {
    if (!ready.value || !rows.value.length) return null;
    const cur = meta.value.currency;
    const quantity = (key, label) => ({ key, label, type: 'number', value: (row) => (row[key] === null || row[key] === undefined ? null : row[key] / 1000) });
    const columns = report.value === 'ledger'
        ? [
            { key: 'moved_on', label: t('inventory.reports.columns.date'), type: 'date' },
            { key: 'kind', label: t('inventory.reports.columns.kind'), value: (row) => (row.kind ? kindLabel(row.kind) : '') },
            { key: 'warehouse', label: t('inventory.reports.columns.warehouse'), value: (row) => warehouseOf(row.warehouse_id)?.name ?? '' },
            quantity('quantity_milli', t('inventory.reports.columns.quantity')),
            { key: 'value_minor', label: t('inventory.reports.columns.value'), type: 'money', currency: cur },
            quantity('balance_milli', t('inventory.reports.columns.balance')),
            { key: 'balance_minor', label: t('inventory.reports.columns.balance_value'), type: 'money', currency: cur },
        ]
        : [
            { key: 'item', label: t('inventory.reports.columns.item'), value: (row) => (row.item_id ? itemOf(row.item_id)?.name ?? '' : row.item) },
            { key: 'sku', label: t('inventory.export.sku'), value: (row) => (row.item_id ? itemOf(row.item_id)?.sku ?? '' : '') },
            { key: 'warehouse', label: t('inventory.reports.columns.warehouse'), value: (row) => (row.warehouse_id ? warehouseOf(row.warehouse_id)?.name ?? '' : '') },
            quantity('quantity_milli', t('inventory.reports.columns.quantity')),
            ...(report.value === 'reorder'
                ? [
                    quantity('reorder_level_milli', t('inventory.reports.columns.level')),
                    quantity('order_milli', t('inventory.reports.columns.order')),
                    { key: 'unit_cost_minor', label: t('inventory.reports.columns.cost'), type: 'money', currency: cur },
                    { key: 'order_value_minor', label: t('inventory.reports.columns.value'), type: 'money', currency: cur },
                ]
                : [{ key: 'value_minor', label: t('inventory.reports.columns.value'), type: 'money', currency: cur }]),
            ...(report.value === 'slow'
                ? [{ key: 'last_out_on', label: t('inventory.reports.columns.last_out'), type: 'date' }, { key: 'idle_days', label: t('inventory.reports.columns.idle_days'), type: 'number' }]
                : []),
        ];
    const lines = report.value === 'reorder'
        ? rows.value.map((row) => ({ ...row, order_value_minor: Math.floor((row.order_milli * row.unit_cost_minor + 500) / 1000) }))
        : rows.value;
    const totals = report.value === 'ledger'
        ? []
        : [{ item: t(report.value === 'reorder' ? 'inventory.reports.order_total' : 'inventory.reports.total'), value_minor: total.value, order_value_minor: orderTotal.value }];
    return { module: 'inventory', page: `inventory.report.${report.value}`, columns, rows: [...lines, ...totals] };
});
</script>

<template>
    <PageFrame :title="t('inventory.reports.title')" :description="t(`inventory.reports.help.${report}`)" width="full" :export="exported">

        <section class="card mb-5 grid gap-4 p-5 print:hidden">
            <AppSegmented v-model="report" size="sm" :label="t('inventory.reports.title')" :options="REPORTS.map((value) => ({ value, label: t(`inventory.reports.names.${value}`) }))" />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <AppField v-slot="{ id }" :label="t('inventory.common.warehouse')">
                    <select :id="id" v-model="filters.warehouse_id" class="field-input">
                        <option value="">{{ t('inventory.reports.all_warehouses') }}</option>
                        <option v-for="warehouse in warehouses.data.value?.data ?? []" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                    </select>
                </AppField>
                <AppField v-if="report === 'valuation'" v-slot="{ id }" :label="t('inventory.reports.as_of')" :hint="t('inventory.reports.as_of_hint')">
                    <DateInput :id="id" v-model="filters.as_of" :max="meta.today" />
                </AppField>
                <AppField v-if="report === 'slow'" v-slot="{ id }" :label="t('inventory.reports.days')">
                    <select :id="id" v-model="filters.days" class="field-input">
                        <option v-for="days in ['30', '60', '90', '180', '365']" :key="days" :value="days">{{ t('inventory.reports.days_option', { days: formatNumber(Number(days)) }) }}</option>
                    </select>
                </AppField>
                <template v-if="report === 'ledger'">
                    <AppField v-slot="{ id }" :label="t('inventory.reports.item')">
                        <select :id="id" v-model="filters.item_id" class="field-input">
                            <option value="" disabled>{{ t('inventory.reports.choose_item') }}</option>
                            <option v-for="item in stockItems" :key="item.id" :value="item.id">{{ item.sku }} · {{ item.name }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('inventory.reports.from')">
                        <DateInput :id="id" v-model="filters.from" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('inventory.reports.to')">
                        <DateInput :id="id" v-model="filters.to" />
                    </AppField>
                </template>
            </div>
        </section>

        <section class="card">
            <EmptyState v-if="!ready" :icon="BarChart3" :title="t('inventory.reports.pick_item')" compact />
            <SkeletonRows v-else-if="result.loading.value && !result.data.value" :rows="6" />
            <ErrorState v-else-if="result.error.value" compact :error="result.error.value" @retry="result.reload()" />
            <template v-else>
                <!-- Ledger: opening and closing around the moves. -->
                <div v-if="report === 'ledger'" class="flex flex-wrap justify-between gap-2 border-b border-line px-5 py-3 text-[13px]">
                    <span>{{ t('inventory.reports.opening', { date: day(meta.from) }) }}: <b class="tabular">{{ formatQuantity(meta.opening?.quantity_milli) }}</b> · {{ money(meta.opening?.value_minor ?? 0) }}</span>
                    <span>{{ t('inventory.reports.closing', { date: day(meta.to) }) }}: <b class="tabular">{{ formatQuantity(meta.closing?.quantity_milli) }}</b> · {{ money(meta.closing?.value_minor ?? 0) }}</span>
                </div>
                <EmptyState v-if="!rows.length" :icon="BarChart3" :title="t(`inventory.reports.empty.${report}`)" compact />
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-[13.5px]">
                        <thead class="text-start text-[12px] text-muted">
                            <tr class="border-b border-line">
                                <template v-if="report === 'ledger'">
                                    <th class="px-5 py-2 text-start font-medium">{{ t('inventory.reports.columns.date') }}</th>
                                    <th class="px-3 py-2 text-start font-medium">{{ t('inventory.reports.columns.kind') }}</th>
                                    <th class="px-3 py-2 text-start font-medium">{{ t('inventory.reports.columns.warehouse') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('inventory.reports.columns.quantity') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('inventory.reports.columns.value') }}</th>
                                    <th class="px-5 py-2 text-end font-medium">{{ t('inventory.reports.columns.balance') }}</th>
                                </template>
                                <template v-else>
                                    <th class="px-5 py-2 text-start font-medium">{{ t('inventory.reports.columns.item') }}</th>
                                    <th class="px-3 py-2 text-start font-medium">{{ t('inventory.reports.columns.warehouse') }}</th>
                                    <th class="px-3 py-2 text-end font-medium">{{ t('inventory.reports.columns.quantity') }}</th>
                                    <th v-if="report === 'reorder'" class="px-3 py-2 text-end font-medium">{{ t('inventory.reports.columns.level') }}</th>
                                    <th v-if="report === 'reorder'" class="px-5 py-2 text-end font-medium">{{ t('inventory.reports.columns.order') }}</th>
                                    <th v-if="report !== 'reorder'" class="px-3 py-2 text-end font-medium">{{ t('inventory.reports.columns.value') }}</th>
                                    <th v-if="report === 'slow'" class="px-5 py-2 text-end font-medium">{{ t('inventory.reports.columns.last_out') }}</th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in rows" :key="row.id ?? `${row.item_id}-${row.warehouse_id}`">
                                <template v-if="report === 'ledger'">
                                    <td class="px-5 py-2 whitespace-nowrap">{{ day(row.moved_on) }}</td>
                                    <td class="px-3 py-2">{{ kindLabel(row.kind) }}</td>
                                    <td class="px-3 py-2">{{ warehouseOf(row.warehouse_id)?.name ?? '—' }}</td>
                                    <td class="tabular px-3 py-2 text-end" :class="row.quantity_milli < 0 ? 'text-bad' : 'text-ok'">{{ row.quantity_milli > 0 ? '+' : '' }}{{ formatQuantity(row.quantity_milli) }}</td>
                                    <td class="tabular px-3 py-2 text-end">{{ money(row.value_minor) }}</td>
                                    <td class="tabular px-5 py-2 text-end font-medium">{{ formatQuantity(row.balance_milli) }}</td>
                                </template>
                                <template v-else>
                                    <td class="px-5 py-2">
                                        <RouterLink :to="{ name: 'inventory-item', params: { id: row.item_id } }" class="font-medium hover:underline">{{ itemOf(row.item_id)?.name ?? '—' }}</RouterLink>
                                        <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ itemOf(row.item_id)?.sku }}</span>
                                    </td>
                                    <td class="px-3 py-2">{{ warehouseOf(row.warehouse_id)?.name ?? '—' }}</td>
                                    <td class="tabular px-3 py-2 text-end">{{ formatQuantity(row.quantity_milli) }}</td>
                                    <td v-if="report === 'reorder'" class="tabular px-3 py-2 text-end text-muted">{{ formatQuantity(row.reorder_level_milli) }}</td>
                                    <td v-if="report === 'reorder'" class="tabular px-5 py-2 text-end font-semibold">{{ formatQuantity(row.order_milli) }}</td>
                                    <td v-if="report !== 'reorder'" class="tabular px-3 py-2 text-end">{{ money(row.value_minor) }}</td>
                                    <td v-if="report === 'slow'" class="px-5 py-2 text-end text-[12.5px]">{{ row.last_out_on ? t('inventory.reports.idle', { date: day(row.last_out_on), days: formatNumber(row.idle_days) }) : t('inventory.reports.never_out') }}</td>
                                </template>
                            </tr>
                        </tbody>
                        <tfoot v-if="report !== 'ledger'" class="border-t border-line font-semibold">
                            <tr>
                                <td class="px-5 py-3" :colspan="report === 'reorder' ? 4 : 3">{{ t(report === 'reorder' ? 'inventory.reports.order_total' : 'inventory.reports.total') }}</td>
                                <td class="tabular px-5 py-3 text-end" :colspan="report === 'slow' ? 2 : 1">{{ money(report === 'reorder' ? orderTotal : total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </template>
        </section>
    </PageFrame>
</template>
