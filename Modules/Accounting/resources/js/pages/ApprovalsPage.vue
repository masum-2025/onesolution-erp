<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CheckCheck } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import BooksGate from '../components/BooksGate.vue';

/**
 * Everything waiting for a second person: journal entries, documents and
 * money records above the approval amount. Each opens its own page, where
 * the server says whether this reader may approve it.
 */
const books = accountingApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const tab = ref(['journals', 'documents', 'settlements'].includes(route.query.tab) ? route.query.tab : 'journals');

const waiting = { status: 'pending_approval', per_page: 50 };
const lists = {
    journals: useResource(() => books.journals(waiting)),
    documents: useResource(() => books.documents(waiting)),
    settlements: useResource(() => books.settlements(waiting)),
};
const tabs = computed(() =>
    ['journals', 'documents', 'settlements'].map((key) => ({ key, label: t(`accounting.approvals_page.${key}`), count: lists[key].data.value?.meta?.total ?? undefined })),
);
const current = computed(() => lists[tab.value]);
const rows = computed(() => current.value.data.value?.data ?? []);
watch(tab, () => router.replace({ query: { tab: tab.value } }));

const row = (item) =>
    ({
        journals: { to: { name: 'accounting-journal', params: { id: item.id } }, title: item.narration, meta: formatDate(item.entry_date), amount: item.total_minor },
        documents: { to: { name: 'accounting-document', params: { id: item.id } }, title: `${t(`accounting.kinds.${item.type}`)} · ${item.party_name}`, meta: formatDate(item.issue_date), amount: item.total_minor },
        settlements: { to: { name: 'accounting-settlement', params: { id: item.id } }, title: `${t(`accounting.kinds.${item.type}`)} · ${item.party_name}`, meta: formatDate(item.settled_on), amount: item.amount_minor },
    })[tab.value];
</script>

<template>
    <div>
        <PageHeader :title="t('accounting.approvals.title')" :description="t('accounting.approvals.text')" />
        <BooksGate>
            <AppTabs v-model="tab" :tabs="tabs" :label="t('accounting.approvals.title')" class="mb-4" />
            <section class="card">
                <SkeletonRows v-if="current.loading.value && !current.data.value" :rows="5" />
                <ErrorState v-else-if="current.error.value" compact :error="current.error.value" @retry="current.reload()" />
                <EmptyState v-else-if="!rows.length" :icon="CheckCheck" :title="t('accounting.list.approvals_empty_title')" :text="t('accounting.list.approvals_empty_text')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in rows" :key="item.id">
                        <RouterLink :to="row(item).to" class="flex items-center gap-3 px-5 py-3 transition hover:bg-subtle/60">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium">{{ row(item).title }}</span>
                                <span class="block text-[12.5px] text-muted">{{ row(item).meta }}</span>
                            </span>
                            <span class="tabular text-[13.5px] font-medium">{{ formatMoney({ amount: row(item).amount, currency: item.currency }) }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </BooksGate>
    </div>
</template>
