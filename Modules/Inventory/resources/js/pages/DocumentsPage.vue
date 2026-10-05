<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowDownToLine, ArrowRightLeft, ArrowUpFromLine, FileStack, SlidersHorizontal } from 'lucide-vue-next';
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
import { inventoryApi } from '../api';
import { DOCUMENT_TYPES, statusTone } from '../lib';

/** Receipts, issues, transfers and adjustments, newest first; filter by kind or status. */
const inventory = inventoryApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const type = ref(route.query.type ?? '');
const status = ref(route.query.status ?? '');
const list = useResource(() => inventory.documents({ ...(type.value ? { type: type.value } : {}), ...(status.value ? { status: status.value } : {}) }));
watch([type, status], () => {
    router.replace({ query: { ...(type.value ? { type: type.value } : {}), ...(status.value ? { status: status.value } : {}) } });
    list.reload();
});
const warehouses = useResource(() => inventory.list('warehouses'));
const warehouseName = (id) => (warehouses.data.value?.data ?? []).find((row) => row.id === id)?.name ?? '';
const documents = computed(() => list.data.value?.data ?? []);
const typeOptions = computed(() => [{ value: '', label: t('inventory.documents.all') }, ...DOCUMENT_TYPES.map((value) => ({ value, label: t(`inventory.types.${value}`) }))]);
const icons = { receipt: ArrowDownToLine, issue: ArrowUpFromLine, transfer: ArrowRightLeft, adjustment: SlidersHorizontal };
</script>

<template>
    <div>
        <PageHeader :title="t('inventory.documents.title')" :description="t('inventory.documents.text')">
            <template #actions>
                <div v-if="can('inventory.manage')" class="flex flex-wrap gap-2">
                    <AppButton v-for="value in DOCUMENT_TYPES" :key="value" :variant="value === 'receipt' ? 'primary' : 'secondary'" :icon="icons[value]" :to="{ name: 'inventory-document-new', params: { type: value } }">
                        {{ t(`inventory.types.${value}`) }}
                    </AppButton>
                </div>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <AppSegmented v-model="type" :options="typeOptions" :label="t('inventory.documents.filter')" />
            <select v-model="status" class="field-input w-auto" :aria-label="t('inventory.documents.status')">
                <option value="">{{ t('inventory.documents.any_status') }}</option>
                <option v-for="value in ['draft', 'pending_approval', 'in_transit', 'posted', 'cancelled']" :key="value" :value="value">{{ t(`inventory.status.${value}`) }}</option>
            </select>
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!documents.length" :icon="FileStack" :title="t('inventory.documents.empty')" :text="t('inventory.documents.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="document in documents" :key="document.id">
                    <RouterLink :to="{ name: 'inventory-document', params: { id: document.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-subtle text-fg"><component :is="icons[document.type]" class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ t(`inventory.types.${document.type}`) }} <span class="whitespace-nowrap font-mono text-[12px] text-muted" dir="ltr">{{ document.number ?? '' }}</span></span>
                            <span class="block text-[12.5px] text-muted">
                                {{ formatDate(`${document.document_date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) }} · {{ warehouseName(document.warehouse_id) }}<template v-if="document.to_warehouse_id"> → {{ warehouseName(document.to_warehouse_id) }}</template>
                                <template v-if="document.counterparty"> · {{ document.counterparty }}</template>
                            </span>
                        </span>
                        <AppBadge :tone="statusTone(document.status)" dot>{{ t(`inventory.status.${document.status}`) }}</AppBadge>
                        <span class="tabular w-32 text-end text-[14px] font-semibold">{{ document.status === 'draft' ? '—' : formatMoney({ amount: Math.abs(document.value_minor), currency: document.currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
