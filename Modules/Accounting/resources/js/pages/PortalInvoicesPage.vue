<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronRight, ReceiptText } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { documentTone, isCredit, statusKey } from '../lib';

/**
 * A portal member's own invoices (B2B2C): what each linked customer still
 * owes, and their invoices and credit notes. The server sends only theirs.
 */
const route = useRoute();
const resource = useResource(() => api('/api/portal/accounting/invoices', { query: { customer: route.query.customer } }).then((response) => response.data));
const data = computed(() => resource.data.value);
const money = (amount) => formatMoney({ amount, currency: data.value?.currency });
</script>

<template>
    <div class="mx-auto max-w-2xl">
        <PageHeader :title="t('accounting.portal_page.title')" :description="data ? t('accounting.portal_page.text', { org: data.organization }) : ''" />
        <SkeletonRows v-if="resource.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />
        <template v-else-if="data">
            <section v-for="customer in data.customers" :key="customer.id" class="card mb-4 flex items-center justify-between p-5">
                <div>
                    <div class="text-[13px] text-muted">{{ customer.name }}</div>
                    <div class="mt-0.5 text-[12.5px] text-muted">{{ customer.balance_minor > 0 ? t('accounting.portal_page.owes') : t('accounting.portal_page.paid_up') }}</div>
                </div>
                <div class="tabular text-[22px] font-semibold" :class="customer.balance_minor > 0 ? 'text-fg' : 'text-ok'">{{ money(Math.max(customer.balance_minor, 0)) }}</div>
            </section>

            <EmptyState v-if="!data.documents.length" :icon="ReceiptText" :title="t('accounting.portal_page.empty_title')" :text="t('accounting.portal_page.empty_text', { org: data.organization })" />
            <ul v-else class="card divide-y divide-line">
                <li v-for="document in data.documents" :key="document.id">
                    <RouterLink :to="`/portal/invoices/${document.id}`" class="flex items-center gap-3 px-5 py-3.5 transition hover:bg-subtle/60">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ t(`accounting.kinds.${document.type}`) }} <span class="font-mono" dir="ltr">{{ document.number }}</span></span>
                            <span class="block text-[12.5px] text-muted">{{ formatDate(document.issue_date) }}<template v-if="document.due_date && document.balance_minor > 0"> · {{ t('accounting.portal_page.due', { date: formatDate(document.due_date) }) }}</template></span>
                        </span>
                        <span class="text-end">
                            <span class="tabular block text-[14px] font-medium">{{ money(isCredit(document.type) ? -document.total_minor : document.total_minor) }}</span>
                            <AppBadge :tone="documentTone(document.status)" dot>{{ t(statusKey(document.type, document.status)) }}</AppBadge>
                        </span>
                        <ChevronRight class="size-4 shrink-0 text-faint rtl:rotate-180" aria-hidden="true" />
                    </RouterLink>
                </li>
            </ul>
        </template>
    </div>
</template>
