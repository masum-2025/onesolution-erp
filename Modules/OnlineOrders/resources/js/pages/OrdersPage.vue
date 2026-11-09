<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Package, Plus, Search } from 'lucide-vue-next';
import { AppButton, EmptyState, ErrorState, PageFrame, SkeletonRows, StatusBadge } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { onlineOrdersApi, STATUS_TONES } from '../api';

/** Online orders, newest first: by status, by order number or the customer's mobile number. */
const orders = onlineOrdersApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const status = ref(route.query.status ?? '');
const search = ref('');
const list = useResource(() => orders.orders({ ...(status.value ? { status: status.value } : {}), ...(search.value.trim() ? { search: search.value.trim() } : {}) }));
const options = useResource(() => orders.options());
watch(status, () => {
    router.replace({ query: status.value ? { status: status.value } : {} });
    list.reload();
});
const rows = computed(() => list.data.value?.data ?? []);
const canOrder = computed(() => options.data.value?.data?.can_order === true);
const STATUSES = ['draft', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled', 'returned'];

const exported = computed(() => ({
    module: 'online_orders',
    page: 'online_orders.orders',
    columns: [
        { key: 'number', label: t('online_orders.fields.number') },
        { key: 'created_at', label: t('online_orders.fields.date'), value: (row) => formatDateTime(row.created_at) },
        { key: 'customer_name', label: t('online_orders.fields.customer') },
        { key: 'customer_phone', label: t('online_orders.fields.phone') },
        { key: 'status', label: t('online_orders.fields.status'), value: (row) => t(`online_orders.status.${row.status}`) },
        { key: 'payment_method', label: t('online_orders.fields.payment'), value: (row) => t(`online_orders.payment.${row.payment_method}`) },
        { key: 'total_minor', label: t('online_orders.fields.total'), type: 'money' },
        { key: 'due_minor', label: t('online_orders.fields.due'), type: 'money' },
    ],
    rows: rows.value,
}));
</script>

<template>
    <PageFrame :title="t('online_orders.list.title')" :description="t('online_orders.list.text')" width="full" :export="exported">
        <template #actions>
            <AppButton v-if="canOrder" variant="primary" :icon="Plus" :to="{ name: 'online-order-new' }">{{ t('online_orders.list.new') }}</AppButton>
        </template>
        <section class="card mb-4 flex flex-wrap items-end gap-4 p-4 print:hidden">
            <label class="grid gap-1 text-[12.5px] text-muted">
                {{ t('online_orders.fields.status') }}
                <select v-model="status" class="field-input min-w-40">
                    <option value="">{{ t('online_orders.list.all') }}</option>
                    <option v-for="value in STATUSES" :key="value" :value="value">{{ t(`online_orders.status.${value}`) }}</option>
                </select>
            </label>
            <div class="relative min-w-0 max-w-xs flex-1">
                <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                <input v-model="search" class="field-input ps-9" dir="ltr" :placeholder="t('online_orders.list.search')" :aria-label="t('online_orders.list.search')" @keydown.enter="list.reload()" />
            </div>
        </section>
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="Package" :title="t('online_orders.list.empty')" :text="t('online_orders.list.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="order in rows" :key="order.id">
                    <RouterLink :to="{ name: 'online-order', params: { id: order.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block font-mono text-[13.5px] font-medium" dir="ltr">{{ order.number }}</span>
                            <span class="block text-[12.5px] text-muted">{{ formatDateTime(order.created_at) }} · {{ order.customer_name }} · <span dir="ltr">{{ order.customer_phone }}</span></span>
                        </span>
                        <StatusBadge :status="order.status" :tones="STATUS_TONES">{{ t(`online_orders.status.${order.status}`) }}</StatusBadge>
                        <span class="tabular w-32 text-end">
                            <span class="block text-[14px] font-semibold">{{ formatMoney({ amount: order.total_minor, currency: order.currency }) }}</span>
                            <span v-if="order.due_minor > 0 && order.status !== 'draft'" class="block text-[12px] text-warn">{{ t('online_orders.list.due', { amount: formatMoney({ amount: order.due_minor, currency: order.currency }) }) }}</span>
                        </span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </PageFrame>
</template>
