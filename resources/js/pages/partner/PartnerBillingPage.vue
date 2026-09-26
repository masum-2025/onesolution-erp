<script setup>
import { computed, ref, watch } from 'vue';
import { Coins, HandCoins, Receipt, Wallet } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import InvoiceList from '../billing/InvoiceList.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { statusTone } from '@/lib/billing';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Partner console: billing. Wholesale partners see what we invoice them;
 * direct and revenue-share partners see what their clients were invoiced
 * under their brand, and (revenue share) their commissions and payouts.
 */
const summary = useResource(() => api('/api/partner/billing').then((response) => response.data));
const mode = computed(() => summary.data.value?.billing_mode ?? null);

const tabs = computed(() => {
    if (!mode.value) return [];
    if (mode.value === 'wholesale') return [{ key: 'ours', label: t('billing.partner.tabs.ours') }];
    return [
        { key: 'clients', label: t('billing.partner.tabs.clients') },
        ...(mode.value === 'revenue_share'
            ? [
                  { key: 'commissions', label: t('billing.partner.tabs.commissions') },
                  { key: 'payouts', label: t('billing.partner.tabs.payouts') },
              ]
            : []),
    ];
});
const tab = ref('');
watch(tabs, (list) => {
    if (!list.some((item) => item.key === tab.value)) tab.value = list[0]?.key ?? '';
});

const listing = useResource(
    () => {
        if (tab.value === 'commissions') return api('/api/partner/billing/commissions', { query: { per_page: 50 } });
        if (tab.value === 'payouts') return api('/api/partner/billing/payouts');
        return api('/api/partner/billing/invoices', { query: { billed_to: tab.value === 'clients' ? 'organization' : 'partner', per_page: 50 } });
    },
    { immediate: false, keepData: false },
);
watch(tab, (value) => value && listing.reload());
const rows = computed(() => listing.data.value?.data ?? []);

const cards = computed(() => {
    const data = summary.data.value;
    if (!data) return [];
    // Kept apart per currency; nothing owed shows as "nothing", not as zero in some currency.
    const totals = (list) => (list.length ? list.map((row) => formatMoney({ amount: row.total_minor, currency: row.currency })).join(' · ') : t('billing.partner.cards.nothing'));
    if (data.billing_mode === 'wholesale') {
        return [{ icon: Receipt, label: t('billing.partner.cards.we_bill_you'), value: totals(data.we_bill_you), hint: t('billing.partner.cards.we_bill_you_hint', { currency: data.currency }) }];
    }
    return [
        { icon: Receipt, label: t('billing.partner.cards.clients_owe'), value: totals(data.clients_owe), hint: t('billing.partner.cards.clients_owe_hint') },
        ...(data.billing_mode === 'revenue_share'
            ? [
                  { icon: Wallet, label: t('billing.partner.cards.payable'), value: totals(data.commissions.payable), hint: t('billing.partner.cards.payable_hint', { share: formatNumber(data.revenue_share_bp / 100) }) },
                  { icon: HandCoins, label: t('billing.partner.cards.pending'), value: totals(data.commissions.pending), hint: t('billing.partner.cards.pending_hint') },
              ]
            : []),
    ];
});
</script>

<template>
    <div>
        <PageHeader :title="t('billing.partner.title')" :description="mode ? t(`billing.partner.text_${mode}`) : ''" />

        <SkeletonRows v-if="summary.loading.value && !summary.data.value" :rows="3" />
        <ErrorState v-else-if="summary.error.value" :error="summary.error.value" @retry="summary.reload()" />

        <template v-else-if="summary.data.value">
            <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="card in cards" :key="card.label" class="card p-5">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-[13px] font-medium text-fg-2">{{ card.label }}</p>
                        <component :is="card.icon" class="size-4 text-muted" aria-hidden="true" />
                    </div>
                    <p class="tabular mt-2 text-[20px] font-semibold text-fg">{{ card.value }}</p>
                    <p class="mt-1 text-[12px] text-muted">{{ card.hint }}</p>
                </div>
            </div>

            <section class="card">
                <div class="px-5 pt-2">
                    <AppTabs v-model="tab" :tabs="tabs" :label="t('billing.partner.title')" />
                </div>

                <SkeletonRows v-if="listing.loading.value" :rows="5" />
                <ErrorState v-else-if="listing.error.value" compact :error="listing.error.value" @retry="listing.reload()" />
                <EmptyState v-else-if="!rows.length" :icon="Coins" :title="t(`billing.partner.empty.${tab}`)" :text="t('billing.partner.empty_text')" compact />

                <InvoiceList v-else-if="tab === 'ours' || tab === 'clients'" :invoices="rows" base="/partner/billing/invoices" :show-buyer="tab === 'clients'" />

                <ul v-else-if="tab === 'commissions'" class="divide-y divide-line">
                    <li v-for="row in rows" :key="row.id" class="flex items-center gap-3 px-5 py-3.5">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                                {{ row.client }}
                                <AppBadge :tone="statusTone(row.status)">{{ t(`billing.commission_status.${row.status}`) }}</AppBadge>
                            </p>
                            <p class="mt-0.5 text-[12.5px] text-muted">
                                <span class="tabular" dir="ltr">{{ row.invoice.number }}</span> ·
                                {{ t('billing.partner.share_of', { share: formatNumber(row.rate_bp / 100), amount: formatMoney({ amount: row.base_minor, currency: row.currency }) }) }} ·
                                {{ formatDate(row.created_at) }}
                            </p>
                        </div>
                        <span class="tabular shrink-0 text-[14px] font-semibold" :class="row.amount_minor < 0 ? 'text-bad' : 'text-fg'">{{ formatMoney({ amount: row.amount_minor, currency: row.currency }) }}</span>
                    </li>
                </ul>

                <ul v-else class="divide-y divide-line">
                    <li v-for="row in rows" :key="row.id" class="flex items-center gap-3 px-5 py-3.5">
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] font-medium text-fg">{{ formatDate(row.paid_at) }}</p>
                            <p class="mt-0.5 text-[12.5px] text-muted">{{ t('billing.partner.payout_line', { count: row.commissions, reference: row.reference }) }}</p>
                        </div>
                        <span class="tabular shrink-0 text-[14px] font-semibold text-ok">{{ formatMoney({ amount: row.amount_minor, currency: row.currency }) }}</span>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
