<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, CopyPlus, Plus, Printer, Save, SearchX, Ship, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { brand } from '@/lib/brand';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDecimal, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { direction, i18n, languageName, loadNamespaces, t, tIn } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { cbmText, milliToText, nextCartonRun, packingTotals, piecesOf, quantityToMilli } from '../lib';

/**
 * One shipment: how and where it goes, the exchange rate, whether it is
 * the order's last, and its packing list (runs of cartons: numbers, size
 * and colour, pieces, weights in kg, size in cm; "ten more the same"
 * carries the numbers on). Changed while draft; shipped by the store
 * keeper (goods leave the store, a draft invoice goes to Accounting).
 * Prints the packing list or the commercial invoice in the buyer's
 * currency, with the company's name and brand.
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const org = currentOrganization();
const route = useRoute();
const view = useResource(() => api.shipment(route.params.id));
const shipment = computed(() => view.data.value?.data ?? null);
const meta = computed(() => view.data.value?.meta ?? {});
const draft = computed(() => shipment.value?.status === 'draft');
const canEdit = computed(() => draft.value && setup.can('merchandise'));
const foreign = computed(() => meta.value.company_currency && shipment.value && meta.value.company_currency !== shipment.value.currency);
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'long', timeZone: 'UTC' });
const money = (minor) => formatMoney({ amount: minor, currency: shipment.value?.currency });

// The form: what was saved, then what is typed (kg and cm as typed text).
const form = reactive({ carrier: '', transport_ref: '', destination: '', exchange_rate: '', is_last: false, note: '' });
const rows = ref([]);
const kg = (grams) => (grams ? milliToText(grams) : '');
const cm = (mm) => (mm ? String(mm / 10) : '');
watch(shipment, (value) => {
    if (!value) return;
    Object.assign(form, { carrier: value.carrier ?? '', transport_ref: value.transport_ref ?? '', destination: value.destination ?? '', exchange_rate: value.exchange_rate, is_last: value.is_last, note: value.note ?? '' });
    rows.value = (value.cartons ?? []).map((row) => ({
        from_no: String(row.from_no), to_no: String(row.to_no), size: row.size, color: row.color, pieces_each: String(row.pieces_each),
        net: kg(row.net_grams_each), gross: kg(row.gross_grams_each), length: cm(row.length_mm), width: cm(row.width_mm), height: cm(row.height_mm),
    }));
}, { immediate: true });
const cmToMm = (text) => {
    const milli = String(text ?? '').trim() === '' ? 0 : quantityToMilli(text);
    return milli === null ? null : Math.round(milli / 100);
};
const toApi = (row) => ({
    from_no: piecesOf(row.from_no), to_no: piecesOf(row.to_no), size: row.size, color: row.color, pieces_each: piecesOf(row.pieces_each),
    net_grams_each: String(row.net).trim() === '' ? 0 : quantityToMilli(row.net), gross_grams_each: String(row.gross).trim() === '' ? 0 : quantityToMilli(row.gross),
    length_mm: cmToMm(row.length), width_mm: cmToMm(row.width), height_mm: cmToMm(row.height),
});
const rowBad = (row) => {
    const value = toApi(row);
    return !value.size || !value.color || !(value.from_no > 0) || !(value.to_no >= value.from_no) || !(value.pieces_each > 0) || Object.values(value).some((part) => part === null);
};
const cartons = computed(() => (draft.value ? rows.value.filter((row) => !rowBad(row)).map(toApi) : (shipment.value?.cartons ?? [])));
const totals = computed(() => packingTotals(cartons.value));
const add = (count) => rows.value.push(nextCartonRun(rows.value, count));
const ready = computed(() => canEdit.value && !rows.value.some(rowBad) && (!foreign.value || form.exchange_rate.trim()));

const busy = ref(false);
async function save() {
    busy.value = true;
    try {
        await api.updateShipment(shipment.value.id, {
            carrier: form.carrier.trim() || null, transport_ref: form.transport_ref.trim() || null, destination: form.destination.trim() || null,
            is_last: form.is_last, note: form.note.trim() || null, ...(foreign.value ? { exchange_rate: form.exchange_rate.trim() } : {}),
            cartons: rows.value.map(toApi), base_version: shipment.value.version,
        });
        toast.success(t('factory_erp.shipments.saved'));
        view.reload();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('factory_erp.conflict') : error.message);
        if (error.code === 'version_conflict') view.reload();
    } finally {
        busy.value = false;
    }
}
async function ship() {
    const yes = await confirmAction({ title: t('factory_erp.shipments.ship_title', { number: shipment.value.number }), message: t('factory_erp.shipments.ship_text', { pieces: formatNumber(shipment.value.pieces) }), confirmLabel: t('factory_erp.shipments.ship') });
    if (!yes) return;
    busy.value = true;
    try {
        const { data } = await api.shipShipment(shipment.value.id, { base_version: shipment.value.version });
        toast.success(data.invoice_id ? t('factory_erp.shipments.shipped_invoice') : t('factory_erp.shipments.shipped'));
        view.reload();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('factory_erp.conflict') : error.message);
        view.reload();
    } finally {
        busy.value = false;
    }
}

// Printing: the packing list or the commercial invoice, in the paper's language
// (the company's rule, else English; the person may choose another), not the screen's.
const paper = ref('packing');
const paperLocale = ref('');
watch(() => setup.rules.value.paper_language, (value) => {
    if (!paperLocale.value) paperLocale.value = i18n.locales.includes(value) ? value : (i18n.locales.includes('en') ? 'en' : i18n.locale);
}, { immediate: true });
const pt = (key, params = {}) => tIn(paperLocale.value, key, params);
const pn = (value, options = {}) => formatNumber(value, { ...options, locale: paperLocale.value });
const pd = (text) => formatDecimal(text, paperLocale.value);
const pm = (minor) => formatMoney({ amount: minor, currency: shipment.value?.currency }, paperLocale.value);
const pday = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'long', timeZone: 'UTC', locale: paperLocale.value });
async function print(which) {
    paper.value = which;
    await loadNamespaces(['factory_erp'], paperLocale.value);
    await nextTick();
    globalThis.print?.();
}
const invoiceRows = computed(() => {
    const cells = {};
    for (const row of cartons.value) {
        const key = `${row.size} / ${row.color}`;
        cells[key] = (cells[key] ?? 0) + (row.to_no - row.from_no + 1) * row.pieces_each;
    }
    return Object.entries(cells).map(([key, pieces]) => ({ key, pieces, value: pieces * (meta.value.order?.unit_price_minor ?? 0) }));
});
</script>

<template>
    <div>
        <AppButton v-if="shipment" variant="ghost" size="sm" :to="{ name: 'fac-order', params: { id: shipment.order_id }, query: { tab: 'shipments' } }" :icon="ArrowLeft" class="mb-3 print:hidden">
            {{ meta.order?.number }}
        </AppButton>
        <SkeletonRows v-if="view.loading.value && !shipment" :rows="8" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('factory_erp.shipments.not_found')" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <template v-else-if="shipment">
            <PageHeader :title="shipment.number" :description="`${meta.order?.number} · ${meta.style?.code} · ${meta.buyer?.name ?? ''}`" class="print:hidden">
                <template #eyebrow>
                    <AppBadge class="mb-2" :tone="shipment.status === 'shipped' ? 'ok' : 'neutral'" dot>{{ t(`factory_erp.shipments.statuses.${shipment.status}`) }}</AppBadge>
                </template>
                <template #actions>
                    <select v-model="paperLocale" class="field-input w-36" :aria-label="t('factory_erp.shipments.paper_language')" :title="t('factory_erp.shipments.paper_language')">
                        <option v-for="code in i18n.locales" :key="code" :value="code">{{ languageName(code) }}</option>
                    </select>
                    <AppButton :icon="Printer" @click="print('packing')">{{ t('factory_erp.shipments.print_packing') }}</AppButton>
                    <AppButton :icon="Printer" @click="print('invoice')">{{ t('factory_erp.shipments.print_invoice') }}</AppButton>
                    <AppButton v-if="draft && setup.can('issue')" variant="primary" :icon="Ship" :loading="busy" :disabled="!shipment.pieces" @click="ship">{{ t('factory_erp.shipments.ship') }}</AppButton>
                </template>
            </PageHeader>

            <p v-if="shipment.status === 'shipped'" class="mb-4 rounded-xl bg-ok-soft px-4 py-3 text-[13px] text-ok print:hidden">
                {{ shipment.invoice_id ? t('factory_erp.shipments.shipped_note_invoice') : t('factory_erp.shipments.shipped_note') }}
            </p>

            <section class="card mb-5 p-5 print:hidden">
                <div class="grid gap-3 sm:grid-cols-3">
                    <AppField v-slot="{ id }" :label="t('factory_erp.shipments.carrier')">
                        <input :id="id" v-model="form.carrier" class="field-input" :disabled="!canEdit" maxlength="100" :placeholder="t('factory_erp.shipments.carrier_hint')" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('factory_erp.shipments.transport_ref')">
                        <input :id="id" v-model="form.transport_ref" class="field-input font-mono" dir="ltr" :disabled="!canEdit" maxlength="60" placeholder="BL-778812" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('factory_erp.shipments.destination')">
                        <input :id="id" v-model="form.destination" class="field-input" :disabled="!canEdit" maxlength="120" />
                    </AppField>
                    <AppField v-if="foreign" v-slot="{ id }" :label="t('factory_erp.shipments.rate', { from: shipment.currency, to: meta.company_currency })">
                        <input :id="id" v-model="form.exchange_rate" inputmode="decimal" class="field-input tabular" dir="ltr" :disabled="!canEdit" />
                    </AppField>
                    <AppField v-slot="{ id }" class="sm:col-span-2" :label="t('factory_erp.issues.note')">
                        <input :id="id" v-model="form.note" class="field-input" :disabled="!canEdit" maxlength="500" />
                    </AppField>
                </div>
                <label class="mt-3 flex items-start gap-2 text-[13px]">
                    <input v-model="form.is_last" type="checkbox" class="mt-0.5 size-4 accent-brand" :disabled="!canEdit" />
                    <span><span class="font-medium text-fg">{{ t('factory_erp.shipments.last_label') }}</span><span class="block text-[12px] text-muted">{{ t('factory_erp.shipments.last_hint') }}</span></span>
                </label>
            </section>

            <section class="card mb-5 print:hidden">
                <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.shipments.packing') }}</h2>
                        <p class="tabular text-[12.5px] text-muted">
                            {{ t('factory_erp.shipments.totals', { cartons: formatNumber(totals.cartons), pieces: formatNumber(totals.pieces), net: formatDecimal(milliToText(totals.net) || '0'), gross: formatDecimal(milliToText(totals.gross) || '0'), cbm: formatDecimal(cbmText(totals.volume)) }) }}
                        </p>
                    </div>
                    <template v-if="canEdit">
                        <AppButton size="sm" :icon="Plus" @click="add(1)">{{ t('factory_erp.shipments.add_row') }}</AppButton>
                        <AppButton size="sm" :icon="CopyPlus" :disabled="!rows.length" @click="add(10)">{{ t('factory_erp.shipments.ten_more') }}</AppButton>
                        <AppButton size="sm" variant="primary" :icon="Save" :loading="busy" :disabled="!ready" @click="save">{{ t('factory_erp.save') }}</AppButton>
                    </template>
                </header>
                <EmptyState v-if="!rows.length" compact :icon="Ship" :title="t('factory_erp.shipments.no_cartons')" :text="t('factory_erp.shipments.no_cartons_text')" />
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-[13px]">
                        <thead class="bg-subtle/60 text-[11.5px] text-muted">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-start font-medium">{{ t('factory_erp.shipments.cartons') }}</th>
                                <th scope="col" class="px-2 py-2 text-start font-medium">{{ t('factory_erp.sewing.cell') }}</th>
                                <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('factory_erp.shipments.each') }}</th>
                                <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('factory_erp.shipments.net') }}</th>
                                <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('factory_erp.shipments.gross') }}</th>
                                <th scope="col" class="px-2 py-2 text-center font-medium">{{ t('factory_erp.shipments.measure') }}</th>
                                <th v-if="canEdit" scope="col" class="w-10" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(row, index) in rows" :key="index" :class="canEdit && rowBad(row) ? 'bg-bad-soft/40' : ''">
                                <td class="px-3 py-1.5">
                                    <span class="inline-flex items-center gap-1">
                                        <input v-model="row.from_no" inputmode="numeric" class="field-input tabular w-16 text-center" :disabled="!canEdit" :aria-label="t('factory_erp.shipments.from_no')" />–
                                        <input v-model="row.to_no" inputmode="numeric" class="field-input tabular w-16 text-center" :disabled="!canEdit" :aria-label="t('factory_erp.shipments.to_no')" />
                                    </span>
                                </td>
                                <td class="px-2 py-1.5">
                                    <span class="inline-flex gap-1">
                                        <select v-model="row.size" class="field-input w-20" :disabled="!canEdit" :aria-label="t('factory_erp.bom.only_size')">
                                            <option v-for="size in meta.style?.sizes ?? []" :key="size" :value="size">{{ size }}</option>
                                        </select>
                                        <select v-model="row.color" class="field-input w-28" :disabled="!canEdit" :aria-label="t('factory_erp.bom.only_color')">
                                            <option v-for="color in meta.style?.colors ?? []" :key="color" :value="color">{{ color }}</option>
                                        </select>
                                    </span>
                                </td>
                                <td class="px-2 py-1.5 text-end"><input v-model="row.pieces_each" inputmode="numeric" class="field-input tabular w-16 text-end" :disabled="!canEdit" :aria-label="t('factory_erp.shipments.each')" /></td>
                                <td class="px-2 py-1.5 text-end"><input v-model="row.net" inputmode="decimal" class="field-input tabular w-20 text-end" :disabled="!canEdit" :aria-label="t('factory_erp.shipments.net')" /></td>
                                <td class="px-2 py-1.5 text-end"><input v-model="row.gross" inputmode="decimal" class="field-input tabular w-20 text-end" :disabled="!canEdit" :aria-label="t('factory_erp.shipments.gross')" /></td>
                                <td class="px-2 py-1.5 text-center">
                                    <span class="inline-flex items-center gap-1 text-muted">
                                        <input v-model="row.length" inputmode="decimal" class="field-input tabular w-14 text-center" :disabled="!canEdit" :aria-label="t('factory_erp.shipments.length')" />×
                                        <input v-model="row.width" inputmode="decimal" class="field-input tabular w-14 text-center" :disabled="!canEdit" :aria-label="t('factory_erp.shipments.width')" />×
                                        <input v-model="row.height" inputmode="decimal" class="field-input tabular w-14 text-center" :disabled="!canEdit" :aria-label="t('factory_erp.shipments.height')" />
                                    </span>
                                </td>
                                <td v-if="canEdit" class="px-2 py-1.5"><AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('factory_erp.shipments.remove_row')" @click="rows.splice(index, 1)" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- The printed paper: hidden on screen, printed alone. -->
            <article class="hidden text-[12px] text-black print:block" :lang="paperLocale" :dir="direction(paperLocale)">
                <header class="flex items-start justify-between border-b border-black pb-3">
                    <div class="flex items-center gap-3">
                        <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-10 w-auto" />
                        <div><div class="text-[16px] font-semibold">{{ meta.seller?.name ?? org.name }}</div><div>{{ brand.name }}</div></div>
                    </div>
                    <div class="text-end">
                        <div class="text-[18px] font-bold uppercase">{{ paper === 'invoice' ? pt('factory_erp.shipments.invoice_title') : pt('factory_erp.shipments.packing_title') }}</div>
                        <div class="font-mono" dir="ltr">{{ shipment.number }}</div>
                        <div>{{ pday(shipment.ship_on) }}</div>
                    </div>
                </header>
                <div class="grid grid-cols-2 gap-4 py-3">
                    <div>
                        <div class="font-semibold uppercase">{{ pt('factory_erp.shipments.buyer') }}</div>
                        <div>{{ meta.buyer?.name }}</div>
                        <div v-if="meta.buyer?.address" class="whitespace-pre-line">{{ meta.buyer.address }}</div>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-3">
                        <dt>{{ pt('factory_erp.fields_.po_number') }}</dt><dd class="font-mono" dir="ltr">{{ meta.order?.po_number ?? '—' }}</dd>
                        <dt>{{ pt('factory_erp.shipments.style') }}</dt><dd>{{ meta.style?.code }} · {{ meta.style?.name }}</dd>
                        <dt>{{ pt('factory_erp.shipments.mode') }}</dt><dd>{{ pt(`factory_erp.shipments.modes.${shipment.mode}`) }}<template v-if="shipment.carrier"> · {{ shipment.carrier }}</template></dd>
                        <dt>{{ pt('factory_erp.shipments.transport_ref') }}</dt><dd class="font-mono" dir="ltr">{{ shipment.transport_ref ?? '—' }}</dd>
                        <dt>{{ pt('factory_erp.shipments.destination') }}</dt><dd>{{ shipment.destination ?? '—' }}</dd>
                    </dl>
                </div>
                <table v-if="paper === 'packing'" class="w-full border-collapse">
                    <thead><tr class="border-y border-black">
                        <th class="py-1 text-start">{{ pt('factory_erp.shipments.cartons') }}</th><th class="text-start">{{ pt('factory_erp.sewing.cell') }}</th>
                        <th class="text-end">{{ pt('factory_erp.shipments.count') }}</th><th class="text-end">{{ pt('factory_erp.shipments.each') }}</th><th class="text-end">{{ pt('factory_erp.tickets.pieces') }}</th>
                        <th class="text-end">{{ pt('factory_erp.shipments.net') }}</th><th class="text-end">{{ pt('factory_erp.shipments.gross') }}</th><th class="text-end">{{ pt('factory_erp.shipments.measure') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="(row, index) in cartons" :key="index" class="border-b border-black/30">
                            <td class="tabular py-1">{{ pn(row.from_no) }}–{{ pn(row.to_no) }}</td><td>{{ row.size }} / {{ row.color }}</td>
                            <td class="tabular text-end">{{ pn(row.to_no - row.from_no + 1) }}</td><td class="tabular text-end">{{ pn(row.pieces_each) }}</td>
                            <td class="tabular text-end">{{ pn((row.to_no - row.from_no + 1) * row.pieces_each) }}</td>
                            <td class="tabular text-end">{{ pd(milliToText((row.to_no - row.from_no + 1) * row.net_grams_each) || '0') }}</td>
                            <td class="tabular text-end">{{ pd(milliToText((row.to_no - row.from_no + 1) * row.gross_grams_each) || '0') }}</td>
                            <td class="tabular text-end">{{ pn(row.length_mm / 10) }}×{{ pn(row.width_mm / 10) }}×{{ pn(row.height_mm / 10) }}</td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="border-t-2 border-black font-semibold">
                        <td class="py-1" colspan="2">{{ pt('factory_erp.sheet.total') }}</td><td class="tabular text-end">{{ pn(totals.cartons) }}</td><td />
                        <td class="tabular text-end">{{ pn(totals.pieces) }}</td><td class="tabular text-end">{{ pd(milliToText(totals.net) || '0') }}</td>
                        <td class="tabular text-end">{{ pd(milliToText(totals.gross) || '0') }}</td><td class="tabular text-end">{{ pd(cbmText(totals.volume)) }} CBM</td>
                    </tr></tfoot>
                </table>
                <table v-else class="w-full border-collapse">
                    <thead><tr class="border-y border-black">
                        <th class="py-1 text-start">{{ pt('factory_erp.shipments.description') }}</th><th class="text-end">{{ pt('factory_erp.tickets.pieces') }}</th>
                        <th class="text-end">{{ pt('factory_erp.shipments.unit_price') }}</th><th class="text-end">{{ pt('factory_erp.shipments.amount') }}</th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="row in invoiceRows" :key="row.key" class="border-b border-black/30">
                            <td class="py-1">{{ meta.style?.product_type ?? '' }} {{ meta.style?.code }} · {{ row.key }}</td><td class="tabular text-end">{{ pn(row.pieces) }}</td>
                            <td class="tabular text-end">{{ pm(meta.order?.unit_price_minor ?? 0) }}</td><td class="tabular text-end">{{ pm(row.value) }}</td>
                        </tr>
                    </tbody>
                    <tfoot><tr class="border-t-2 border-black font-semibold">
                        <td class="py-1">{{ pt('factory_erp.sheet.total') }}</td><td class="tabular text-end">{{ pn(totals.pieces) }}</td><td />
                        <td class="tabular text-end">{{ pm(invoiceRows.reduce((sum, row) => sum + row.value, 0)) }}</td>
                    </tr></tfoot>
                </table>
                <p v-if="shipment.note" class="mt-3">{{ shipment.note }}</p>
            </article>
        </template>
    </div>
</template>
