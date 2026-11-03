<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, CircleAlert, CircleCheck, Landmark } from 'lucide-vue-next';
import { DateInput, PageFrame } from '@/ui';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization, session } from '@/lib/session';
import { subtreeIds, visibleOrganizations } from '@/lib/organizations';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { accountTree, monthOf, todayIn } from '../lib';
import BooksGate from '../components/BooksGate.vue';

/**
 * The four reports, from posted entries only: trial balance and balance
 * sheet on a day, profit and loss and an account's ledger over a range,
 * each optionally for one branch or department (with its units). Printable.
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();
const router = useRouter();

const tabs = computed(() => [
    { key: 'trial-balance', label: t('accounting.reports.tabs.trial_balance') },
    { key: 'profit-loss', label: t('accounting.reports.tabs.profit_loss') },
    { key: 'balance-sheet', label: t('accounting.reports.tabs.balance_sheet') },
    { key: 'ledger', label: t('accounting.reports.tabs.ledger') },
    { key: 'aging', label: t('accounting.aging.tab') },
    { key: 'vat', label: t('accounting.tax.report.tab') },
]);
const tab = ref(tabs.value.some((item) => item.key === route.query.report) ? route.query.report : 'trial-balance');
const ranged = computed(() => ['profit-loss', 'ledger', 'vat'].includes(tab.value));

const today = todayIn(session.me?.context?.settings?.timezone);
const filters = reactive({ as_of: today, from: monthOf(today).from, to: today, cost_centre_id: '', account_id: route.query.account_id ?? '', side: 'sales' });
const units = ref([]);
const accounts = ref([]);

const loading = ref(false);
const error = ref(null);
const report = ref(null);
const money = (amount) => formatMoney({ amount, currency: report.value?.currency });

onMounted(async () => {
    const [visible, list] = await Promise.all([visibleOrganizations().catch(() => []), books.accounts(true).catch(() => ({ data: [] }))]);
    const inCompany = subtreeIds(visible, org.id);
    units.value = visible.filter((unit) => unit.id !== org.id && inCompany.has(unit.id));
    // Every account that can hold entries, archived ones too (their history stays readable).
    accounts.value = accountTree(list.data).flat.filter((account) => !account.is_group);
    show();
});

watch(tab, () => {
    router.replace({ query: { report: tab.value } });
    report.value = null;
    error.value = null;
    show();
});

async function show() {
    if (tab.value === 'ledger' && !filters.account_id) {
        report.value = null;
        return;
    }
    const query = ranged.value ? { from: filters.from, to: filters.to } : { as_of: filters.as_of };
    if (tab.value === 'ledger') query.account_id = filters.account_id;
    if (tab.value === 'aging') query.side = filters.side;
    else if (filters.cost_centre_id && tab.value !== 'vat') query.cost_centre_id = filters.cost_centre_id;

    loading.value = true;
    error.value = null;
    try {
        report.value = (await books.report(tab.value, query)).data;
    } catch (caught) {
        error.value = caught;
        report.value = null;
    } finally {
        loading.value = false;
    }
}

/** Column titles of the aging report from the company's limits (e.g. 30, 60, 90). */
function bucketLabel(key) {
    if (key === 'current') return t('accounting.aging.current');
    const limits = report.value.bucket_limits;
    if (key.startsWith('over_')) return t('accounting.aging.over', { days: formatNumber(Number(key.slice(5))) });
    const index = limits.indexOf(Number(key.slice(5)));
    return t('accounting.aging.upto', { from: formatNumber(index === 0 ? 1 : limits[index - 1] + 1), to: formatNumber(limits[index]) });
}

const sheetBalanced = computed(() => report.value && tab.value === 'balance-sheet' && report.value.assets.total_minor === report.value.total_liabilities_and_equity_minor);

// Export and print: the report shown, as its table reads (totals as the last rows).
function reportSheet(kind, data) {
    const cur = data.currency;
    const money = (key, label) => ({ key, label, type: 'money', currency: cur });
    const account = [{ key: 'code', label: t('accounting.export.code') }, { key: 'name', label: t('accounting.reports.account_col') }];
    const total = t('accounting.reports.total');
    if (kind === 'trial-balance') {
        return {
            columns: [...account, money('debit_minor', t('accounting.reports.debit')), money('credit_minor', t('accounting.reports.credit'))],
            rows: [...data.rows, { name: total, debit_minor: data.total_debit_minor, credit_minor: data.total_credit_minor }],
        };
    }
    if (kind === 'profit-loss' || kind === 'balance-sheet') {
        const parts = kind === 'profit-loss'
            ? [['income', t('accounting.reports.income')], ['expense', t('accounting.reports.expenses')]]
            : [['assets', t('accounting.reports.assets')], ['liabilities', t('accounting.reports.liabilities')], ['equity', t('accounting.reports.equity')]];
        const rows = parts.flatMap(([key, label]) => [
            ...data[key].rows.map((row) => ({ ...row, part: label })),
            { part: label, name: total, amount_minor: data[key].total_minor },
        ]);
        if (kind === 'profit-loss') rows.push({ name: data.net_profit_minor < 0 ? t('accounting.reports.net_loss') : t('accounting.reports.net_profit'), amount_minor: data.net_profit_minor });
        else rows.push({ part: t('accounting.reports.equity'), name: t('accounting.reports.earnings'), amount_minor: data.earnings_to_date_minor }, { name: t('accounting.reports.liabilities_equity'), amount_minor: data.total_liabilities_and_equity_minor });
        return { columns: [{ key: 'part', label: t('accounting.export.part') }, ...account, money('amount_minor', t('accounting.export.total'))], rows };
    }
    if (kind === 'aging') {
        return {
            columns: [
                { key: 'party_name', label: t('accounting.aging.party') },
                ...data.buckets.map((bucket) => ({ key: bucket, label: bucketLabel(bucket), type: 'money', currency: cur, value: (row) => row.buckets[bucket] ?? null })),
                money('credits_minor', t('accounting.aging.credits')),
                money('net_minor', t('accounting.aging.net')),
            ],
            rows: [...data.rows.map((row) => ({ ...row, credits_minor: row.credits_minor ? -row.credits_minor : null })), { party_name: total, buckets: data.totals.buckets, credits_minor: -data.totals.credits_minor, net_minor: data.totals.net_minor }],
        };
    }
    if (kind === 'vat') {
        return {
            columns: [
                { key: 'name', label: t('accounting.tax.report.code'), value: (row) => row.name ?? t('accounting.tax.report.no_tax') },
                money('sales_taxable_minor', `${t('accounting.tax.report.sales')}: ${t('accounting.tax.report.taxable')}`),
                money('output_tax_minor', t('accounting.tax.report.output')),
                money('purchases_taxable_minor', `${t('accounting.tax.report.purchases')}: ${t('accounting.tax.report.taxable')}`),
                money('input_tax_minor', t('accounting.tax.report.input')),
            ],
            rows: [
                ...data.rows,
                { name: total, output_tax_minor: data.output_tax_minor, input_tax_minor: data.input_tax_minor },
                { name: data.payable_minor < 0 ? t('accounting.tax.report.refundable') : t('accounting.tax.report.payable'), output_tax_minor: Math.abs(data.payable_minor) },
            ],
        };
    }
    return {
        columns: [
            { key: 'entry_date', label: t('accounting.reports.date'), type: 'date' },
            { key: 'number', label: t('accounting.export.number') },
            { key: 'narration', label: t('accounting.reports.narration'), value: (row) => [row.narration, row.memo].filter(Boolean).join(' · ') },
            money('debit_minor', t('accounting.reports.debit')),
            money('credit_minor', t('accounting.reports.credit')),
            money('balance_minor', t('accounting.reports.balance')),
        ],
        rows: [{ narration: t('accounting.reports.opening'), balance_minor: data.opening_minor }, ...data.rows, { narration: t('accounting.reports.closing'), balance_minor: data.closing_minor }],
    };
}
const exported = computed(() => (report.value ? { module: 'accounting', page: `accounting.report.${tab.value}`, ...reportSheet(tab.value, report.value) } : null));
</script>

<template>
    <PageFrame :title="t('accounting.reports.title')" :description="t('accounting.reports.text')" width="full" :export="exported">
        <template #actions>
            <AppButton variant="ghost" :to="{ name: 'accounting' }" :icon="ArrowLeft" class="print:hidden">{{ t('accounting.journal.back') }}</AppButton>
        </template>

        <BooksGate>
            <AppTabs v-model="tab" :tabs="tabs" :label="t('accounting.reports.title')" class="mb-4 print:hidden" />

            <form class="card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto] lg:items-end print:hidden" @submit.prevent="show">
                <AppField v-if="tab === 'ledger'" v-slot="{ id }" :label="t('accounting.reports.account')" class="sm:col-span-2 lg:col-span-1">
                    <select :id="id" v-model="filters.account_id" class="field-input">
                        <option value="">{{ t('accounting.reports.choose_account') }}</option>
                        <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option>
                    </select>
                </AppField>
                <template v-if="ranged">
                    <AppField v-slot="{ id }" :label="t('accounting.reports.from')">
                        <DateInput :id="id" v-model="filters.from" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('accounting.reports.to')">
                        <DateInput :id="id" v-model="filters.to" :min="filters.from" />
                    </AppField>
                </template>
                <AppField v-else v-slot="{ id }" :label="t('accounting.reports.as_of')">
                    <DateInput :id="id" v-model="filters.as_of" />
                </AppField>
                <AppField v-if="tab === 'aging'" v-slot="{ id }" :label="t('accounting.aging.side')">
                    <select :id="id" v-model="filters.side" class="field-input">
                        <option value="sales">{{ t('accounting.aging.sales') }}</option>
                        <option value="purchases">{{ t('accounting.aging.purchases') }}</option>
                    </select>
                </AppField>
                <AppField v-else-if="tab !== 'vat'" v-slot="{ id }" :label="t('accounting.reports.cost_centre')">
                    <select :id="id" v-model="filters.cost_centre_id" class="field-input">
                        <option value="">{{ t('accounting.reports.whole_company') }}</option>
                        <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.display_name }}</option>
                    </select>
                </AppField>
                <AppButton variant="primary" type="submit" :loading="loading">{{ t('accounting.reports.show') }}</AppButton>
            </form>

            <section class="card overflow-x-auto">
                <header v-if="report" class="border-b border-line px-5 py-3">
                    <h2 class="text-[15px] font-semibold text-fg">{{ tabs.find((item) => item.key === tab)?.label }}</h2>
                    <p class="text-[12.5px] text-muted">
                        {{ report.as_of ? t('accounting.reports.as_of_date', { date: formatDate(report.as_of) }) : t('accounting.reports.period', { from: formatDate(report.from), to: formatDate(report.to) }) }}
                        <template v-if="filters.cost_centre_id"> · {{ units.find((unit) => unit.id === filters.cost_centre_id)?.display_name }}</template>
                    </p>
                </header>

                <SkeletonRows v-if="loading && !report" :rows="8" />
                <ErrorState v-else-if="error" compact :error="error" @retry="show" />
                <EmptyState v-else-if="tab === 'ledger' && !filters.account_id" :icon="Landmark" :title="t('accounting.reports.pick_account')" compact />

                <!-- Trial balance -->
                <table v-else-if="report && tab === 'trial-balance'" class="w-full min-w-[32rem] text-[13.5px]">
                    <thead class="border-b border-line text-[12px] text-muted">
                        <tr>
                            <th class="px-5 py-2.5 text-start font-medium">{{ t('accounting.reports.account_col') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('accounting.reports.debit') }}</th>
                            <th class="px-5 py-2.5 text-end font-medium">{{ t('accounting.reports.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-if="!report.rows.length"><td colspan="3" class="px-5 py-6 text-center text-muted">{{ t('accounting.reports.nothing') }}</td></tr>
                        <tr v-for="row in report.rows" :key="row.account_id">
                            <td class="px-5 py-2"><span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ row.code }}</span> {{ row.name }}</td>
                            <td class="tabular px-3 py-2 text-end">{{ row.debit_minor ? money(row.debit_minor) : '' }}</td>
                            <td class="tabular px-5 py-2 text-end">{{ row.credit_minor ? money(row.credit_minor) : '' }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-line font-semibold">
                        <tr>
                            <td class="px-5 py-2.5">{{ t('accounting.reports.total') }}</td>
                            <td class="tabular px-3 py-2.5 text-end">{{ money(report.total_debit_minor) }}</td>
                            <td class="tabular px-5 py-2.5 text-end">{{ money(report.total_credit_minor) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Profit and loss -->
                <div v-else-if="report && tab === 'profit-loss'" class="divide-y divide-line text-[13.5px]">
                    <div v-for="part in [{ key: 'income', label: t('accounting.reports.income') }, { key: 'expense', label: t('accounting.reports.expenses') }]" :key="part.key" class="px-5 py-3">
                        <h3 class="mb-1.5 text-[12.5px] font-semibold uppercase tracking-wide text-muted">{{ part.label }}</h3>
                        <p v-if="!report[part.key].rows.length" class="text-muted">{{ t('accounting.reports.nothing') }}</p>
                        <div v-for="row in report[part.key].rows" :key="row.account_id" class="flex justify-between gap-3 py-1">
                            <span><span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ row.code }}</span> {{ row.name }}</span>
                            <span class="tabular">{{ money(row.amount_minor) }}</span>
                        </div>
                        <div class="mt-1.5 flex justify-between border-t border-line pt-1.5 font-semibold">
                            <span>{{ t('accounting.reports.total') }}</span><span class="tabular">{{ money(report[part.key].total_minor) }}</span>
                        </div>
                    </div>
                    <div class="flex justify-between px-5 py-3 text-[15px] font-semibold" :class="report.net_profit_minor < 0 ? 'text-bad' : 'text-ok'">
                        <span>{{ report.net_profit_minor < 0 ? t('accounting.reports.net_loss') : t('accounting.reports.net_profit') }}</span>
                        <span class="tabular">{{ money(Math.abs(report.net_profit_minor)) }}</span>
                    </div>
                </div>

                <!-- Balance sheet -->
                <div v-else-if="report && tab === 'balance-sheet'" class="grid text-[13.5px] md:grid-cols-2 md:divide-x md:divide-line rtl:md:divide-x-reverse">
                    <div class="px-5 py-3">
                        <h3 class="mb-1.5 text-[12.5px] font-semibold uppercase tracking-wide text-muted">{{ t('accounting.reports.assets') }}</h3>
                        <div v-for="row in report.assets.rows" :key="row.account_id" class="flex justify-between gap-3 py-1">
                            <span><span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ row.code }}</span> {{ row.name }}</span>
                            <span class="tabular">{{ money(row.amount_minor) }}</span>
                        </div>
                        <div class="mt-1.5 flex justify-between border-t border-line pt-1.5 font-semibold">
                            <span>{{ t('accounting.reports.total') }}</span><span class="tabular">{{ money(report.assets.total_minor) }}</span>
                        </div>
                    </div>
                    <div class="px-5 py-3">
                        <template v-for="part in [{ key: 'liabilities', label: t('accounting.reports.liabilities') }, { key: 'equity', label: t('accounting.reports.equity') }]" :key="part.key">
                            <h3 v-if="report[part.key].rows.length" class="mb-1.5 mt-2 text-[12.5px] font-semibold uppercase tracking-wide text-muted first:mt-0">{{ part.label }}</h3>
                            <div v-for="row in report[part.key].rows" :key="row.account_id" class="flex justify-between gap-3 py-1">
                                <span><span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ row.code }}</span> {{ row.name }}</span>
                                <span class="tabular">{{ money(row.amount_minor) }}</span>
                            </div>
                        </template>
                        <div class="flex justify-between gap-3 py-1">
                            <span>{{ t('accounting.reports.earnings') }}</span><span class="tabular">{{ money(report.earnings_to_date_minor) }}</span>
                        </div>
                        <div class="mt-1.5 flex justify-between border-t border-line pt-1.5 font-semibold">
                            <span>{{ t('accounting.reports.liabilities_equity') }}</span><span class="tabular">{{ money(report.total_liabilities_and_equity_minor) }}</span>
                        </div>
                    </div>
                    <p class="flex items-center gap-1.5 border-t border-line px-5 py-3 text-[13px] md:col-span-2" :class="sheetBalanced ? 'text-ok' : 'text-bad'">
                        <component :is="sheetBalanced ? CircleCheck : CircleAlert" class="size-4" aria-hidden="true" />
                        {{ sheetBalanced ? t('accounting.reports.balanced') : t('accounting.reports.not_balanced') }}
                    </p>
                </div>

                <!-- Aging: open amounts by days overdue -->
                <table v-else-if="report && tab === 'aging'" class="w-full min-w-[44rem] text-[13.5px]">
                    <thead class="border-b border-line text-[12px] text-muted">
                        <tr>
                            <th class="px-5 py-2.5 text-start font-medium">{{ t('accounting.aging.party') }}</th>
                            <th v-for="bucket in report.buckets" :key="bucket" class="px-3 py-2.5 text-end font-medium">{{ bucketLabel(bucket) }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('accounting.aging.credits') }}</th>
                            <th class="px-5 py-2.5 text-end font-medium">{{ t('accounting.aging.net') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-if="!report.rows.length"><td :colspan="report.buckets.length + 3" class="px-5 py-6 text-center text-muted">{{ t('accounting.reports.nothing') }}</td></tr>
                        <tr v-for="row in report.rows" :key="row.party_id">
                            <td class="px-5 py-2">
                                <RouterLink class="font-medium text-brand-strong hover:underline" :to="{ name: 'accounting-party', params: { id: row.party_id } }">{{ row.party_name }}</RouterLink>
                            </td>
                            <td v-for="bucket in report.buckets" :key="bucket" class="tabular px-3 py-2 text-end" :class="bucket !== 'current' && row.buckets[bucket] ? 'text-bad' : ''">{{ row.buckets[bucket] ? money(row.buckets[bucket]) : '' }}</td>
                            <td class="tabular px-3 py-2 text-end">{{ row.credits_minor ? money(-row.credits_minor) : '' }}</td>
                            <td class="tabular px-5 py-2 text-end font-medium">{{ money(row.net_minor) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-line font-semibold">
                        <tr>
                            <td class="px-5 py-2.5">{{ t('accounting.reports.total') }}</td>
                            <td v-for="bucket in report.buckets" :key="bucket" class="tabular px-3 py-2.5 text-end">{{ money(report.totals.buckets[bucket]) }}</td>
                            <td class="tabular px-3 py-2.5 text-end">{{ money(-report.totals.credits_minor) }}</td>
                            <td class="tabular px-5 py-2.5 text-end">{{ money(report.totals.net_minor) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <!-- VAT: per tax code, output on sales and input on purchases -->
                <table v-else-if="report && tab === 'vat'" class="w-full min-w-[44rem] text-[13.5px]">
                    <thead class="border-b border-line text-[12px] text-muted">
                        <tr>
                            <th class="px-5 py-2.5 text-start font-medium">{{ t('accounting.tax.report.code') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('accounting.tax.report.sales') }} · {{ t('accounting.tax.report.taxable') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('accounting.tax.report.output') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('accounting.tax.report.purchases') }} · {{ t('accounting.tax.report.taxable') }}</th>
                            <th class="px-5 py-2.5 text-end font-medium">{{ t('accounting.tax.report.input') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-if="!report.rows.length"><td colspan="5" class="px-5 py-6 text-center text-muted">{{ t('accounting.reports.nothing') }}</td></tr>
                        <tr v-for="row in report.rows" :key="row.tax_code_id ?? 'none'">
                            <td class="px-5 py-2">{{ row.name ?? t('accounting.tax.report.no_tax') }}</td>
                            <td class="tabular px-3 py-2 text-end">{{ money(row.sales_taxable_minor) }}</td>
                            <td class="tabular px-3 py-2 text-end">{{ money(row.output_tax_minor) }}</td>
                            <td class="tabular px-3 py-2 text-end">{{ money(row.purchases_taxable_minor) }}</td>
                            <td class="tabular px-5 py-2 text-end">{{ money(row.input_tax_minor) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-line font-semibold">
                        <tr>
                            <td class="px-5 py-2.5" colspan="2">{{ t('accounting.reports.total') }}</td>
                            <td class="tabular px-3 py-2.5 text-end">{{ money(report.output_tax_minor) }}</td>
                            <td></td>
                            <td class="tabular px-5 py-2.5 text-end">{{ money(report.input_tax_minor) }}</td>
                        </tr>
                        <tr :class="report.payable_minor < 0 ? 'text-ok' : ''">
                            <td class="px-5 py-2.5" colspan="4">{{ report.payable_minor < 0 ? t('accounting.tax.report.refundable') : t('accounting.tax.report.payable') }}</td>
                            <td class="tabular px-5 py-2.5 text-end text-[15px]">{{ money(Math.abs(report.payable_minor)) }}</td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Account ledger -->
                <table v-else-if="report && tab === 'ledger'" class="w-full min-w-[40rem] text-[13.5px]">
                    <thead class="border-b border-line text-[12px] text-muted">
                        <tr>
                            <th class="px-5 py-2.5 text-start font-medium">{{ t('accounting.reports.date') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('accounting.reports.narration') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('accounting.reports.debit') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('accounting.reports.credit') }}</th>
                            <th class="px-5 py-2.5 text-end font-medium">{{ t('accounting.reports.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr class="bg-subtle/50">
                            <td class="px-5 py-2 font-medium" colspan="4">{{ t('accounting.reports.opening') }}</td>
                            <td class="tabular px-5 py-2 text-end font-medium">{{ money(report.opening_minor) }}</td>
                        </tr>
                        <tr v-for="row in report.rows" :key="`${row.journal_id}-${row.debit_minor}-${row.credit_minor}-${row.balance_minor}`">
                            <td class="whitespace-nowrap px-5 py-2">{{ formatDate(row.entry_date) }}</td>
                            <td class="px-3 py-2">
                                <RouterLink class="font-medium text-brand-strong hover:underline" :to="{ name: 'accounting-journal', params: { id: row.journal_id } }">{{ row.number }}</RouterLink>
                                {{ row.narration }}
                                <span v-if="row.memo" class="block text-[12px] text-muted">{{ row.memo }}</span>
                            </td>
                            <td class="tabular px-3 py-2 text-end">{{ row.debit_minor ? money(row.debit_minor) : '' }}</td>
                            <td class="tabular px-3 py-2 text-end">{{ row.credit_minor ? money(row.credit_minor) : '' }}</td>
                            <td class="tabular px-5 py-2 text-end">{{ money(row.balance_minor) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-line font-semibold">
                        <tr>
                            <td class="px-5 py-2.5" colspan="4">{{ t('accounting.reports.closing') }}</td>
                            <td class="tabular px-5 py-2.5 text-end">{{ money(report.closing_minor) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>
        </BooksGate>
    </PageFrame>
</template>
