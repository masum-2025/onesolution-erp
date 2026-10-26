<script setup>
import { computed, ref, watch } from 'vue';
import { ReceiptText, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import ClaimDetail from '../components/ClaimDetail.vue';
import ClaimRows from '../components/ClaimRows.vue';
import { expensesApi } from '../api';

/**
 * Every sent claim of the unit's people, for the finance people and those
 * who oversee expenses: approved ones to pay back first, those waiting for
 * this person (the second step), the rest by status; a name or claim
 * number to find one. Drafts stay their writers' own.
 */
const org = currentOrganization();
const calls = expensesApi(org.id);

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

const views = computed(() => ['approved', 'mine', 'submitted', 'paid', 'rejected', 'all'].map((value) => ({ value, label: t(`expenses.claims.view_${value}`) })));
</script>

<template>
    <div>
        <PageHeader :title="t('expenses.claims.title')" :description="t('expenses.claims.text')" />

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="max-w-full overflow-x-auto">
                <AppSegmented v-model="view" size="sm" :label="t('expenses.claims.title')" :options="views" />
            </div>
            <label class="relative ms-auto w-full sm:w-72">
                <span class="sr-only">{{ t('expenses.claims.search') }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input v-model="typed" type="search" class="field-input ps-9" :placeholder="t('expenses.claims.search')" />
            </label>
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" :icon="ReceiptText" :title="t(q ? 'expenses.claims.no_match' : `expenses.claims.empty_${view}`)" compact />
            <ClaimRows v-else :items="items" @open="(item) => (viewing = item.id)" />
        </section>

        <ClaimDetail :open="viewing !== null" :claim-id="viewing" :calls="calls" :payroll="!!list.data.value?.meta?.payroll" @close="viewing = null" @changed="list.reload()" />
    </div>
</template>
