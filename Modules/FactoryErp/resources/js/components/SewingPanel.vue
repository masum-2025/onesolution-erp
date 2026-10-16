<script setup>
import { computed, ref } from 'vue';
import { PackageCheck, Warehouse } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { newOpId } from '@/lib/billing';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { piecesOf, sheetToLines, stagePercent } from '../lib';
import SizeColorSheet from './SizeColorSheet.vue';

/**
 * An order from the cutting table to the store: for each size and colour
 * what was cut, sewn good, rejected and taken into the store; the work in
 * progress it holds; the finished goods taken in so far; and taking more
 * in (store keepers, while the order is in production), at most the good
 * pieces not in the store yet. "The last" takes all the work in progress
 * left into the cost.
 */
const props = defineProps({
    order: { type: Object, required: true },
    style: { type: Object, required: true },
});
const emit = defineEmits(['changed']);
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const progress = useResource(() => api.progress(props.order.id));
const receipts = useResource(() => api.receipts(props.order.id));
const cells = computed(() => progress.data.value?.data?.cells ?? {});
const totals = computed(() => progress.data.value?.data?.totals ?? { ordered: 0, cut: 0, good: 0, rejects: 0, received: 0 });
const money = (minor, currency) => formatMoney({ amount: minor, currency });
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const canReceive = computed(() => props.order.status === 'in_production' && setup.can('issue'));
const steps = computed(() => [
    { key: 'cut', value: totals.value.cut },
    { key: 'good', value: totals.value.good },
    { key: 'received', value: totals.value.received },
].map((step) => ({ ...step, percent: stagePercent(step.value, totals.value.ordered) })));
const ready = (key) => Math.max(0, (cells.value[key]?.good ?? 0) - (cells.value[key]?.received ?? 0));

// Taking finished goods in.
const taking = ref(false);
const sheet = ref({});
const warehouseId = ref('');
const receivedOn = ref('');
const isLast = ref(false);
const note = ref('');
const busy = ref(false);
let opId = '';
function startTaking() {
    sheet.value = {};
    warehouseId.value = setup.warehouses.value[0]?.id ?? '';
    receivedOn.value = setup.data.value?.today ?? '';
    isLast.value = false;
    note.value = '';
    opId = newOpId();
    taking.value = true;
}
function fillReady() {
    sheet.value = Object.fromEntries(Object.keys(cells.value).filter((key) => ready(key) > 0).map((key) => [key, String(ready(key))]));
}
const sheetBad = computed(() => Object.values(sheet.value).some((text) => piecesOf(text) === null));
const takeSum = computed(() => sheetToLines(sheet.value).reduce((sum, line) => sum + line.quantity, 0));
const tooMany = computed(() => sheetToLines(sheet.value).filter((line) => line.quantity > ready(`${line.size}|${line.color}`)));
async function take() {
    busy.value = true;
    try {
        await api.receive(props.order.id, {
            warehouse_id: warehouseId.value, received_on: receivedOn.value, lines: sheetToLines(sheet.value).filter((line) => line.quantity > 0),
            is_last: isLast.value, ...(note.value.trim() ? { note: note.value.trim() } : {}), op_id: opId,
        });
        toast.success(t('factory_erp.sewing.taken', { pieces: formatNumber(takeSum.value) }));
        taking.value = false;
        progress.reload();
        receipts.reload();
        emit('changed');
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-5">
        <section class="card">
            <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <div class="min-w-0 flex-1">
                    <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.sewing.title') }}</h2>
                    <p class="text-[12.5px] text-muted">{{ t('factory_erp.sewing.text') }}</p>
                </div>
                <AppButton v-if="canReceive" size="sm" variant="primary" :icon="Warehouse" @click="startTaking">{{ t('factory_erp.sewing.take') }}</AppButton>
            </header>
            <SkeletonRows v-if="progress.loading.value && !progress.data.value" :rows="4" />
            <ErrorState v-else-if="progress.error.value" compact :error="progress.error.value" @retry="progress.reload()" />
            <template v-else>
                <ol class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-3">
                    <li v-for="step in steps" :key="step.key" class="rounded-xl border border-line p-3">
                        <p class="text-[12px] text-muted">{{ t(`factory_erp.sewing.steps.${step.key}`) }}</p>
                        <p class="tabular text-[18px] font-bold text-fg">{{ formatNumber(step.value) }} <span class="text-[12px] font-medium text-muted">/ {{ formatNumber(totals.ordered) }}</span></p>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-subtle" role="progressbar" :aria-valuenow="step.percent" aria-valuemin="0" aria-valuemax="100" :aria-label="t(`factory_erp.sewing.steps.${step.key}`)">
                            <div class="h-full rounded-full" :class="step.key === 'received' ? 'bg-ok' : 'bg-brand'" :style="{ inlineSize: `${step.percent}%` }" />
                        </div>
                    </li>
                </ol>
                <p class="px-5 pb-3 text-[12.5px] text-muted">
                    {{ t('factory_erp.sewing.summary', { rejects: formatNumber(totals.rejects), wip: money(progress.data.value?.data?.wip_minor ?? 0, progress.data.value?.data?.currency) }) }}
                </p>
                <div class="overflow-x-auto px-3 pb-4 sm:px-4">
                    <table class="w-full min-w-[560px] text-[13px]">
                        <thead class="bg-subtle/60 text-[11.5px] text-muted">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-start font-medium">{{ t('factory_erp.sewing.cell') }}</th>
                                <th v-for="stage in ['ordered', 'cut', 'good', 'rejects', 'received']" :key="stage" scope="col" class="px-3 py-2 text-end font-medium">{{ t(`factory_erp.sewing.cols.${stage}`) }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <template v-for="color in style.colors" :key="color">
                                <template v-for="size in style.sizes" :key="size">
                                    <tr v-if="cells[`${size}|${color}`]">
                                        <th scope="row" class="px-3 py-2 text-start font-medium text-fg">{{ size }} / {{ color }}</th>
                                        <td class="tabular px-3 py-2 text-end">{{ formatNumber(cells[`${size}|${color}`].ordered) }}</td>
                                        <td class="tabular px-3 py-2 text-end">{{ formatNumber(cells[`${size}|${color}`].cut) }}</td>
                                        <td class="tabular px-3 py-2 text-end font-medium">{{ formatNumber(cells[`${size}|${color}`].good) }}</td>
                                        <td class="tabular px-3 py-2 text-end" :class="cells[`${size}|${color}`].rejects ? 'text-bad' : 'text-muted'">{{ formatNumber(cells[`${size}|${color}`].rejects) }}</td>
                                        <td class="tabular px-3 py-2 text-end font-semibold" :class="cells[`${size}|${color}`].received >= cells[`${size}|${color}`].ordered ? 'text-ok' : ''">{{ formatNumber(cells[`${size}|${color}`].received) }}</td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <section class="card">
            <header class="border-b border-line px-5 py-3">
                <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.sewing.receipts') }}</h2>
            </header>
            <SkeletonRows v-if="receipts.loading.value && !receipts.data.value" :rows="2" />
            <ErrorState v-else-if="receipts.error.value" compact :error="receipts.error.value" @retry="receipts.reload()" />
            <EmptyState v-else-if="!(receipts.data.value?.data ?? []).length" compact :icon="PackageCheck" :title="t('factory_erp.sewing.empty_title')" :text="t('factory_erp.sewing.empty_text')" />
            <ul v-else class="divide-y divide-line">
                <li v-for="receipt in receipts.data.value.data" :key="receipt.id" class="flex flex-wrap items-start gap-3 px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-[13.5px] font-medium text-fg">
                            {{ t('factory_erp.sewing.receipt_line', { pieces: formatNumber(receipt.pieces), store: setup.warehouseName(receipt.warehouse_id) }) }}
                            <AppBadge v-if="receipt.is_last" tone="ok" class="ms-1">{{ t('factory_erp.sewing.last_badge') }}</AppBadge>
                        </p>
                        <p class="text-[12px] text-muted">
                            {{ day(receipt.received_on) }} · {{ receipt.lines.map((line) => `${line.size}/${line.color} ${formatNumber(line.quantity)}`).join(' · ') }}
                            <template v-if="receipt.note"> · {{ receipt.note }}</template>
                        </p>
                    </div>
                    <span class="tabular text-[13.5px] font-semibold text-fg">{{ money(receipt.cost_minor, receipt.currency) }}</span>
                </li>
            </ul>
        </section>

        <AppDialog :open="taking" size="lg" :icon="Warehouse" :title="t('factory_erp.sewing.take_title', { number: order.number })" :description="t('factory_erp.sewing.take_text')" @close="taking = false">
            <p v-if="!style.finished_item_id" class="mb-3 rounded-xl bg-warn-soft px-3 py-2 text-[13px] text-warn">{{ t('factory_erp.sewing.no_item') }}</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('factory_erp.issues.store')">
                    <select :id="id" v-model="warehouseId" class="field-input">
                        <option v-for="warehouse in setup.warehouses.value" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }} ({{ warehouse.code }})</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('factory_erp.issues.day')">
                    <input :id="id" v-model="receivedOn" type="date" class="field-input" :max="setup.data.value?.today" />
                </AppField>
            </div>
            <div class="mt-4 flex items-center justify-between gap-3">
                <h3 class="text-[13px] font-semibold text-fg">{{ t('factory_erp.sewing.pieces') }}</h3>
                <AppButton size="sm" variant="ghost" @click="fillReady">{{ t('factory_erp.sewing.fill_ready') }}</AppButton>
            </div>
            <div class="mt-2 rounded-xl border border-line p-2">
                <SizeColorSheet v-model="sheet" :sizes="style.sizes" :colors="style.colors" />
            </div>
            <p v-if="tooMany.length" class="mt-2 text-[12.5px] text-bad" role="alert">
                {{ t('factory_erp.sewing.too_many', { cells: tooMany.map((line) => `${line.size}/${line.color}`).join(', ') }) }}
            </p>
            <label class="mt-4 flex items-start gap-2 text-[13px]">
                <input v-model="isLast" type="checkbox" class="mt-0.5 size-4 accent-brand" />
                <span><span class="font-medium text-fg">{{ t('factory_erp.sewing.last') }}</span><span class="block text-[12px] text-muted">{{ t('factory_erp.sewing.last_hint') }}</span></span>
            </label>
            <AppField v-slot="{ id }" class="mt-3" :label="t('factory_erp.issues.note')">
                <input :id="id" v-model="note" class="field-input" maxlength="300" :placeholder="t('factory_erp.sewing.note_hint')" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="taking = false">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!style.finished_item_id || sheetBad || takeSum === 0 || tooMany.length > 0 || !warehouseId || !receivedOn" @click="take">
                    {{ t('factory_erp.sewing.take_save', { pieces: formatNumber(takeSum) }) }}
                </AppButton>
            </template>
        </AppDialog>
    </div>
</template>
