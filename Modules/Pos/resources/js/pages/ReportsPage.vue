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
import { posApi } from '../api';
import { formatQuantity, reportRange } from '../lib';

/**
 * Takings at the counters for supervisors and managers: today, the last 7
 * or 30 days, this month, or chosen days; one counter or all. Totals on
 * top, then by day, hour, cashier, payment method and item, each with a
 * bar for its share. Export and print through the page's menu.
 */
const pos = posApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const registers = useResource(() => pos.registers());
const today = new Date().toISOString().slice(0, 10);
const preset = ref(route.query.preset ?? (route.query.from ? 'custom' : 'today'));
const custom = ref({ from: route.query.from ?? today, to: route.query.to ?? today });
const registerId = ref(route.query.register_id ?? '');
const range = computed(() => (preset.value === 'custom' ? custom.value : reportRange(preset.value, today)));
const report = useResource(() => pos.report({ ...range.value, ...(registerId.value ? { register_id: registerId.value } : {}) }));
watch([preset, custom, registerId], () => {
    router.replace({ query: { preset: preset.value, ...(preset.value === 'custom' ? custom.value : {}), ...(registerId.value ? { register_id: registerId.value } : {}) } });
    report.reload();
}, { deep: true });

const data = computed(() => report.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: data.value?.currency });
const day = (date) => formatDate(`${date}T00:00:00Z`, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
const tiles = computed(() => {
    const totals = data.value?.totals;
    if (!totals) return [];
    return [
        { key: 'net', value: money(totals.net), note: t('pos.reports.receipts', { count: formatNumber(totals.sales_count) }) },
        { key: 'average', value: money(totals.average) },
        { key: 'returns', value: money(totals.returns), note: t('pos.reports.receipts', { count: formatNumber(totals.returns_count) }) },
        { key: 'tax', value: money(totals.tax) },
        { key: 'discount', value: money(totals.discount) },
        { key: 'margin', value: money(totals.margin), note: totals.net - totals.tax > 0 ? t('pos.reports.margin_share', { percent: formatNumber(Math.round((totals.margin * 100) / (totals.net - totals.tax))) }) : null },
    ];
});
const busiest = (rows) => Math.max(1, ...rows.map((row) => Math.abs(row.amount)));
const share = (amount, rows) => `${Math.max(2, Math.round((Math.abs(amount) * 100) / busiest(rows)))}%`;
const hours = computed(() => (data.value?.hours ?? []).filter((row) => row.count > 0 || row.amount !== 0));
const registerName = (id) => (registers.data.value?.data ?? []).find((row) => row.id === id)?.name ?? '';

// Export and print (audited, by the company's export rules): the whole report as one table,
// each row named by its part (totals, by day, cashier, payment method, item).
const exported = computed(() => {
    const report = data.value;
    if (!report) return null;
    const part = (key, rows) => rows.map((row) => ({ part: t(key), ...row }));
    return {
        module: 'pos',
        page: 'pos.takings',
        columns: [
            { key: 'part', label: t('pos.export.part') },
            { key: 'name', label: t('pos.export.name') },
            { key: 'sku', label: 'SKU' },
            { key: 'quantity', label: t('pos.reports.columns.quantity'), type: 'number' },
            { key: 'count', label: t('pos.reports.columns.receipts'), type: 'number' },
            { key: 'returns', label: t('pos.reports.columns.returns'), type: 'number' },
            { key: 'amount', label: t('pos.reports.columns.amount'), type: 'money', currency: report.currency },
            { key: 'margin', label: t('pos.reports.columns.margin'), type: 'money', currency: report.currency },
        ],
        rows: [
            ...part('pos.export.totals', tiles.value.map((tile) => ({ name: t(`pos.reports.tiles.${tile.key}`), amount: report.totals[tile.key] }))),
            ...part('pos.reports.by_day', report.days.map((row) => ({ name: row.date, amount: row.amount, count: row.count }))),
            ...part('pos.reports.by_cashier', report.cashiers.map((row) => ({ name: row.name ?? '', amount: row.amount, count: row.count, returns: row.returns }))),
            ...part('pos.reports.by_method', report.methods.map((row) => ({ name: t(`pos.methods.${row.method}`), amount: row.amount }))),
            ...part('pos.reports.by_item', report.items.map((row) => ({ name: row.name, sku: row.sku, quantity: row.quantity_milli / 1000, amount: row.amount, margin: row.net - row.cost }))),
        ],
    };
});
</script>

<template>
    <PageFrame :title="t('pos.reports.title')" :description="t('pos.reports.text')" width="full" :export="exported">
        <section class="card mb-5 grid gap-4 p-5 print:hidden">
            <AppSegmented v-model="preset" size="sm" :label="t('pos.reports.period')" :options="['today', 'week', 'month30', 'this_month', 'custom'].map((value) => ({ value, label: t(`pos.reports.presets.${value}`) }))" />
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <template v-if="preset === 'custom'">
                    <AppField v-slot="{ id }" :label="t('pos.reports.from')">
                        <DateInput :id="id" v-model="custom.from" :max="today" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('pos.reports.to')">
                        <DateInput :id="id" v-model="custom.to" :max="today" />
                    </AppField>
                </template>
                <AppField v-slot="{ id }" :label="t('pos.till.counter')">
                    <select :id="id" v-model="registerId" class="field-input">
                        <option value="">{{ t('pos.reports.all_counters') }}</option>
                        <option v-for="row in registers.data.value?.data ?? []" :key="row.id" :value="row.id">{{ row.name }}</option>
                    </select>
                </AppField>
            </div>
        </section>

        <SkeletonRows v-if="report.loading.value && !data" :rows="8" />
        <ErrorState v-else-if="report.error.value" :error="report.error.value" @retry="report.reload()" />
        <EmptyState v-else-if="data && !data.totals.sales_count && !data.totals.returns_count" :icon="BarChart3" :title="t('pos.reports.empty')" :text="t('pos.reports.empty_text')" />
        <template v-else-if="data">
            <!-- Totals -->
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <div v-for="tile in tiles" :key="tile.key" class="card p-4">
                    <p class="text-[12px] text-muted">{{ t(`pos.reports.tiles.${tile.key}`) }}</p>
                    <p class="tabular mt-1 text-[18px] font-semibold" :class="tile.key === 'returns' && data.totals.returns ? 'text-bad' : ''">{{ tile.value }}</p>
                    <p v-if="tile.note" class="mt-0.5 text-[12px] text-muted">{{ tile.note }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
                <!-- By day -->
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('pos.reports.by_day') }}</h2>
                    <ul class="divide-y divide-line">
                        <li v-for="row in data.days" :key="row.date" class="grid grid-cols-[7rem_1fr_auto] items-center gap-3 px-5 py-2 text-[13px]">
                            <span>{{ day(row.date) }}</span>
                            <span class="h-2 rounded-full bg-subtle" aria-hidden="true"><span class="block h-2 rounded-full bg-brand" :style="{ inlineSize: share(row.amount, data.days) }" /></span>
                            <span class="tabular w-28 text-end font-medium">{{ money(row.amount) }}</span>
                        </li>
                    </ul>
                </section>

                <!-- By hour -->
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('pos.reports.by_hour') }}</h2>
                    <ul class="divide-y divide-line">
                        <li v-for="row in hours" :key="row.hour" class="grid grid-cols-[7rem_1fr_auto] items-center gap-3 px-5 py-2 text-[13px]">
                            <span class="tabular">{{ t('pos.reports.hour', { from: formatNumber(row.hour), to: formatNumber((row.hour + 1) % 24) }) }}</span>
                            <span class="h-2 rounded-full bg-subtle" aria-hidden="true"><span class="block h-2 rounded-full bg-brand" :style="{ inlineSize: share(row.amount, hours) }" /></span>
                            <span class="tabular w-28 text-end"><b>{{ money(row.amount) }}</b> <span class="text-muted">· {{ formatNumber(row.count) }}</span></span>
                        </li>
                    </ul>
                </section>

                <!-- By cashier -->
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('pos.reports.by_cashier') }}</h2>
                    <ul class="divide-y divide-line">
                        <li v-for="row in data.cashiers" :key="row.user_id" class="flex items-center gap-3 px-5 py-2.5 text-[13px]">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">{{ row.name ?? '—' }}</span>
                                <span class="block text-[12px] text-muted">{{ t('pos.reports.receipts', { count: formatNumber(row.count) }) }}<template v-if="row.returns"> · {{ t('pos.reports.returns_count', { count: formatNumber(row.returns) }) }}</template></span>
                            </span>
                            <span class="tabular font-semibold">{{ money(row.amount) }}</span>
                        </li>
                    </ul>
                </section>

                <!-- By payment method -->
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('pos.reports.by_method') }}</h2>
                    <ul class="divide-y divide-line">
                        <li v-for="row in data.methods" :key="row.method" class="grid grid-cols-[8rem_1fr_auto] items-center gap-3 px-5 py-2.5 text-[13px]">
                            <span>{{ t(`pos.methods.${row.method}`) }}</span>
                            <span class="h-2 rounded-full bg-subtle" aria-hidden="true"><span class="block h-2 rounded-full bg-brand" :style="{ inlineSize: share(row.amount, data.methods) }" /></span>
                            <span class="tabular w-28 text-end font-medium">{{ money(row.amount) }}</span>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- By item -->
            <section class="card mt-5">
                <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('pos.reports.by_item') }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-[13px]">
                        <thead class="text-[12px] text-muted">
                            <tr class="border-b border-line">
                                <th class="px-5 py-2 text-start font-medium">{{ t('pos.reports.columns.item') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('pos.reports.columns.quantity') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('pos.reports.columns.amount') }}</th>
                                <th class="px-5 py-2 text-end font-medium">{{ t('pos.reports.columns.margin') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in data.items" :key="row.item_id">
                                <td class="px-5 py-2"><span class="font-medium">{{ row.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ row.sku }}</span></td>
                                <td class="tabular px-3 py-2 text-end">{{ formatQuantity(row.quantity_milli) }}</td>
                                <td class="tabular px-3 py-2 text-end font-medium">{{ money(row.amount) }}</td>
                                <td class="tabular px-5 py-2 text-end text-muted">{{ money(row.net - row.cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </PageFrame>
</template>
