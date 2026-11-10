<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Boxes, ShieldAlert, Undo2 } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { pharmacyApi, pieces } from '../api';

/** Medicine batches with their expiry: all, expiring soon, due back to the supplier, or expired (red first). */
const ph = pharmacyApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const show = ref(['all', 'near', 'return', 'expired'].includes(route.query.show) ? route.query.show : 'all');
const list = useResource(() => ph.stock({ show: show.value }));
watch(show, () => {
    router.replace({ query: show.value === 'all' ? {} : { show: show.value } });
    list.reload();
});
const rows = computed(() => list.data.value?.data ?? []);
const tones = { expired: 'bad', near: 'bad', return: 'warn', ok: 'ok' };
const choices = computed(() => ['all', 'near', 'return', 'expired'].map((value) => ({ value, label: t(`pharmacy.stock.show.${value}`) })));
function returnIt(row) {
    router.push({ name: 'pharmacy-returns', query: { medicine: row.medicine_id, batch: row.batch_number, warehouse: row.warehouse_id, quantity: pieces(row.quantity_milli) } });
}
const exported = computed(() => ({
    module: 'pharmacy',
    page: 'pharmacy.stock',
    columns: [
        { key: 'sku', label: t('pharmacy.medicines.sku') },
        { key: 'name', label: t('pharmacy.medicines.brand'), value: (row) => textIn(row.name) },
        { key: 'generic', label: t('pharmacy.medicines.generic'), value: (row) => textIn(row.generic) },
        { key: 'batch_number', label: t('pharmacy.purchases.batch') },
        { key: 'expires_on', label: t('pharmacy.purchases.expiry'), value: (row) => (row.expires_on ? formatDate(row.expires_on) : '') },
        { key: 'quantity', label: t('pharmacy.stock.pieces'), value: (row) => pieces(row.quantity_milli) },
        { key: 'warehouse', label: t('pharmacy.purchases.warehouse') },
        { key: 'state', label: t('pharmacy.stock.state'), value: (row) => t(`pharmacy.stock.states.${row.state}`) },
    ],
    rows: rows.value,
}));
</script>

<template>
    <PageFrame :title="t('pharmacy.stock.title')" :description="t('pharmacy.stock.text')" width="full" :resource="list" :export="exported" guide="pharmacy.guide.stock"
               :guide-settings="{ name: 'module-settings', params: { module: 'pharmacy' } }">
        <template #top>
            <div class="mb-4 print:hidden"><AppSegmented v-model="show" :options="choices" :label="t('pharmacy.stock.filter')" /></div>
        </template>
        <section class="card">
            <EmptyState v-if="!rows.length" :icon="Boxes" :title="t('pharmacy.stock.empty')" :text="t(`pharmacy.stock.empty_${show}`)" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="`${row.warehouse_id}-${row.item_id}-${row.batch_number}`" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3"
                    :class="row.state === 'expired' || row.state === 'near' ? 'bg-bad-soft/40' : ''">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ textIn(row.name) }} <span class="text-[12.5px] text-muted">{{ row.strength }}</span>
                            <ShieldAlert v-if="row.is_controlled" class="ms-1 inline size-3.5 text-warn" :aria-label="t('pharmacy.sell.controlled')" />
                        </span>
                        <span class="block text-[12.5px] text-muted">{{ textIn(row.generic) }} · {{ t('pharmacy.purchases.batch') }} <span class="font-mono" dir="ltr">{{ row.batch_number }}</span> · {{ row.warehouse }}</span>
                    </span>
                    <span class="tabular w-24 text-end text-[13.5px]">{{ t('pharmacy.sell.pieces', { count: formatNumber(pieces(row.quantity_milli)) }) }}</span>
                    <span class="w-44 text-end">
                        <AppBadge :tone="tones[row.state]" dot>{{ t(`pharmacy.stock.states.${row.state}`) }}</AppBadge>
                        <span v-if="row.expires_on" class="block text-[12px] text-muted">{{ formatDate(row.expires_on) }} · {{ row.days_left < 0 ? t('pharmacy.stock.days_ago', { count: -row.days_left }) : t('pharmacy.stock.days_left', { count: row.days_left }) }}</span>
                    </span>
                    <span class="w-28 text-end text-[12.5px] text-muted">MRP {{ formatMoney({ amount: row.mrp_minor, currency: row.currency }) }}</span>
                    <AppButton v-if="row.state !== 'ok' && can('pharmacy.manage')" size="sm" variant="ghost" :icon="Undo2" class="print:hidden" @click="returnIt(row)">{{ t('pharmacy.stock.return') }}</AppButton>
                </li>
            </ul>
        </section>
    </PageFrame>
</template>
