<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, History, PenLine, Tags, Warehouse } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import { formatQuantity, levelTone } from '../lib';
import ItemDialog from '../components/ItemDialog.vue';

/** One item: what it is, stock per warehouse, batches still held, and its last moves. */
const inventory = inventoryApi(currentOrganization().id);
const route = useRoute();
const record = useResource(() => inventory.item(route.params.id));
const item = computed(() => record.data.value?.data ?? null);
const currency = computed(() => record.data.value?.meta?.currency);
const warehouses = useResource(() => inventory.list('warehouses'));
const units = useResource(() => inventory.list('units'));
const warehouseName = (id) => (warehouses.data.value?.data ?? []).find((row) => row.id === id)?.name ?? '—';
const unitName = computed(() => (units.data.value?.data ?? []).find((unit) => unit.id === item.value?.unit_id)?.name ?? '');
const money = (amount) => formatMoney({ amount, currency: currency.value });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const total = computed(() => (item.value?.balances ?? []).reduce((sum, row) => sum + row.quantity_milli, 0));
const editing = ref(false);
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'inventory-items' }" :icon="ArrowLeft" class="mb-3">{{ t('inventory.items.title') }}</AppButton>
        <SkeletonRows v-if="record.loading.value && !item" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="item">
            <PageHeader :title="item.name" :description="item.description ?? ''">
                <template #eyebrow>
                    <span class="mb-2 flex flex-wrap items-center gap-2">
                        <span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ item.sku }}<template v-if="item.barcode"> · {{ item.barcode }}</template></span>
                        <AppBadge v-if="item.kind === 'non_stock'" tone="neutral">{{ t('inventory.kinds.non_stock') }}</AppBadge>
                        <AppBadge v-if="!item.is_active" tone="neutral">{{ t('inventory.common.off') }}</AppBadge>
                    </span>
                </template>
                <template #actions>
                    <AppButton v-if="item.sale_price_minor !== null" variant="ghost" :icon="Tags" :to="{ name: 'inventory-labels', query: { items: item.id } }">{{ t('inventory.labels.title') }}</AppButton>
                    <AppButton v-if="can('inventory.manage')" variant="secondary" :icon="PenLine" @click="editing = true">{{ t('inventory.common.edit') }}</AppButton>
                </template>
            </PageHeader>

            <section class="card mb-5 grid grid-cols-2 gap-4 p-5 sm:grid-cols-4">
                <div><p class="text-[12.5px] text-muted">{{ t('inventory.item.on_hand') }}</p><p class="tabular text-[18px] font-semibold">{{ formatQuantity(total) }} {{ unitName }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('inventory.items.price') }}</p><p class="tabular text-[18px] font-semibold">{{ item.sale_price_minor === null ? '—' : money(item.sale_price_minor) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('inventory.items.reorder') }}</p><p class="tabular text-[18px] font-semibold">{{ formatQuantity(item.reorder_level_milli) }}</p></div>
                <div><p class="text-[12.5px] text-muted">{{ t('inventory.item.value') }}</p><p class="tabular text-[18px] font-semibold text-brand-text">{{ money(item.balances.reduce((sum, row) => sum + row.value_minor, 0)) }}</p></div>
            </section>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <section class="card">
                    <h2 class="flex items-center gap-2 border-b border-line px-5 py-3 text-[14.5px] font-semibold"><Warehouse class="size-4 text-muted" aria-hidden="true" />{{ t('inventory.item.by_warehouse') }}</h2>
                    <p v-if="!item.balances.length" class="px-5 py-4 text-[13px] text-muted">{{ t('inventory.item.no_stock') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="row in item.balances" :key="row.warehouse_id" class="flex items-center gap-3 px-5 py-2.5 text-[13.5px]">
                            <span class="min-w-0 flex-1">{{ warehouseName(row.warehouse_id) }}</span>
                            <AppBadge :tone="levelTone(row.quantity_milli, item.reorder_level_milli)" dot>{{ formatQuantity(row.quantity_milli) }}</AppBadge>
                            <span class="tabular w-28 text-end">{{ money(row.value_minor) }}</span>
                        </li>
                    </ul>
                    <template v-if="item.batches.length">
                        <h3 class="border-y border-line bg-subtle/60 px-5 py-1.5 text-[12px] font-medium text-muted">{{ t('inventory.items.batches') }}</h3>
                        <ul class="divide-y divide-line">
                            <li v-for="batch in item.batches" :key="batch.id" class="flex items-center gap-3 px-5 py-2 text-[13px]">
                                <span class="min-w-0 flex-1 font-mono" dir="ltr">{{ batch.number }}</span>
                                <span class="text-muted">{{ batch.expires_on ? t('inventory.item.expires', { date: day(batch.expires_on) }) : '—' }}</span>
                                <span class="tabular w-20 text-end">{{ formatQuantity(batch.quantity_milli) }}</span>
                            </li>
                        </ul>
                    </template>
                </section>

                <section class="card">
                    <h2 class="flex items-center gap-2 border-b border-line px-5 py-3 text-[14.5px] font-semibold"><History class="size-4 text-muted" aria-hidden="true" />{{ t('inventory.item.moves') }}</h2>
                    <p v-if="!item.moves.length" class="px-5 py-4 text-[13px] text-muted">{{ t('inventory.item.no_moves') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="move in item.moves" :key="move.id" class="flex flex-wrap items-center gap-x-3 gap-y-0.5 px-5 py-2.5 text-[13px]">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">{{ t(`inventory.moves.${move.kind}`) }}</span>
                                <span class="block text-[12px] text-muted">{{ day(move.moved_on) }} · {{ warehouseName(move.warehouse_id) }}</span>
                            </span>
                            <span class="tabular font-semibold" :class="move.quantity_milli < 0 ? 'text-bad' : 'text-ok'">{{ move.quantity_milli > 0 ? '+' : '' }}{{ formatQuantity(move.quantity_milli) }}</span>
                            <span class="tabular w-24 text-end text-muted">{{ money(move.value_minor) }}</span>
                        </li>
                    </ul>
                </section>
            </div>
        </template>

        <ItemDialog v-if="editing && item" :item="item" :currency="currency" @close="editing = false" @saved="editing = false; record.reload()" />
    </div>
</template>
