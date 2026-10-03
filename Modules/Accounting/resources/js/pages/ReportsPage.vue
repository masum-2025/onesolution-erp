<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, CircleAlert, CircleCheck, Landmark, Printer } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { formatDate, formatMoney } from '@/lib/format';
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
]);
const tab = ref(tabs.value.some((item) => item.key === route.query.report) ? route.query.report : 'trial-balance');
const ranged = computed(() => ['profit-loss', 'ledger'].includes(tab.value));

const today = todayIn(session.me?.context?.settings?.timezone);
const filters = reactive({ as_of: today, from: monthOf(today).from, to: today, cost_centre_id: '', account_id: route.query.account_id ?? '' });
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
    if (filters.cost_centre_id) query.cost_centre_id = filters.cost_centre_id;

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

function printPage() {
    window.print();
}

const sheetBalanced = computed(() => report.value && tab.value === 'balance-sheet' && report.value.assets.total_minor === report.value.total_liabilities_and_equity_minor);
</script>

<template>
    <div>
        <PageHeader :title="t('accounting.reports.title')" :description="t('accounting.reports.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'accounting' }" :icon="ArrowLeft" class="print:hidden">{{ t('accounting.journal.back') }}</AppButton>
                <AppButton :icon="Printer" :disabled="!report" class="print:hidden" @click="printPage">{{ t('accounting.reports.print') }}</AppButton>
            </template>
        </PageHeader>

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
                        <input :id="id" v-model="filters.from" type="date" class="field-input" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('accounting.reports.to')">
                        <input :id="id" v-model="filters.to" type="date" class="field-input" :min="filters.from" />
                    </AppField>
                </template>
                <AppField v-else v-slot="{ id }" :label="t('accounting.reports.as_of')">
                    <input :id="id" v-model="filters.as_of" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.reports.cost_centre')">
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
    </div>
</template>
