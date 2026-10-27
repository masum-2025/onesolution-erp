<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { HandCoins, ReceiptText, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import AdvanceDrawer from '../components/AdvanceDrawer.vue';
import ClaimDetail from '../components/ClaimDetail.vue';
import ClaimRows from '../components/ClaimRows.vue';
import { expensesApi } from '../api';
import { advanceTone, amountText } from '../lib';

/**
 * Every sent claim of the unit's people, for the finance people and those
 * who oversee expenses: approved ones to pay back first, those waiting for
 * this person (the second step), the rest by status; a name or claim
 * number to find one. Drafts stay their writers' own. The advances part:
 * waiting for this person, to pay out, paid (still owed), past their day.
 */
const route = useRoute();
const org = currentOrganization();
const calls = expensesApi(org.id);

const part = ref(route.query.view === 'advances' ? 'advances' : 'claims');
const view = ref('approved');
const typed = ref('');
const q = ref('');
let timer = null;
watch(typed, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => (q.value = value.trim()), 300);
});

const query = () => ({
    ...(view.value === 'mine' ? { waiting: 1 } : view.value === 'all' ? {} : { status: view.value }),
    q: q.value || undefined,
});
const list = useResource(() => calls.claims(query()));
const items = computed(() => list.data.value?.data ?? []);
watch([view, q], () => list.reload());
const viewing = ref(null);

const advanceView = ref(route.query.view === 'advances' ? 'overdue' : 'waiting');
const advanceList = useResource(() => calls.advances(advanceView.value === 'all' ? undefined : advanceView.value));
const advances = computed(() => (advanceList.data.value?.data ?? []).filter((item) => !q.value || `${item.employee.name} ${item.employee.code} ${item.number} ${item.purpose}`.toLowerCase().includes(q.value.toLowerCase())));
watch(advanceView, () => advanceList.reload());
const advanceOpen = ref(null);

const views = computed(() => ['approved', 'mine', 'submitted', 'paid', 'rejected', 'all'].map((value) => ({ value, label: t(`expenses.claims.view_${value}`) })));
const advanceViews = computed(() => ['waiting', 'approved', 'paid', 'overdue', 'all'].map((value) => ({ value, label: t(`expenses.advance.view_${value}`) })));
const parts = computed(() => [{ value: 'claims', label: t('expenses.claims.part_claims') }, { value: 'advances', label: t('expenses.claims.part_advances') }]);
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
</script>

<template>
    <div>
        <PageHeader :title="t('expenses.claims.title')" :description="t('expenses.claims.text')">
            <template #actions>
                <AppSegmented v-model="part" size="sm" :label="t('expenses.claims.title')" :options="parts" />
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="max-w-full overflow-x-auto">
                <AppSegmented v-if="part === 'claims'" v-model="view" size="sm" :label="t('expenses.claims.part_claims')" :options="views" />
                <AppSegmented v-else v-model="advanceView" size="sm" :label="t('expenses.claims.part_advances')" :options="advanceViews" />
            </div>
            <label class="relative ms-auto w-full sm:w-72">
                <span class="sr-only">{{ t('expenses.claims.search') }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input v-model="typed" type="search" class="field-input ps-9" :placeholder="t('expenses.claims.search')" />
            </label>
        </div>

        <section v-if="part === 'claims'" class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" :icon="ReceiptText" :title="t(q ? 'expenses.claims.no_match' : `expenses.claims.empty_${view}`)" compact />
            <ClaimRows v-else :items="items" @open="(item) => (viewing = item.id)" />
        </section>
        <section v-else class="card">
            <SkeletonRows v-if="advanceList.loading.value && !advanceList.data.value" :rows="4" />
            <ErrorState v-else-if="advanceList.error.value" compact :error="advanceList.error.value" @retry="advanceList.reload()" />
            <EmptyState v-else-if="!advances.length" :icon="HandCoins" :title="t(`expenses.advance.empty_${advanceView}`)" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="item in advances" :key="item.id">
                    <button type="button" class="grid w-full gap-1 px-5 py-3 text-start hover:bg-subtle sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-4" @click="advanceOpen = item.id">
                        <span class="min-w-0">
                            <span class="block text-[14px] font-medium">{{ item.employee.name }} <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ item.employee.code }}</span></span>
                            <span class="block truncate text-[13px]">{{ item.purpose }}</span>
                            <span class="block text-[12.5px] text-muted">
                                <span class="font-mono" dir="ltr">{{ item.number }}</span>
                                <template v-if="item.status === 'paid'"> · {{ t('expenses.advance.left', { amount: amountText(item.outstanding_minor, item.currency) }) }}</template>
                                <template v-if="item.settle_by && item.status === 'paid'"> · {{ t('expenses.advance.settle_by_on', { date: day(item.settle_by) }) }}</template>
                            </span>
                        </span>
                        <span class="flex items-center gap-3">
                            <span class="tabular text-[14px] font-semibold">{{ amountText(item.amount_minor, item.currency) }}</span>
                            <AppBadge v-if="item.overdue" tone="bad">{{ t('expenses.advance.overdue') }}</AppBadge>
                            <AppBadge :tone="advanceTone(item.status)">{{ t(`expenses.advance_status.${item.status}`) }}</AppBadge>
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <ClaimDetail :open="viewing !== null" :claim-id="viewing" :calls="calls" :payroll="!!list.data.value?.meta?.payroll" @close="viewing = null" @changed="list.reload()" />
        <AdvanceDrawer :open="advanceOpen !== null" :advance-id="advanceOpen" :payroll="!!list.data.value?.meta?.payroll" :calls="calls" @close="advanceOpen = null" @changed="advanceList.reload()" />
    </div>
</template>
