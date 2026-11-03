<script setup>
import { computed, ref, watch } from 'vue';
import { FileText } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { billState } from '../lib';
import { billTitle, day } from '../labels';

/** Bills made final at the unit and below, by state: unpaid, overdue, paid, cancelled. */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();

const tab = ref('open');
const tabs = computed(() => ['open', 'overdue', 'paid', 'cancelled', 'all'].map((key) => ({ key, label: t(`education_fees.bills.tabs.${key}`) })));
const query = () => ({ open: { status: 'open' }, overdue: { overdue: 1 }, paid: { status: 'paid' }, cancelled: { status: 'cancelled' }, all: {} }[tab.value]);
const list = useResource(() => api.bills(query()));
watch(tab, () => list.reload());
const rows = computed(() => list.data.value?.data ?? []);

// Export and print: the bills of the tab picked (what is left to pay on open ones).
const exported = computed(() => ({
    module: 'education_fees',
    page: 'education_fees.bills',
    columns: [
        { key: 'number', label: t('education_fees.export.number') },
        { key: 'code', label: t('education_fees.dues.csv_columns.code'), value: (bill) => bill.student?.code ?? '' },
        { key: 'name', label: t('education_fees.dues.csv_columns.name'), value: (bill) => bill.student?.name ?? '' },
        { key: 'title', label: t('education_fees.export.bill'), value: (bill) => billTitle(bill) },
        { key: 'due_date', label: t('education_fees.export.due_date'), type: 'date' },
        { key: 'total_minor', label: t('education_fees.export.total'), type: 'money', currency: setup.currency.value },
        { key: 'balance_minor', label: t('education_fees.export.balance'), type: 'money', currency: setup.currency.value },
        { key: 'status', label: t('education_fees.export.status'), value: (bill) => t(`education_fees.bill_states.${bill.status}`) },
    ],
    rows: rows.value,
}));
</script>

<template>
    <PageFrame :title="t('education_fees.bills.title')" :description="t('education_fees.bills.text')" width="full" :export="exported">
        <div class="mb-4 overflow-x-auto print:hidden"><AppTabs v-model="tab" :tabs="tabs" :label="t('education_fees.bills.title')" /></div>
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="FileText" :title="t('education_fees.bills.empty')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="bill in rows" :key="bill.id">
                    <RouterLink :to="{ name: 'fees-bill', params: { id: bill.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                        <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                            <span class="block truncate text-[14px] font-medium text-fg">{{ bill.student?.name ?? '—' }} · {{ billTitle(bill) }}</span>
                            <span class="block text-[12.5px] text-muted"><span class="whitespace-nowrap font-mono" dir="ltr">{{ bill.number }}</span> · {{ t('education_fees.counter.due', { date: day(bill.due_date) }) }}</span>
                        </span>
                        <AppBadge :tone="billState(bill).tone" dot>{{ t(`education_fees.bill_states.${billState(bill).key}`) }}</AppBadge>
                        <span class="tabular ms-auto w-32 text-end text-[14px] font-semibold text-fg">{{ setup.money(bill.status === 'open' ? bill.balance_minor : bill.total_minor) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </PageFrame>
</template>
