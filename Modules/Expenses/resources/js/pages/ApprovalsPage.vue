<script setup>
import { computed, ref } from 'vue';
import { CheckCheck } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
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
 * Claims waiting for this person's decision: the employees they manage
 * first, the second step for the finance people above the limit, never
 * their own. Opened to see the lines and receipts, then approved or
 * rejected (with a reason).
 */
const org = currentOrganization();
const calls = expensesApi(org.id);
const list = useResource(() => calls.claims({ waiting: 1 }));
const items = computed(() => list.data.value?.data ?? []);
const viewing = ref(null);
</script>

<template>
    <div>
        <PageHeader :title="t('expenses.approvals.title')" :description="t('expenses.approvals.text')" />

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" :icon="CheckCheck" :title="t('expenses.approvals.empty')" :text="t('expenses.approvals.empty_text')" compact />
            <ClaimRows v-else :items="items" @open="(item) => (viewing = item.id)" />
        </section>

        <ClaimDetail :open="viewing !== null" :claim-id="viewing" :calls="calls" :payroll="!!list.data.value?.meta?.payroll" @close="viewing = null" @changed="list.reload()" />
    </div>
</template>
