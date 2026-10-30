<script setup>
import { computed, ref, watch } from 'vue';
import { RefreshCw } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { complianceApi } from '../api';
import { renewalTone } from '../lib';
import AuditDrawer from '../components/AuditDrawer.vue';
import QuoteCard from '../components/QuoteCard.vue';
import ResultCard from '../components/ResultCard.vue';

/**
 * Renewals with the providers: results waiting to be checked first (they
 * change the register), then the requests sent and what the providers
 * answered. A line opens in the register's drawer.
 */
const org = currentOrganization();
const calls = complianceApi(org.id);
const which = ref('open');
const parts = computed(() => [{ value: 'open', label: t('compliance.renewals.open') }, { value: 'all', label: t('compliance.renewals.all') }]);
const data = useResource(() => calls.renewals(which.value === 'open'));
watch(which, () => data.reload());

const requests = computed(() => data.data.value?.data.requests ?? []);
const waiting = computed(() => (data.data.value?.data.results ?? []).filter((result) => result.status === 'pending'));
// Quotations from connected providers: pending first (open shows only them).
const quotes = computed(() => [...(data.data.value?.data.quotes ?? [])].sort((a, b) => (a.status === 'pending' ? 0 : 1) - (b.status === 'pending' ? 0 : 1)));
const decided = computed(() => (data.data.value?.data.results ?? []).filter((result) => result.status !== 'pending'));
const openId = ref(null);
</script>

<template>
    <div>
        <PageHeader :title="t('compliance.renewals.title')" :description="t('compliance.renewals.text')" />

        <SkeletonRows v-if="data.loading.value && !data.data.value" :rows="5" />
        <ErrorState v-else-if="data.error.value" :error="data.error.value" @retry="data.reload()" />
        <template v-else>
            <section v-if="quotes.length" class="mb-6" :aria-label="t('compliance.quotes.section')">
                <h2 class="mb-2 text-[14.5px] font-semibold">{{ t('compliance.quotes.section') }}</h2>
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <QuoteCard v-for="quote in quotes" :key="quote.id" :quote="quote" :calls="calls" show-factory @decided="data.reload()" />
                </div>
            </section>

            <section v-if="waiting.length" class="mb-6" :aria-label="t('compliance.renewals.results_title')">
                <h2 class="mb-2 text-[14.5px] font-semibold">{{ t('compliance.renewals.results_title') }}</h2>
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <ResultCard v-for="result in waiting" :key="result.id" :result="result" :calls="calls" show-factory @decided="data.reload()" />
                </div>
            </section>

            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-[14.5px] font-semibold">{{ t('compliance.renewals.requests_title') }}</h2>
                <AppSegmented v-model="which" size="sm" :label="t('compliance.renewals.requests_title')" :options="parts" />
            </div>
            <section class="card">
                <EmptyState v-if="!requests.length" :icon="RefreshCw" compact :title="t('compliance.renewals.none_title')" :text="t('compliance.renewals.none_text')" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="request in requests" :key="request.id">
                        <button type="button" class="flex w-full flex-wrap items-center gap-3 px-5 py-3 text-start transition hover:bg-subtle/60" @click="openId = request.audit_id">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium">{{ request.service?.name ?? '—' }} · {{ request.factory.name }}</span>
                                <span class="block truncate text-[12.5px] text-muted">
                                    {{ request.provider?.name }} · {{ t('compliance.renewal.asked_on', { date: formatDate(request.created_at) }) }}
                                    <template v-if="request.expires_on"> · {{ t('compliance.renewals.expires', { date: formatDate(request.expires_on) }) }}</template>
                                    <template v-if="request.planned_on"> · {{ t('compliance.renewal.planned_value', { date: formatDate(request.planned_on) }) }}</template>
                                </span>
                                <span v-if="request.delivery === 'failed'" class="block text-[12.5px] text-bad">{{ t('compliance.renewal.not_delivered') }}</span>
                            </span>
                            <AppBadge :tone="renewalTone(request.status)">{{ t(`compliance.renewal_statuses.${request.status}`) }}</AppBadge>
                        </button>
                    </li>
                </ul>
            </section>

            <section v-if="which === 'all' && decided.length" class="mt-6" :aria-label="t('compliance.renewals.decided_title')">
                <h2 class="mb-2 text-[14.5px] font-semibold">{{ t('compliance.renewals.decided_title') }}</h2>
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <ResultCard v-for="result in decided" :key="result.id" :result="result" :calls="calls" show-factory />
                </div>
            </section>
        </template>

        <AuditDrawer :open="openId !== null" :audit-id="openId" :calls="calls" @close="openId = null" @changed="data.reload()" />
    </div>
</template>
