<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { BarChart3 } from 'lucide-vue-next';
import { DateInput, PageFrame } from '@/ui';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { socialApi } from '../api';

/**
 * Facebook sales for owners and managers (social_commerce.reports): the last
 * 7 or 30 days, this month, or chosen days; all pages or one. Totals on top,
 * who answered, then by day (a bar each), page and product. Days are the
 * company's own (the server's timezone). Export and print in the page menu.
 */
const social = socialApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const pages = useResource(() => social.pages());
const today = new Date().toISOString().slice(0, 10);
const back = (days) => new Date(Date.parse(`${today}T00:00:00Z`) - days * 86400000).toISOString().slice(0, 10);
const presets = { week: () => ({ from: back(6), to: today }), month30: () => ({ from: back(29), to: today }), this_month: () => ({ from: `${today.slice(0, 7)}-01`, to: today }) };
const preset = ref(route.query.preset ?? (route.query.from ? 'custom' : 'month30'));
const custom = ref({ from: route.query.from ?? back(29), to: route.query.to ?? today });
const pageId = ref(route.query.page_id ?? '');
const range = computed(() => (preset.value === 'custom' ? custom.value : presets[preset.value]?.() ?? presets.month30()));
const report = useResource(() => social.salesReport({ ...range.value, ...(pageId.value ? { page_id: pageId.value } : {}) }));
watch([preset, custom, pageId], () => {
    router.replace({ query: { preset: preset.value, ...(preset.value === 'custom' ? custom.value : {}), ...(pageId.value ? { page_id: pageId.value } : {}) } });
    report.reload();
}, { deep: true });

const data = computed(() => report.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: data.value?.currency });
const percent = (part, whole) => (whole > 0 ? `${formatNumber(Math.round((part * 100) / whole))}%` : '—');
const day = (date) => formatDate(`${date}T00:00:00Z`, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
const quantity = (milli) => formatNumber(milli / 1000);
const empty = computed(() => !!data.value && !data.value.conversations.active && !data.value.conversations.new && !data.value.orders.count);

const tiles = computed(() => {
    const report = data.value;
    if (!report) return [];
    const { conversations: c, orders: o } = report;
    return [
        { key: 'conversations', value: formatNumber(c.active), note: t('social_commerce.reports.new_count', { count: formatNumber(c.new) }) },
        { key: 'orders', value: formatNumber(o.count), note: t('social_commerce.reports.conversion', { percent: formatNumber(report.conversion_bp / 100) }), hidden: !report.orders_on },
        { key: 'amount', value: money(o.amount), note: t('social_commerce.reports.average', { amount: money(o.average) }), hidden: !report.orders_on },
        { key: 'delivered', value: formatNumber(o.delivered), note: money(o.delivered_amount), hidden: !report.orders_on },
        { key: 'cancelled', value: formatNumber(o.cancelled), note: percent(o.cancelled, o.count), bad: o.cancelled > 0, hidden: !report.orders_on },
        { key: 'paid_online', value: money(o.paid_online), hidden: !report.orders_on },
    ].filter((tile) => !tile.hidden);
});
const answers = computed(() => {
    const a = data.value?.answers;
    if (!a) return [];
    return [
        { key: 'bot', value: a.bot, of: a.bot + a.person },
        { key: 'person', value: a.person, of: a.bot + a.person },
        { key: 'by_keyword', value: a.by_keyword, of: a.received },
        { key: 'by_ai', value: a.by_ai, of: a.received },
        { key: 'not_understood', value: a.not_understood, of: a.received },
    ];
});
const busiest = computed(() => Math.max(1, ...(data.value?.days ?? []).map((row) => row.amount)));
const most = computed(() => Math.max(1, ...(data.value?.days ?? []).map((row) => row.orders)));
const bar = (value, max) => `${value > 0 ? Math.max(3, Math.round((value * 100) / max)) : 0}%`;
const days = computed(() => (data.value?.days ?? []).filter((row) => row.orders || row.conversations || (data.value?.days.length ?? 0) <= 31));

// Export and print: the whole report as one table, each row named by its part.
const exported = computed(() => {
    const report = data.value;
    if (!report) return null;
    const part = (key, rows) => rows.map((row) => ({ part: t(key), ...row }));
    return {
        module: 'social_commerce',
        page: 'social_commerce.sales',
        columns: [
            { key: 'part', label: t('social_commerce.reports.columns.part') },
            { key: 'name', label: t('social_commerce.reports.columns.name') },
            { key: 'conversations', label: t('social_commerce.reports.columns.conversations'), type: 'number' },
            { key: 'orders', label: t('social_commerce.reports.columns.orders'), type: 'number' },
            { key: 'quantity', label: t('social_commerce.reports.columns.quantity'), type: 'number' },
            { key: 'amount', label: t('social_commerce.reports.columns.amount'), type: 'money', currency: report.currency },
        ],
        rows: [
            ...part('social_commerce.reports.totals', [
                { name: t('social_commerce.reports.tiles.conversations'), conversations: report.conversations.active },
                { name: t('social_commerce.reports.tiles.orders'), orders: report.orders.count, amount: report.orders.amount },
                { name: t('social_commerce.reports.tiles.delivered'), orders: report.orders.delivered, amount: report.orders.delivered_amount },
                { name: t('social_commerce.reports.tiles.cancelled'), orders: report.orders.cancelled },
                { name: t('social_commerce.reports.tiles.paid_online'), amount: report.orders.paid_online },
            ]),
            ...part('social_commerce.reports.by_answer', answers.value.map((row) => ({ name: t(`social_commerce.reports.answers.${row.key}`), conversations: row.value }))),
            ...part('social_commerce.reports.by_day', report.days.map((row) => ({ name: row.date, conversations: row.conversations, orders: row.orders, amount: row.amount }))),
            ...part('social_commerce.reports.by_page', report.pages.map((row) => ({ name: row.name, conversations: row.conversations, orders: row.orders, amount: row.amount }))),
            ...part('social_commerce.reports.by_item', report.items.map((row) => ({ name: row.name, orders: row.orders, quantity: row.quantity_milli / 1000, amount: row.amount }))),
        ],
    };
});
</script>

<template>
    <PageFrame
        :title="t('social_commerce.reports.title')"
        :description="t('social_commerce.reports.text')"
        width="full"
        :resource="report"
        :empty="{ when: empty, icon: BarChart3, title: t('social_commerce.reports.empty'), text: t('social_commerce.reports.empty_text') }"
        :export="exported"
    >
        <template #top>
            <section class="card mb-5 grid gap-4 p-5 print:hidden">
                <AppSegmented v-model="preset" size="sm" :label="t('social_commerce.reports.period')" :options="['week', 'month30', 'this_month', 'custom'].map((value) => ({ value, label: t(`social_commerce.reports.presets.${value}`) }))" />
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <template v-if="preset === 'custom'">
                        <AppField v-slot="{ id }" :label="t('social_commerce.reports.from')">
                            <DateInput :id="id" v-model="custom.from" :max="today" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('social_commerce.reports.to')">
                            <DateInput :id="id" v-model="custom.to" :max="today" />
                        </AppField>
                    </template>
                    <AppField v-slot="{ id }" :label="t('social_commerce.reports.page')">
                        <select :id="id" v-model="pageId" class="field-input">
                            <option value="">{{ t('social_commerce.reports.all_pages') }}</option>
                            <option v-for="row in pages.data.value?.data ?? []" :key="row.id" :value="row.id">{{ row.name }}</option>
                        </select>
                    </AppField>
                </div>
            </section>
        </template>

        <template v-if="data">
            <p class="mb-3 text-[12px] text-muted">{{ t('social_commerce.reports.zone', { zone: data.timezone }) }}</p>
            <p v-if="!data.orders_on" class="card mb-5 p-4 text-[13px]">{{ t('social_commerce.reports.orders_off') }}</p>
            <p v-else-if="data.orders.other_currency" class="card mb-5 p-4 text-[13px]">{{ t('social_commerce.reports.other_currency', { count: formatNumber(data.orders.other_currency) }) }}</p>

            <!-- Totals -->
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <div v-for="tile in tiles" :key="tile.key" class="card p-4">
                    <p class="text-[12px] text-muted">{{ t(`social_commerce.reports.tiles.${tile.key}`) }}</p>
                    <p class="tabular mt-1 text-[18px] font-semibold" :class="tile.bad ? 'text-bad' : ''">{{ tile.value }}</p>
                    <p v-if="tile.note" class="mt-0.5 text-[12px] text-muted">{{ tile.note }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
                <!-- By day: a bar for the amount (orders when nothing was added up) -->
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('social_commerce.reports.by_day') }}</h2>
                    <ul class="max-h-[28rem] divide-y divide-line overflow-y-auto">
                        <li v-for="row in days" :key="row.date" class="grid grid-cols-[6.5rem_1fr_auto] items-center gap-3 px-5 py-2 text-[13px]">
                            <span>{{ day(row.date) }}</span>
                            <span class="h-2 rounded-full bg-subtle" aria-hidden="true"><span class="block h-2 rounded-full bg-brand" :style="{ inlineSize: data.orders.amount ? bar(row.amount, busiest) : bar(row.orders, most) }" /></span>
                            <span class="tabular text-end">
                                <b v-if="data.orders_on">{{ money(row.amount) }}</b>
                                <span class="text-muted"> · {{ t('social_commerce.reports.day_counts', { orders: formatNumber(row.orders), conversations: formatNumber(row.conversations) }) }}</span>
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- Who answered, and how the questions were understood -->
                <section class="card">
                    <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('social_commerce.reports.by_answer') }}</h2>
                    <p class="px-5 pt-3 text-[12px] text-muted">{{ t('social_commerce.reports.received', { count: formatNumber(data.answers.received) }) }}</p>
                    <ul class="divide-y divide-line">
                        <li v-for="row in answers" :key="row.key" class="grid grid-cols-[9rem_1fr_auto] items-center gap-3 px-5 py-2.5 text-[13px]">
                            <span>{{ t(`social_commerce.reports.answers.${row.key}`) }}</span>
                            <span class="h-2 rounded-full bg-subtle" aria-hidden="true"><span class="block h-2 rounded-full" :class="row.key === 'not_understood' ? 'bg-bad' : 'bg-brand'" :style="{ inlineSize: bar(row.value, Math.max(1, row.of)) }" /></span>
                            <span class="tabular w-24 text-end"><b>{{ formatNumber(row.value) }}</b> <span class="text-muted">· {{ percent(row.value, row.of) }}</span></span>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- By page -->
            <section class="card mt-5">
                <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('social_commerce.reports.by_page') }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-[13px]">
                        <thead class="text-[12px] text-muted">
                            <tr class="border-b border-line">
                                <th class="px-5 py-2 text-start font-medium">{{ t('social_commerce.reports.columns.page') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('social_commerce.reports.columns.conversations') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('social_commerce.reports.columns.orders') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('social_commerce.reports.columns.amount') }}</th>
                                <th class="px-5 py-2 text-end font-medium">{{ t('social_commerce.reports.columns.delivered') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in data.pages" :key="row.page_id">
                                <td class="px-5 py-2 font-medium">{{ row.name }}</td>
                                <td class="tabular px-3 py-2 text-end">{{ formatNumber(row.conversations) }}</td>
                                <td class="tabular px-3 py-2 text-end">{{ formatNumber(row.orders) }}</td>
                                <td class="tabular px-3 py-2 text-end font-medium">{{ money(row.amount) }}</td>
                                <td class="tabular px-5 py-2 text-end text-muted">{{ percent(row.delivered, row.orders) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Best-selling products -->
            <section v-if="data.orders_on" class="card mt-5">
                <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('social_commerce.reports.by_item') }}</h2>
                <p v-if="!data.items.length" class="px-5 py-4 text-[13px] text-muted">{{ t('social_commerce.reports.no_items') }}</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[480px] text-[13px]">
                        <thead class="text-[12px] text-muted">
                            <tr class="border-b border-line">
                                <th class="px-5 py-2 text-start font-medium">{{ t('social_commerce.reports.columns.name') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('social_commerce.reports.columns.orders') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('social_commerce.reports.columns.quantity') }}</th>
                                <th class="px-5 py-2 text-end font-medium">{{ t('social_commerce.reports.columns.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in data.items" :key="row.item_id">
                                <td class="px-5 py-2 font-medium">{{ row.name }}</td>
                                <td class="tabular px-3 py-2 text-end">{{ formatNumber(row.orders) }}</td>
                                <td class="tabular px-3 py-2 text-end">{{ quantity(row.quantity_milli) }}</td>
                                <td class="tabular px-5 py-2 text-end font-medium">{{ money(row.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </PageFrame>
</template>
