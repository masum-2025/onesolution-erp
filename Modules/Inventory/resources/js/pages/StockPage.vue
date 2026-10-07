<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowDownToLine, ArrowRightLeft, ArrowUpFromLine, ClipboardList, Package, Search, SlidersHorizontal } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import { formatQuantity, levelTone } from '../lib';

/**
 * What is in stock: per item and warehouse, quantity in its unit and value
 * at cost; filter by warehouse, search, or show only what is low. The
 * buttons start a receipt, issue, transfer or adjustment.
 */
const inventory = inventoryApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const warehouseId = ref(route.query.warehouse_id ?? '');
const low = ref(route.query.low === '1');
const search = ref('');
const list = useResource(() => inventory.stock({ ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}), ...(low.value ? { low: 1 } : {}) }));
watch([warehouseId, low], () => {
    router.replace({ query: { ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}), ...(low.value ? { low: '1' } : {}) } });
    list.reload();
});
const items = useResource(() => inventory.list('items'));
const warehouses = useResource(() => inventory.list('warehouses'));
const units = useResource(() => inventory.list('units'));
const byId = (resource) => Object.fromEntries((resource.data.value?.data ?? []).map((row) => [row.id, row]));
const itemsById = computed(() => byId(items));
const warehousesById = computed(() => byId(warehouses));
const unitsById = computed(() => byId(units));
const currency = computed(() => list.data.value?.meta?.currency);

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return (list.data.value?.data ?? [])
        .map((row) => ({ ...row, item: itemsById.value[row.item_id], warehouse: warehousesById.value[row.warehouse_id] }))
        .filter((row) => row.item && (!needle || row.item.sku.toLowerCase().includes(needle) || row.item.name.toLowerCase().includes(needle) || row.item.barcode === search.value.trim()))
        .sort((a, b) => a.item.name.localeCompare(b.item.name));
});
const actions = [
    { type: 'receipt', icon: ArrowDownToLine },
    { type: 'issue', icon: ArrowUpFromLine },
    { type: 'transfer', icon: ArrowRightLeft },
    { type: 'adjustment', icon: SlidersHorizontal },
];
</script>

<template>
    <div>
        <PageHeader :title="t('inventory.stock.title')" :description="t('inventory.stock.text')">
            <template #actions>
                <div class="flex flex-wrap gap-2">
                    <template v-if="can('inventory.manage')">
                        <AppButton v-for="action in actions" :key="action.type" :variant="action.type === 'receipt' ? 'primary' : 'secondary'" :icon="action.icon" :to="{ name: 'inventory-document-new', params: { type: action.type } }">
                            {{ t(`inventory.types.${action.type}`) }}
                        </AppButton>
                    </template>
                    <AppButton variant="ghost" :icon="ClipboardList" :to="{ name: 'inventory-counts' }">{{ t('inventory.counts.title') }}</AppButton>
                </div>
            </template>
        </PageHeader>

        <section class="card mb-5 flex flex-wrap items-end gap-4 p-5">
            <div class="min-w-0 flex-1">
                <p class="text-[12.5px] text-muted">{{ t('inventory.stock.value') }}</p>
                <p class="tabular text-[22px] font-semibold text-brand-text">{{ list.error.value ? '—' : formatMoney({ amount: list.data.value?.meta?.value_minor ?? 0, currency }) }}</p>
            </div>
            <label class="grid gap-1 text-[12.5px] text-muted">
                {{ t('inventory.common.warehouse') }}
                <select v-model="warehouseId" class="field-input min-w-48">
                    <option value="">{{ t('inventory.stock.all_warehouses') }}</option>
                    <option v-for="warehouse in warehouses.data.value?.data ?? []" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                </select>
            </label>
            <AppSwitch v-model="low" :label="t('inventory.stock.only_low')" show-label />
        </section>

        <div class="relative mb-4 max-w-sm">
            <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
            <input v-model="search" type="search" class="field-input ps-9" :placeholder="t('inventory.stock.search')" :aria-label="t('inventory.stock.search')" />
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="Package" :title="t(low ? 'inventory.stock.none_low' : 'inventory.stock.empty')" :text="t('inventory.stock.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="`${row.item_id}-${row.warehouse_id}`">
                    <RouterLink :to="{ name: 'inventory-item', params: { id: row.item_id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ row.item.name }} <span class="whitespace-nowrap font-mono text-[12px] text-muted" dir="ltr">{{ row.item.sku }}</span></span>
                            <span class="block text-[12.5px] text-muted">{{ row.warehouse?.name }} · {{ t('inventory.stock.at_cost', { cost: formatMoney({ amount: row.unit_cost_minor, currency }) }) }}</span>
                        </span>
                        <AppBadge :tone="levelTone(row.quantity_milli, row.item.reorder_level_milli)" dot>
                            {{ formatQuantity(row.quantity_milli) }} {{ unitsById[row.item.unit_id]?.name ?? '' }}
                        </AppBadge>
                        <span class="tabular w-32 text-end text-[14px] font-semibold">{{ formatMoney({ amount: row.value_minor, currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
