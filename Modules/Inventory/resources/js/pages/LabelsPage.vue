<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { Minus, Plus, Printer, Search, Tags } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import { barcodeModules } from '../lib';

/**
 * Shelf and product labels: pick items and how many of each, then print on
 * an A4 sheet (3 × 8) or a label printer roll (one 50 × 30 mm label a
 * page). Each label carries the shop's name, the item, its price and its
 * barcode (EAN-13 when it has one, else Code 128 of its SKU), drawn here as
 * SVG, so nothing is sent anywhere to print.
 */
const inventory = inventoryApi(currentOrganization().id);
const route = useRoute();
const items = useResource(() => inventory.list('items', { active: 1 }));
const currency = computed(() => items.data.value?.meta?.currency);
const search = ref('');
const layout = ref('sheet');
const copies = reactive({});
// Coming from an item or the reorder list: those items ticked once.
for (const id of String(route.query.items ?? '').split(',').filter(Boolean)) copies[id] = 1;

const listed = computed(() => {
    const needle = search.value.trim().toLowerCase();
    return (items.data.value?.data ?? []).filter((item) => item.sale_price_minor !== null)
        .filter((item) => !needle || [item.sku, item.barcode, item.name].some((text) => String(text ?? '').toLowerCase().includes(needle)));
});
const change = (id, by) => {
    copies[id] = Math.max(0, Math.min(500, (copies[id] ?? 0) + by));
};
const labels = computed(() => (items.data.value?.data ?? []).flatMap((item) => {
    const code = item.barcode || item.sku;
    const modules = barcodeModules(code);
    return Array.from({ length: copies[item.id] ?? 0 }, (_, index) => ({ key: `${item.id}-${index}`, item, code, modules }));
}));
const count = computed(() => labels.value.length);
const shop = computed(() => currentOrganization().name);
const price = (item) => formatMoney({ amount: item.sale_price_minor, currency: currency.value });
// Bars of the SVG: each run of "1"s is one bar.
const bars = (modules) => {
    const out = [];
    for (let index = 0; index < modules.length; index++) {
        if (modules[index] !== '1') continue;
        let width = 1;
        while (modules[index + width] === '1') width++;
        out.push({ x: index, width });
        index += width - 1;
    }
    return out;
};
const printPage = () => window.print();
</script>

<template>
    <div>
        <div class="print:hidden">
            <PageHeader :title="t('inventory.labels.title')" :description="t('inventory.labels.text')">
                <template #actions>
                    <AppButton variant="primary" :icon="Printer" :disabled="!count" @click="printPage">{{ t('inventory.labels.print', { count: formatNumber(count) }) }}</AppButton>
                </template>
            </PageHeader>

            <section class="card mb-5 grid gap-4 p-5 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('inventory.labels.find')">
                    <span class="relative block">
                        <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                        <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('inventory.labels.find_hint')" />
                    </span>
                </AppField>
                <div>
                    <p class="field-label mb-1.5">{{ t('inventory.labels.layout') }}</p>
                    <AppSegmented v-model="layout" size="sm" :label="t('inventory.labels.layout')" :options="['sheet', 'roll'].map((value) => ({ value, label: t(`inventory.labels.layouts.${value}`) }))" />
                </div>
            </section>

            <section class="card mb-5">
                <SkeletonRows v-if="items.loading.value && !items.data.value" :rows="6" />
                <ErrorState v-else-if="items.error.value" compact :error="items.error.value" @retry="items.reload()" />
                <EmptyState v-else-if="!listed.length" :icon="Tags" :title="t('inventory.labels.empty')" compact />
                <ul v-else class="max-h-[420px] divide-y divide-line overflow-y-auto">
                    <li v-for="item in listed" :key="item.id" class="flex items-center gap-3 px-5 py-2.5">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium">{{ item.name }}</span>
                            <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ item.barcode || item.sku }} · {{ price(item) }}</span>
                        </span>
                        <span class="flex items-center gap-1">
                            <AppButton size="sm" variant="ghost" :icon="Minus" :aria-label="t('inventory.labels.fewer', { item: item.name })" :disabled="!copies[item.id]" @click="change(item.id, -1)" />
                            <span class="tabular w-8 text-center text-[14px] font-semibold">{{ formatNumber(copies[item.id] ?? 0) }}</span>
                            <AppButton size="sm" variant="ghost" :icon="Plus" :aria-label="t('inventory.labels.more', { item: item.name })" @click="change(item.id, 1)" />
                        </span>
                    </li>
                </ul>
            </section>
            <p v-if="count" class="mb-3 text-[13px] text-muted">{{ t('inventory.labels.preview') }}</p>
        </div>

        <!-- The labels themselves: a preview here, the pages when printed. -->
        <div :class="layout === 'sheet' ? 'labels-sheet' : 'labels-roll'">
            <article v-for="label in labels" :key="label.key" class="label" dir="ltr">
                <p class="label-shop">{{ shop }}</p>
                <p class="label-name">{{ label.item.name }}</p>
                <svg v-if="label.modules" class="label-bars" :viewBox="`0 0 ${label.modules.length + 20} 40`" preserveAspectRatio="none" role="img" :aria-label="label.code">
                    <rect v-for="bar in bars(label.modules)" :key="bar.x" :x="bar.x + 10" y="0" :width="bar.width" height="40" fill="#000" />
                </svg>
                <p class="label-foot"><span class="font-mono">{{ label.code }}</span><b>{{ price(label.item) }}</b></p>
            </article>
        </div>
    </div>
</template>

<style scoped>
.labels-sheet {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(62mm, 1fr));
    gap: 3mm;
}
.labels-roll {
    display: flex;
    flex-wrap: wrap;
    gap: 3mm;
}
.label {
    background: #fff;
    color: #000;
    border: 1px dashed #c9ced6;
    border-radius: 2mm;
    padding: 2mm 3mm;
    block-size: 34mm;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.labels-roll .label {
    inline-size: 50mm;
    block-size: 30mm;
}
.label-shop {
    font-size: 7pt;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.label-name {
    font-size: 9pt;
    font-weight: 600;
    line-height: 1.15;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.label-bars {
    flex: 1;
    inline-size: 100%;
    min-block-size: 8mm;
    margin-block: 1mm;
}
.label-foot {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 2mm;
    font-size: 8pt;
}
.label-foot b {
    font-size: 10pt;
}
@media print {
    .labels-sheet {
        grid-template-columns: repeat(3, 1fr);
        gap: 0;
    }
    .labels-sheet .label {
        block-size: 37mm;
        border: 0;
        border-radius: 0;
        break-inside: avoid;
    }
    .labels-roll {
        display: block;
    }
    .labels-roll .label {
        border: 0;
        break-after: page;
    }
}
</style>
