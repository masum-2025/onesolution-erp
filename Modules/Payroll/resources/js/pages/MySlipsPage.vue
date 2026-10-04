<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronRight, ReceiptText } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { payrollApi, portalPayrollApi } from '../api';

/** The reader's own payslips, newest first (approved months only), in the app or the portal. */
const route = useRoute();
const portal = route.meta.portal === true;
const list = useResource(() => (portal ? portalPayrollApi.slips() : payrollApi(currentOrganization().id).mySlips()));
const slips = computed(() => list.data.value?.data ?? []);
const monthName = (value) => formatDate(`${value}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' });
const target = (slip) => (portal ? { name: 'payroll-portal-slip', params: { slip: slip.id } } : { name: 'payroll-my-slip', params: { slip: slip.id } });
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('payroll.mine.title')" :description="t('payroll.mine.text')" />
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!slips.length" :icon="ReceiptText" :title="t('payroll.mine.empty')" :text="t('payroll.mine.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="slip in slips" :key="slip.id">
                    <RouterLink :to="target(slip)" class="flex items-center gap-4 px-5 py-3.5 hover:bg-surface-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><ReceiptText class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14.5px] font-semibold">{{ monthName(slip.period) }}</span>
                            <span class="block text-[12.5px] text-muted">{{ slip.paid_on ? t('payroll.mine.paid_on', { date: formatDate(`${slip.paid_on}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) }) : t('payroll.mine.approved') }}</span>
                        </span>
                        <span class="tabular text-[14.5px] font-semibold">{{ formatMoney({ amount: slip.net_minor, currency: slip.currency }) }}</span>
                        <ChevronRight class="size-4 text-muted rtl:rotate-180" aria-hidden="true" />
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
