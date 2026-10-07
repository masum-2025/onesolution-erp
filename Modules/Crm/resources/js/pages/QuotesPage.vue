<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { FileText, Plus, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { quoteTone } from '../lib';

/** Estimates and quotations, newest first: by kind, status or number. */
const crm = crmApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const kind = ref(route.query.kind ?? '');
const status = ref(route.query.status ?? '');
const search = ref(route.query.q ?? '');
const list = useResource(() => crm.quotes(Object.fromEntries(Object.entries({ kind: kind.value, status: status.value, q: search.value.trim() }).filter(([, value]) => value))));
let timer = null;
watch([kind, status, search], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.replace({ query: Object.fromEntries(Object.entries({ kind: kind.value, status: status.value, q: search.value }).filter(([, value]) => value)) });
        list.reload();
    }, 250);
});
const quotes = computed(() => list.data.value?.data ?? []);
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
</script>

<template>
    <div>
        <PageHeader :title="t('crm.quotes.title')" :description="t('crm.quotes.text')">
            <template #actions>
                <AppButton v-if="can('crm.edit')" variant="secondary" :icon="Plus" :to="{ name: 'crm-quote-new', params: { kind: 'estimate' } }">{{ t('crm.quotes.new_estimate') }}</AppButton>
                <AppButton v-if="can('crm.edit')" variant="primary" :icon="Plus" :to="{ name: 'crm-quote-new', params: { kind: 'quotation' } }">{{ t('crm.quotes.new_quotation') }}</AppButton>
            </template>
        </PageHeader>
        <section class="card mb-5 grid gap-3 p-4 md:grid-cols-[auto_1fr_auto] md:items-center">
            <AppSegmented v-model="kind" size="sm" :label="t('crm.quotes.kind')" :options="[{ value: '', label: t('crm.quotes.all') }, { value: 'estimate', label: t('crm.quote_kinds.estimate') }, { value: 'quotation', label: t('crm.quote_kinds.quotation') }]" />
            <label class="relative block">
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input v-model="search" type="search" class="field-input ps-9" :placeholder="t('crm.quotes.search')" :aria-label="t('crm.quotes.search')" />
            </label>
            <select v-model="status" class="field-input" :aria-label="t('crm.quotes.status')">
                <option value="">{{ t('crm.quotes.any_status') }}</option>
                <option v-for="value in ['draft', 'sent', 'accepted', 'declined', 'converted']" :key="value" :value="value">{{ t(`crm.quote_status.${value}`) }}</option>
            </select>
        </section>
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!quotes.length" :icon="FileText" :title="t('crm.quotes.empty')" :text="t('crm.quotes.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="quote in quotes" :key="quote.id">
                    <RouterLink :to="{ name: 'crm-quote', params: { id: quote.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium"><span class="font-mono" dir="ltr">{{ quote.number }}</span> · {{ quote.contact_company || quote.contact_name }}</span>
                            <span class="block text-[12.5px] text-muted">{{ t(`crm.quote_kinds.${quote.kind}`) }} · {{ day(quote.issue_date) }}<template v-if="quote.subject"> · {{ quote.subject }}</template></span>
                        </span>
                        <AppBadge :tone="quoteTone(quote)" dot>{{ t(quote.expired ? 'crm.quote_status.expired' : `crm.quote_status.${quote.status}`) }}</AppBadge>
                        <span class="tabular w-32 text-end text-[14px] font-semibold">{{ formatMoney({ amount: quote.total_minor, currency: quote.currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
