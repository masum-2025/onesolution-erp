<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { BookOpen, CalendarRange, ChevronDown, ChevronLeft, ChevronRight, FilePlus, FolderTree, Landmark, Search, Settings2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppMenu from '@/components/AppMenu.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { statusTone } from '../lib';
import BooksGate from '../components/BooksGate.vue';

/**
 * Journal entries, newest first: filter by status and dates, search by
 * number or narration. The same page lists entries waiting for approval
 * (route meta "approvals").
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();
const router = useRouter();
const approvals = computed(() => route.meta.approvals === true);

const more = computed(() => [
    { label: t('accounting.links.accounts'), icon: FolderTree, onSelect: () => router.push({ name: 'accounting-accounts' }) },
    { label: t('accounting.links.reports'), icon: Landmark, onSelect: () => router.push({ name: 'accounting-reports' }) },
    { label: t('accounting.links.fiscal_years'), icon: CalendarRange, onSelect: () => router.push({ name: 'accounting-fiscal-years' }) },
    { label: t('accounting.links.posting_accounts'), icon: Settings2, onSelect: () => router.push({ name: 'accounting-posting-accounts' }) },
]);

const search = ref(route.query.q ?? '');
const status = ref(route.query.status ?? '');
const from = ref(route.query.from ?? '');
const to = ref(route.query.to ?? '');
const page = ref(Number(route.query.page) || 1);
const statuses = ['draft', 'pending_approval', 'posted', 'rejected'];

const list = useResource(() =>
    books.journals({
        q: search.value.trim(),
        status: approvals.value ? 'pending_approval' : status.value,
        from: from.value,
        to: to.value,
        page: page.value,
        per_page: 25,
    }),
);
const journals = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
const filtering = computed(() => search.value.trim() !== '' || status.value !== '' || from.value !== '' || to.value !== '');

let timer = null;
watch([search, status, from, to], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        sync();
    }, 300);
});
watch(approvals, () => {
    page.value = 1;
    list.reload();
});
onBeforeUnmount(() => clearTimeout(timer));

function sync() {
    const query = Object.fromEntries(
        Object.entries({ q: search.value.trim(), status: status.value, from: from.value, to: to.value, page: page.value > 1 ? page.value : '' }).filter(([, value]) => value),
    );
    router.replace({ query });
    list.reload();
}

function go(to) {
    page.value = to;
    sync();
}

function clear() {
    search.value = '';
    status.value = '';
    from.value = '';
    to.value = '';
}
</script>

<template>
    <div>
        <PageHeader :title="approvals ? t('accounting.approvals.title') : t('accounting.title')" :description="approvals ? t('accounting.approvals.text') : t('accounting.text')">
            <template #actions>
                <AppMenu :items="more" :label="t('accounting.more')">
                    <template #trigger="{ toggle, attrs }">
                        <AppButton :icon-end="ChevronDown" v-bind="attrs" @click="toggle(false)">{{ t('accounting.more') }}</AppButton>
                    </template>
                </AppMenu>
                <AppButton v-if="can('accounting.post')" variant="primary" :to="{ name: 'accounting-journal-new' }" :icon="FilePlus">{{ t('accounting.new') }}</AppButton>
            </template>
        </PageHeader>

        <BooksGate>
            <div v-if="!approvals" class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_12rem_10rem_10rem_auto] lg:items-end">
                <AppField v-slot="{ id }" :label="t('accounting.filters.search')" sr-only-label>
                    <div class="relative">
                        <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                        <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('accounting.filters.search')" autocomplete="off" />
                    </div>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.filters.status')" sr-only-label>
                    <select :id="id" v-model="status" class="field-input">
                        <option value="">{{ t('accounting.filters.all_statuses') }}</option>
                        <option v-for="value in statuses" :key="value" :value="value">{{ t(`accounting.statuses.${value}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.filters.from')">
                    <input :id="id" v-model="from" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.filters.to')">
                    <input :id="id" v-model="to" type="date" class="field-input" :min="from || undefined" />
                </AppField>
                <AppButton v-if="filtering" variant="ghost" :icon="X" @click="clear">{{ t('accounting.filters.clear') }}</AppButton>
            </div>

            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="8" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState
                    v-else-if="!journals.length"
                    :icon="BookOpen"
                    :title="approvals ? t('accounting.list.approvals_empty_title') : filtering ? t('accounting.list.none_title') : t('accounting.list.empty_title')"
                    :text="approvals ? t('accounting.list.approvals_empty_text') : filtering ? t('accounting.list.none_text') : t('accounting.list.empty_text')"
                    compact
                >
                    <AppButton v-if="!approvals && !filtering && can('accounting.post')" variant="primary" :to="{ name: 'accounting-journal-new' }" :icon="FilePlus">{{ t('accounting.new') }}</AppButton>
                </EmptyState>

                <template v-else>
                    <header class="border-b border-line px-4 py-2.5 text-[12.5px] text-muted sm:px-5">
                        {{ t('accounting.list.count', { count: meta?.total ?? journals.length }) }}
                    </header>
                    <ul class="divide-y divide-line">
                        <li v-for="journal in journals" :key="journal.id">
                            <RouterLink :to="{ name: 'accounting-journal', params: { id: journal.id } }" class="flex items-center gap-3.5 px-4 py-3 transition hover:bg-subtle/60 sm:px-5">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[14px] font-medium text-fg">{{ journal.narration }}</span>
                                    <span class="mt-0.5 block truncate text-[12.5px] text-muted">
                                        <span v-if="journal.number" class="font-mono" dir="ltr">{{ journal.number }}</span>
                                        <template v-else>{{ t('accounting.list.no_number') }}</template>
                                        · {{ formatDate(journal.entry_date) }}
                                        <template v-if="journal.source"> · {{ t('accounting.list.from_module', { module: journal.source.module }) }}</template>
                                    </span>
                                </span>
                                <span class="tabular shrink-0 text-[13.5px] font-medium text-fg">{{ formatMoney({ amount: journal.total_minor, currency: journal.currency }) }}</span>
                                <AppBadge :tone="statusTone(journal.status)" dot class="hidden shrink-0 sm:inline-flex">{{ t(`accounting.statuses.${journal.status}`) }}</AppBadge>
                            </RouterLink>
                        </li>
                    </ul>
                </template>

                <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                    <span class="tabular text-[12.5px] text-muted">{{ t('accounting.list.page', { page: formatNumber(meta.page), pages: formatNumber(meta.last_page) }) }}</span>
                    <div class="flex gap-2">
                        <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('core.actions.previous') }}</AppButton>
                        <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('core.actions.next') }}</AppButton>
                    </div>
                </footer>
            </section>
        </BooksGate>
    </div>
</template>
