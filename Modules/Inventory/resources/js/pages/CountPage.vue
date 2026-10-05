<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Check, Save, ScanBarcode, Search, Send, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import { formatQuantity, milliToText, quantityToMilli, statusTone } from '../lib';

/**
 * One stock count: what the books expect and what was counted, item by item
 * (scan to find one, or add an item not expected); save as you go, send for
 * approval; someone else approves and the differences are posted.
 */
const inventory = inventoryApi(currentOrganization().id);
const route = useRoute();
const record = useResource(() => inventory.count(route.params.id));
const count = computed(() => record.data.value?.data ?? null);
const items = useResource(() => inventory.list('items', { active: 1 }));
const warehouses = useResource(() => inventory.list('warehouses'));
const itemOf = (id) => (items.data.value?.data ?? []).find((item) => item.id === id);
const warehouseName = computed(() => (warehouses.data.value?.data ?? []).find((row) => row.id === count.value?.warehouse_id)?.name ?? '');
const busy = ref(null);

// What was typed per item (empty = not counted).
const counted = reactive({});
const extra = ref([]);
watch(count, (value) => {
    for (const line of value?.lines ?? []) counted[line.item_id] = line.counted_milli === null ? '' : milliToText(line.counted_milli);
    extra.value = [];
}, { immediate: true });
const lines = computed(() => [...(count.value?.lines ?? []), ...extra.value.map((id) => ({ item_id: id, expected_milli: 0, counted_milli: null, value_minor: 0 }))]);
const filter = ref('');
const shown = computed(() => {
    const needle = filter.value.trim().toLowerCase();
    return lines.value.filter((line) => {
        const item = itemOf(line.item_id);
        return !needle || item?.name.toLowerCase().includes(needle) || item?.sku.toLowerCase().includes(needle) || item?.barcode === filter.value.trim();
    });
});
const done = computed(() => lines.value.filter((line) => String(counted[line.item_id] ?? '').trim() !== '').length);
const difference = (line) => {
    const value = quantityToMilli(counted[line.item_id], 3);
    return value === null ? null : value - line.expected_milli;
};

// Scanning finds the item's line (or adds it, expected nothing).
const scan = ref('');
function scanned() {
    const code = scan.value.trim();
    const item = (items.data.value?.data ?? []).find((row) => row.kind === 'stock' && (row.barcode === code || row.sku === code));
    if (!item) {
        toast.error(t('inventory.counts.not_found', { code }));
        return;
    }
    if (!lines.value.some((line) => line.item_id === item.id)) extra.value.push(item.id);
    filter.value = item.sku;
    scan.value = '';
}

async function saveCounts() {
    const body = [];
    for (const line of lines.value) {
        const text = String(counted[line.item_id] ?? '').trim();
        const value = text === '' ? null : quantityToMilli(text, 3);
        if (text !== '' && value === null) {
            toast.error(t('inventory.counts.bad_quantity', { item: itemOf(line.item_id)?.name ?? '' }));
            return false;
        }
        body.push({ item_id: line.item_id, counted_milli: value });
    }
    busy.value = 'save';
    try {
        await inventory.recordCount(count.value.id, { base_version: count.value.version, lines: body });
        toast.success(t('inventory.counts.saved'));
        await record.reload();
        return true;
    } catch (error) {
        toast.error(error.message);
        return false;
    } finally {
        busy.value = null;
    }
}

async function step(name) {
    const confirmed = await confirmAction({
        title: t(`inventory.counts.confirm.${name}_title`), message: t(`inventory.counts.confirm.${name}_text`, { done: formatNumber(done.value), total: formatNumber(lines.value.length) }),
        confirmLabel: t(`inventory.counts.steps.${name}`), reason: name === 'reject' ? 'required' : 'none', danger: ['reject', 'cancel'].includes(name),
    });
    if (!confirmed) return;
    if (name === 'submit' && !(await saveCounts())) return;
    busy.value = name;
    try {
        await inventory.countStep(count.value.id, name, { base_version: count.value.version, ...(name === 'reject' ? { reason: confirmed.reason } : {}) });
        toast.success(t(`inventory.counts.done.${name}`));
        record.reload();
    } catch (error) {
        if (error.code === 'version_conflict') record.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'inventory-counts' }" :icon="ArrowLeft" class="mb-3">{{ t('inventory.counts.title') }}</AppButton>
        <SkeletonRows v-if="record.loading.value && !count" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="count">
            <PageHeader :title="`${warehouseName} · ${count.number}`" :description="t('inventory.counts.page_text', { date: formatDate(`${count.counted_on}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) })">
                <template #eyebrow>
                    <span class="mb-2 flex items-center gap-2">
                        <AppBadge :tone="statusTone(count.status)" dot>{{ t(`inventory.status.${count.status}`) }}</AppBadge>
                        <span class="text-[12.5px] text-muted">{{ t('inventory.counts.progress', { done: formatNumber(done), total: formatNumber(lines.length) }) }}</span>
                    </span>
                </template>
                <template #actions>
                    <div class="flex flex-wrap gap-2">
                        <AppButton v-if="count.can.record" variant="secondary" :icon="Save" :loading="busy === 'save'" @click="saveCounts">{{ t('inventory.common.save') }}</AppButton>
                        <AppButton v-if="count.can.submit" variant="primary" :icon="Send" :loading="busy === 'submit'" @click="step('submit')">{{ t('inventory.counts.steps.submit') }}</AppButton>
                        <AppButton v-if="count.can.approve" variant="primary" :icon="Check" :loading="busy === 'approve'" @click="step('approve')">{{ t('inventory.counts.steps.approve') }}</AppButton>
                        <AppButton v-if="count.can.reject" variant="ghost" :icon="X" @click="step('reject')">{{ t('inventory.counts.steps.reject') }}</AppButton>
                        <AppButton v-if="count.can.cancel" variant="ghost" :icon="X" @click="step('cancel')">{{ t('inventory.counts.steps.cancel') }}</AppButton>
                    </div>
                </template>
            </PageHeader>

            <p v-if="count.reject_reason && count.status === 'counting'" class="mb-4 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="status">{{ t('inventory.document.sent_back', { reason: count.reject_reason }) }}</p>
            <p v-if="count.status === 'posted'" class="mb-4 rounded-xl bg-subtle px-4 py-3 text-[13px]" role="status">{{ t('inventory.counts.posted', { amount: formatMoney({ amount: count.variance_value_minor, currency: count.currency }) }) }}</p>

            <div class="mb-4 flex flex-wrap gap-3">
                <div v-if="count.can.record" class="relative w-full sm:w-72">
                    <ScanBarcode class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                    <input v-model="scan" class="field-input ps-9 font-mono" dir="ltr" :placeholder="t('inventory.document.scan')" :aria-label="t('inventory.document.scan')" @keydown.enter.prevent="scanned" />
                </div>
                <div class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                    <input v-model="filter" type="search" class="field-input ps-9" :placeholder="t('inventory.items.search')" :aria-label="t('inventory.items.search')" />
                </div>
            </div>

            <section class="card">
                <p v-if="!shown.length" class="px-5 py-6 text-center text-[13.5px] text-muted">{{ t('inventory.counts.no_lines') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="line in shown" :key="line.item_id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-2.5 text-[13.5px]">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ itemOf(line.item_id)?.name ?? '—' }} <span class="font-mono text-[11.5px] text-muted" dir="ltr">{{ itemOf(line.item_id)?.sku }}</span></span>
                            <span class="block text-[12px] text-muted">{{ t('inventory.counts.expected', { quantity: formatQuantity(line.expected_milli) }) }}</span>
                        </span>
                        <input v-if="count.can.record" v-model="counted[line.item_id]" inputmode="decimal" class="field-input w-28 tabular text-end" dir="ltr" :aria-label="t('inventory.counts.counted')" :placeholder="t('inventory.counts.counted')" />
                        <span v-else class="tabular w-20 text-end">{{ formatQuantity(line.counted_milli) }}</span>
                        <span class="tabular w-20 text-end font-semibold" :class="difference(line) < 0 ? 'text-bad' : difference(line) > 0 ? 'text-ok' : 'text-muted'">
                            {{ difference(line) === null ? '' : (difference(line) > 0 ? '+' : '') + formatQuantity(difference(line)) }}
                        </span>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
