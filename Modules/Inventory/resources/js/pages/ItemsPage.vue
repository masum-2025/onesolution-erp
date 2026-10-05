<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Package, Plus, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import ItemDialog from '../components/ItemDialog.vue';

/** The item list: search by name, SKU or barcode, filter by category; add or open an item. */
const inventory = inventoryApi(currentOrganization().id);
const search = ref('');
const categoryId = ref('');
const list = useResource(() => inventory.list('items', { ...(search.value.trim() ? { search: search.value.trim() } : {}), ...(categoryId.value ? { category_id: categoryId.value } : {}) }));
let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => list.reload(), 300);
});
watch(categoryId, () => list.reload());
const categories = useResource(() => inventory.list('categories'));
const units = useResource(() => inventory.list('units'));
const unitName = (id) => (units.data.value?.data ?? []).find((unit) => unit.id === id)?.name ?? '';
const categoryName = (id) => (categories.data.value?.data ?? []).find((category) => category.id === id)?.name ?? '';
const items = computed(() => list.data.value?.data ?? []);
const currency = computed(() => list.data.value?.meta?.currency);
const adding = reactive({ open: false });
</script>

<template>
    <div>
        <PageHeader :title="t('inventory.items.title')" :description="t('inventory.items.text')">
            <template #actions>
                <AppButton v-if="can('inventory.manage')" variant="primary" :icon="Plus" @click="adding.open = true">{{ t('inventory.items.add') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap gap-3">
            <div class="relative min-w-0 max-w-sm flex-1">
                <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                <input v-model="search" type="search" class="field-input ps-9" :placeholder="t('inventory.items.search')" :aria-label="t('inventory.items.search')" />
            </div>
            <select v-model="categoryId" class="field-input w-auto" :aria-label="t('inventory.items.category')">
                <option value="">{{ t('inventory.items.all_categories') }}</option>
                <option v-for="category in categories.data.value?.data ?? []" :key="category.id" :value="category.id">{{ category.name }}</option>
            </select>
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" :icon="Package" :title="t(search ? 'inventory.items.none_found' : 'inventory.items.empty')" :text="t('inventory.items.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="item in items" :key="item.id">
                    <RouterLink :to="{ name: 'inventory-item', params: { id: item.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ item.name }} <span class="whitespace-nowrap font-mono text-[12px] text-muted" dir="ltr">{{ item.sku }}</span></span>
                            <span class="block text-[12.5px] text-muted">
                                {{ unitName(item.unit_id) }}<template v-if="item.category_id"> · {{ categoryName(item.category_id) }}</template>
                                <template v-if="item.barcode"> · <span class="font-mono" dir="ltr">{{ item.barcode }}</span></template>
                            </span>
                        </span>
                        <AppBadge v-if="item.kind === 'non_stock'" tone="neutral">{{ t('inventory.kinds.non_stock') }}</AppBadge>
                        <AppBadge v-if="item.track_batches" tone="brand">{{ t('inventory.items.batches') }}</AppBadge>
                        <AppBadge v-if="!item.is_active" tone="neutral">{{ t('inventory.common.off') }}</AppBadge>
                        <span class="tabular w-28 text-end text-[14px] font-semibold">{{ item.sale_price_minor === null ? '—' : formatMoney({ amount: item.sale_price_minor, currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <ItemDialog v-if="adding.open" :currency="currency" @close="adding.open = false" @saved="adding.open = false; list.reload()" />
    </div>
</template>
