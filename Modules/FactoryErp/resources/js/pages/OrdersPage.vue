<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ClipboardList, Plus, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { orderTone } from '../lib';
import OrderDialog from '../components/OrderDialog.vue';

/**
 * Buyers' orders at the unit, by ship date: status tabs, a search by order
 * or PO number, pieces and value of each. "New order" picks the style and
 * opens the order to fill in its sizes and colours.
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const route = useRoute();
const router = useRouter();
const STATUSES = ['draft', 'confirmed', 'in_production', 'shipped', 'cancelled'];

const status = computed({
    get: () => (STATUSES.includes(route.query.status) ? route.query.status : 'open'),
    set: (value) => router.replace({ query: { ...route.query, status: value === 'open' ? undefined : value, page: undefined } }),
});
const search = ref(typeof route.query.q === 'string' ? route.query.q : '');
const page = ref(1);
const list = useResource(() => api.orders({ status: status.value === 'open' ? '' : status.value, q: search.value.trim(), page: page.value, per_page: 25 }));
let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        list.reload();
    }, 300);
});
watch(() => route.query.status, () => {
    page.value = 1;
    list.reload();
});
const rows = computed(() => (list.data.value?.data ?? []).filter((order) => status.value !== 'open' || !['shipped', 'cancelled'].includes(order.status)));
const meta = computed(() => list.data.value?.meta ?? {});
const tabs = computed(() => ['open', ...STATUSES].map((key) => ({ key, label: t(`factory_erp.tabs.${key}`) })));
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });

const making = ref(false);
watch([() => route.query.new, () => setup.data.value], ([value, data]) => {
    if (value && data) {
        making.value = true;
        router.replace({ query: { ...route.query, new: undefined } });
    }
}, { immediate: true });
</script>

<template>
    <div>
        <PageHeader :title="t('factory_erp.orders.title')" :description="t('factory_erp.orders.text')">
            <template v-if="setup.can('merchandise')" #actions>
                <AppButton variant="primary" :icon="Plus" @click="making = true">{{ t('factory_erp.orders.new') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-3 overflow-x-auto"><AppTabs v-model="status" :tabs="tabs" :label="t('factory_erp.orders.title')" /></div>
        <AppField v-slot="{ id }" :label="t('factory_erp.orders.search')" sr-only-label class="mb-4 sm:w-96">
            <span class="relative block">
                <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('factory_erp.orders.search')" autocomplete="off" />
            </span>
        </AppField>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <div v-else-if="!rows.length" class="card">
            <EmptyState :icon="ClipboardList" :title="t('factory_erp.orders.empty_title')" :text="t('factory_erp.orders.empty_text')">
                <AppButton v-if="setup.can('merchandise')" variant="primary" :icon="Plus" @click="making = true">{{ t('factory_erp.orders.new') }}</AppButton>
            </EmptyState>
        </div>
        <section v-else class="card">
            <ul class="divide-y divide-line">
                <li v-for="order in rows" :key="order.id">
                    <RouterLink :to="{ name: 'fac-order', params: { id: order.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                        <span class="min-w-0 flex-1 basis-56">
                            <span class="block text-[14px] font-semibold text-fg">
                                <span class="font-mono" dir="ltr">{{ order.number }}</span>
                                <span class="font-normal text-muted"> · {{ meta.styles?.[order.style_id]?.code }} {{ meta.styles?.[order.style_id]?.name }}</span>
                            </span>
                            <span class="block text-[12.5px] text-muted">
                                {{ meta.buyers?.[order.buyer_id]?.name ?? '—' }}<template v-if="order.po_number"> · PO <span dir="ltr">{{ order.po_number }}</span></template>
                            </span>
                        </span>
                        <span class="text-end">
                            <span class="tabular block text-[14px] font-semibold text-fg">{{ t('factory_erp.pieces', { count: formatNumber(order.pieces) }) }}</span>
                            <span class="tabular block text-[12px] text-muted">{{ formatMoney({ amount: order.value_minor, currency: order.currency }) }}</span>
                        </span>
                        <span class="w-28 text-end text-[12.5px] text-muted">{{ t('factory_erp.orders.ships', { date: day(order.ship_on) }) }}</span>
                        <AppBadge :tone="orderTone(order.status)" dot>{{ t(`factory_erp.statuses.${order.status}`) }}</AppBadge>
                    </RouterLink>
                </li>
            </ul>
            <nav v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3" :aria-label="t('factory_erp.pages')">
                <AppButton size="sm" variant="ghost" :disabled="page <= 1" @click="page--; list.reload()">{{ t('factory_erp.previous') }}</AppButton>
                <span class="tabular text-[12.5px] text-muted">{{ t('factory_erp.page', { page, pages: meta.last_page }) }}</span>
                <AppButton size="sm" variant="ghost" :disabled="page >= meta.last_page" @click="page++; list.reload()">{{ t('factory_erp.next') }}</AppButton>
            </nav>
        </section>

        <OrderDialog :open="making" @close="making = false" />
    </div>
</template>
