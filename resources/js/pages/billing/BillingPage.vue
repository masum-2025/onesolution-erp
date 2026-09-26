<script setup>
import { computed } from 'vue';
import { CalendarClock, Package, Receipt } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import InvoiceList from './InvoiceList.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * The client's own billing: the plan it is on, what it costs, and the
 * invoices and credit notes it received. Read-only.
 */
const org = currentOrganization();
const billing = useResource(() => api(`/api/organizations/${org.id}/billing`).then((response) => response.data));
const data = computed(() => billing.data.value);

// The next invoice covers the day after the last one ends.
const nextInvoice = computed(() => {
    if (!data.value?.billed_through) return null;
    const next = new Date(`${data.value.billed_through}T00:00:00Z`);
    next.setUTCDate(next.getUTCDate() + 1);
    return next.toISOString();
});
</script>

<template>
    <div>
        <PageHeader :title="t('billing.client.title')" :description="t('billing.client.text')" />

        <SkeletonRows v-if="billing.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="billing.error.value" :error="billing.error.value" @retry="billing.reload()" />

        <template v-else-if="data">
            <div class="mb-6 grid gap-4 sm:grid-cols-2">
                <div class="card p-5">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-[13px] font-medium text-fg-2">{{ t('billing.client.plan') }}</p>
                        <Package class="size-4 text-muted" aria-hidden="true" />
                    </div>
                    <p class="mt-2 text-[20px] font-semibold text-fg">{{ data.plan_name }}</p>
                    <p class="tabular mt-1 text-[13px] text-fg-2">
                        <template v-if="data.billed_by_provider">{{ t('billing.client.by_provider', { provider: data.provider }) }}</template>
                        <template v-else-if="data.price_minor !== null">{{ formatMoney({ amount: data.price_minor, currency: data.currency }) }} {{ t(`billing.per.${data.period}`) }}</template>
                        <template v-else>{{ t('billing.client.no_price') }}</template>
                    </p>
                </div>
                <div v-if="!data.billed_by_provider" class="card p-5">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-[13px] font-medium text-fg-2">{{ t('billing.client.next') }}</p>
                        <CalendarClock class="size-4 text-muted" aria-hidden="true" />
                    </div>
                    <p class="mt-2 text-[20px] font-semibold text-fg">{{ nextInvoice ? formatDate(nextInvoice) : t('billing.client.next_first') }}</p>
                    <p class="mt-1 text-[13px] text-muted">{{ t('billing.client.issued_by', { provider: data.provider }) }}</p>
                </div>
            </div>

            <section v-if="!data.billed_by_provider" class="card">
                <h2 class="border-b border-line px-5 py-3.5 text-[14px] font-semibold text-fg">{{ t('billing.client.invoices') }}</h2>
                <EmptyState v-if="!data.invoices.length" :icon="Receipt" :title="t('billing.client.empty_title')" :text="t('billing.client.empty_text')" compact />
                <InvoiceList v-else :invoices="data.invoices" base="/billing/invoices" />
            </section>
        </template>
    </div>
</template>
