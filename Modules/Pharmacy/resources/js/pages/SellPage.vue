<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { AlertTriangle, CloudOff, FileText, Minus, Pill, Plus, Printer, Repeat, ScanBarcode, Search, ShieldAlert, Store, Trash2, X } from 'lucide-vue-next';
import { MoneyInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useResource } from '@/lib/useResource';
import { enqueueOffline, offline } from '@/lib/offline';
import { readPref, writePref } from '@/lib/storage';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { packsOf, pharmacyApi, pieces, piecesIn } from '../api';
import { suggestedLine, suggestedPieces } from '../prescription';

/**
 * The pharmacy counter: find a medicine by brand or generic name (or scan it),
 * sell it by piece, strip or box from the good batch expiring first, take the
 * payment and print the POS receipt. Controlled medicines ask for the
 * prescription. The sale is a POS sale in the counter's open shift.
 * With Outpatient on, a doctor's prescription (scanned code, or the patient)
 * fills the cart with suggested quantities and the prescription's details.
 */
const org = currentOrganization();
const ph = pharmacyApi(org.id);
const registers = useResource(() => ph.registers());
const registerList = computed(() => (registers.data.value?.data ?? []).filter((row) => row.is_active));
const registerId = ref(readPref('pharmacy.register') ?? '');
const register = computed(() => registerList.value.find((row) => row.id === registerId.value) ?? registerList.value[0] ?? null);
watch(register, (row) => {
    if (row) writePref('pharmacy.register', row.id);
});
const shift = computed(() => register.value?.session ?? null);

const catalogue = useResource(() => (register.value ? ph.catalogue(register.value.id) : Promise.resolve(null)));
const counter = useResource(() => (register.value ? ph.counter(register.value.id) : Promise.resolve(null)));
watch(() => register.value?.id, () => {
    catalogue.reload();
    counter.reload();
});
const currency = computed(() => catalogue.data.value?.meta?.currency ?? null);
const includeTax = computed(() => catalogue.data.value?.meta?.prices_include_tax ?? true);
const items = computed(() => Object.fromEntries((catalogue.data.value?.data?.items ?? []).map((item) => [item.id, item])));
const loose = computed(() => counter.data.value?.meta?.sell_loose_pieces ?? true);
const medicines = computed(() => (counter.data.value?.data ?? []).filter((row) => items.value[row.item_id]).map((row) => ({ ...row, item: items.value[row.item_id] })));
const money = (amount) => formatMoney({ amount, currency: currency.value });
const name = (row) => textIn(row.item?.names ?? {}) || row.item?.sku;

// Finding: brand, generic, SKU or a scanned barcode.
const search = ref('');
const searchBox = ref(null);
const found = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (!needle) return [];
    return medicines.value.filter((row) => [...Object.values(row.item.names ?? {}), ...Object.values(row.generic ?? {}), row.item.sku, row.item.barcode]
        .some((text) => text && String(text).toLowerCase().includes(needle))).slice(0, 12);
});
function onEnter() {
    const typed = search.value.trim();
    const exact = medicines.value.find((row) => row.item.barcode && row.item.barcode === typed);
    const pick = exact ?? (found.value.length === 1 ? found.value[0] : null);
    if (pick) add(pick);
}

// The cart: one line per medicine and pack.
const cart = ref([]);
function add(row, pack = null) {
    const packs = packsOf(row, loose.value);
    const chosen = pack ?? (packs.includes('strip') ? 'strip' : packs[0]);
    const line = cart.value.find((entry) => entry.medicine.medicine_id === row.medicine_id && entry.pack === chosen);
    if (line) line.quantity++;
    else cart.value.push({ key: `${row.medicine_id}-${chosen}-${Date.now()}`, medicine: row, pack: chosen, quantity: 1 });
    search.value = '';
    nextTick(() => searchBox.value?.focus());
}
const linePieces = (line) => (piecesIn(line.medicine, line.pack) ?? 0) * line.quantity;
const lineAmount = (line) => linePieces(line) * (line.medicine.item.sale_price_minor ?? 0);
const piecesInCart = (row) => cart.value.filter((line) => line.medicine.medicine_id === row.medicine_id).reduce((sum, line) => sum + linePieces(line), 0);
const short = (line) => piecesInCart(line.medicine) > pieces(line.medicine.sellable_milli);
const hasControlled = computed(() => cart.value.some((line) => line.medicine.is_controlled));
const needsPrescription = computed(() => hasControlled.value && (counter.data.value?.meta?.controlled_requires_prescription ?? true));

const discount = ref(0);
const subtotal = computed(() => cart.value.reduce((sum, line) => sum + lineAmount(line), 0));
// Tax on top only where the company prices without it (POS works out the exact amount).
const taxOnTop = computed(() => (includeTax.value ? 0 : cart.value.reduce((sum, line) => sum + Math.round((lineAmount(line) * (line.medicine.item.tax_rate_bp ?? 0)) / 10000), 0)));
const total = computed(() => Math.max(0, subtotal.value - (discount.value ?? 0)) + taxOnTop.value);
// The sale's discount shared over the lines by their amount (the last line takes what is left).
function lineDiscounts() {
    let given = 0;
    return cart.value.map((line, index) => {
        if (index === cart.value.length - 1) return (discount.value ?? 0) - given;
        const share = subtotal.value ? Math.floor(((discount.value ?? 0) * lineAmount(line)) / subtotal.value) : 0;
        given += share;
        return share;
    });
}

const prescription = reactive({ patient_name: '', prescriber_name: '', prescriber_bmdc: '', ref: '' });
const customer = reactive({ name: '', phone: '' });
const method = ref('cash');
const received = ref(null);
watch(total, (value) => {
    received.value = value;
});
const change = computed(() => (method.value === 'cash' ? Math.max(0, (received.value ?? 0) - total.value) : 0));
const errors = ref({});
const selling = ref(false);
const done = ref(null);

// Substitutes: other brands of the same generic, strength and form.
const substitutesOf = ref(null);
const substitutes = computed(() => (substitutesOf.value ? medicines.value.filter((row) => row.medicine_id !== substitutesOf.value.medicine_id && row.generic_id === substitutesOf.value.generic_id
    && row.form === substitutesOf.value.form && row.strength === substitutesOf.value.strength) : []));

// From a doctor's prescription: found by its printed code or the patient, its medicines put in the cart.
const prescriptionsOn = computed(() => counter.data.value?.meta?.prescriptions_on ?? false);
const fromRx = ref(null);
const rx = reactive({ open: false, search: '', rows: null, busy: false, error: '' });
function openRx() {
    Object.assign(rx, { open: true, search: '', rows: null, error: '' });
}
async function findRx() {
    const term = rx.search.trim();
    if (term.length < 2 || rx.busy) return;
    Object.assign(rx, { busy: true, error: '' });
    try {
        const rows = (await ph.prescriptions(register.value.id, term)).data;
        rx.rows = rows;
        // A scanned code opens it straight away.
        if (rows.length === 1 && rows[0].id.toLowerCase() === term.toLowerCase()) await takeRx(rows[0].id);
    } catch (error) {
        rx.error = error.message;
    } finally {
        rx.busy = false;
    }
}
async function takeRx(id) {
    rx.busy = true;
    try {
        const found = (await ph.prescription(register.value.id, id)).data;
        const missing = [];
        for (const medicine of found.medicines) {
            const row = medicine.medicine_id ? medicines.value.find((entry) => entry.medicine_id === medicine.medicine_id) : null;
            const piecesWanted = suggestedPieces(medicine.dose, medicine.duration);
            const line = row && row.sellable_milli ? suggestedLine(row, loose.value, piecesWanted) : null;
            if (!line) {
                missing.push({ ...medicine, row });
                continue;
            }
            const same = cart.value.find((entry) => entry.medicine.medicine_id === row.medicine_id && entry.pack === line.pack);
            if (same) same.quantity = Math.max(same.quantity, line.quantity);
            else cart.value.push({ key: `${row.medicine_id}-${line.pack}-${Date.now()}`, medicine: row, pack: line.pack, quantity: line.quantity, prescribed: piecesWanted, dose: medicine.dose });
        }
        fromRx.value = { ...found, missing };
        Object.assign(prescription, { patient_name: found.patient_name, prescriber_name: found.doctor_name, prescriber_bmdc: found.doctor_registration_no ?? '', ref: '' });
        rx.open = false;
    } catch (error) {
        rx.error = error.message;
    } finally {
        rx.busy = false;
    }
}
function dropRx() {
    fromRx.value = null;
    Object.assign(prescription, { patient_name: '', prescriber_name: '', prescriber_bmdc: '', ref: '' });
}
function findWritten(medicine) {
    search.value = medicine.medicine_text;
    nextTick(() => searchBox.value?.focus());
}

const floatAmount = ref(0);
const opening = ref(false);
async function openShift() {
    opening.value = true;
    try {
        await ph.openShift(register.value.id, floatAmount.value ?? 0);
        toast.success(t('pharmacy.sell.shift_opened'));
        await registers.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        opening.value = false;
    }
}

const canComplete = computed(() => cart.value.length > 0 && !cart.value.some(short) && (method.value !== 'cash' || (received.value ?? 0) >= total.value)
    && (!needsPrescription.value || (prescription.patient_name.trim() && prescription.prescriber_bmdc.trim())));

async function complete() {
    if (!canComplete.value) return;
    errors.value = {};
    const discounts = lineDiscounts();
    const payment = { method: method.value, amount_minor: method.value === 'cash' ? (received.value ?? 0) : total.value };
    const opId = crypto.randomUUID();
    selling.value = true;
    try {
        if (!offline.online) {
            await sellOffline(opId, discounts, payment);
            return;
        }
        const filled = Object.fromEntries(Object.entries(prescription).filter(([, value]) => value.trim()));
        if (fromRx.value) filled.consultation_id = fromRx.value.id;
        const sale = await ph.sell({
            op_id: opId, register_id: register.value.id, session_id: shift.value?.id ?? null,
            lines: cart.value.map((line, index) => ({ medicine_id: line.medicine.medicine_id, pack: line.pack, quantity: line.quantity, discount_minor: discounts[index] || 0 })),
            payments: [payment], customer_name: customer.name.trim() || null, customer_phone: customer.phone.trim() || null,
            ...(Object.keys(filled).length ? { prescription: filled } : {}),
        });
        done.value = { number: sale.data.number, change: sale.data.change_minor, saleId: sale.data.pos_sale_id };
        reset();
        counter.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
    } finally {
        selling.value = false;
    }
}
async function sellOffline(opId, discounts, payment) {
    try {
        if (hasControlled.value) {
            toast.error(t('pharmacy.sell.controlled_offline'));
            return;
        }
        if (!catalogue.data.value?.meta?.offline_sales) {
            toast.error(t('pharmacy.sell.offline_off'));
            return;
        }
        // Kept on this device and sent as a POS sale later; the pharmacy's rules are checked when it arrives.
        await enqueueOffline('pos.sale', 'create', {
            op_id: opId, register_id: register.value.id, session_id: shift.value?.id ?? null, payments: [payment],
            lines: cart.value.map((line, index) => ({ item_id: line.medicine.item_id, quantity_milli: linePieces(line) * 1000, discount_minor: discounts[index] || 0 })),
        });
        done.value = { number: null, change: change.value, offline: true };
        reset();
    } finally {
        selling.value = false;
    }
}
function reset() {
    cart.value = [];
    fromRx.value = null;
    discount.value = 0;
    Object.assign(prescription, { patient_name: '', prescriber_name: '', prescriber_bmdc: '', ref: '' });
    Object.assign(customer, { name: '', phone: '' });
    method.value = 'cash';
}
function nextSale() {
    done.value = null;
    nextTick(() => searchBox.value?.focus());
}
const loading = computed(() => (registers.loading.value && !registers.data.value) || (register.value && ((catalogue.loading.value && !catalogue.data.value) || (counter.loading.value && !counter.data.value))));
const failed = computed(() => registers.error.value ?? catalogue.error.value ?? counter.error.value ?? null);
</script>

<template>
    <PageFrame :title="t('pharmacy.sell.title')" :description="t('pharmacy.sell.text')" width="full" guide="pharmacy.guide.sell"
               :guide-settings="{ name: 'module-settings', params: { module: 'pharmacy' } }"
               :resource="{ loading, error: failed, data: registers.data, reload: () => { registers.reload(); catalogue.reload(); counter.reload(); } }"
               :empty="{ when: !registerList.length, icon: Store, title: t('pharmacy.sell.no_counter'), text: t('pharmacy.sell.no_counter_text') }">
        <template #actions>
            <select v-if="registerList.length > 1" v-model="registerId" class="field-input h-9 w-auto" :aria-label="t('pharmacy.sell.counter')">
                <option v-for="row in registerList" :key="row.id" :value="row.id">{{ row.name }}</option>
            </select>
            <AppBadge v-if="!offline.online" tone="warn" dot><CloudOff class="me-1 inline size-3.5" aria-hidden="true" />{{ t('pharmacy.sell.offline') }}</AppBadge>
        </template>

        <!-- No open shift at this counter yet -->
        <section v-if="!shift" class="card mx-auto max-w-md p-6 text-center">
            <span class="mx-auto mb-3 grid size-14 place-items-center rounded-2xl bg-brand-soft text-brand-text"><Store class="size-7" aria-hidden="true" /></span>
            <h2 class="text-[17px] font-semibold">{{ t('pharmacy.sell.open_title') }}</h2>
            <p class="mt-1 text-[13px] text-muted">{{ t('pharmacy.sell.open_text') }}</p>
            <form class="mt-4 grid gap-3 text-start" novalidate @submit.prevent="openShift">
                <AppField v-slot="{ id }" :label="t('pharmacy.sell.float')">
                    <MoneyInput :id="id" v-model="floatAmount" :currency="currency" />
                </AppField>
                <AppButton variant="primary" type="submit" :loading="opening">{{ t('pharmacy.sell.open') }}</AppButton>
            </form>
        </section>

        <!-- Sold -->
        <section v-else-if="done" class="card mx-auto max-w-md p-6 text-center" role="status">
            <span class="mx-auto mb-3 grid size-14 place-items-center rounded-2xl bg-ok-soft text-ok"><Pill class="size-7" aria-hidden="true" /></span>
            <h2 class="text-[17px] font-semibold">{{ done.offline ? t('pharmacy.sell.saved_offline') : t('pharmacy.sell.sold', { number: done.number }) }}</h2>
            <p v-if="done.change" class="mt-2 text-[15px]">{{ t('pharmacy.sell.give_change') }} <strong class="tabular">{{ money(done.change) }}</strong></p>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                <AppButton v-if="done.saleId" :icon="Printer" :to="{ name: 'pos-sale', params: { id: done.saleId }, query: { print: '1' } }">{{ t('pharmacy.sell.print') }}</AppButton>
                <AppButton variant="primary" data-autofocus @click="nextSale">{{ t('pharmacy.sell.next') }}</AppButton>
            </div>
        </section>

        <div v-else class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
            <!-- Find and add -->
            <section class="card p-4">
                <div class="relative">
                    <ScanBarcode class="pointer-events-none absolute inset-y-0 start-3 my-auto size-5 text-muted" aria-hidden="true" />
                    <input ref="searchBox" v-model="search" class="field-input h-11 ps-10 text-[15px]" :placeholder="t('pharmacy.sell.search')" :aria-label="t('pharmacy.sell.search')" autofocus @keydown.enter.prevent="onEnter" />
                </div>
                <div v-if="prescriptionsOn" class="mt-2 flex flex-wrap items-center gap-2">
                    <AppButton size="sm" :icon="FileText" :disabled="!offline.online" @click="openRx">{{ t('pharmacy.sell.from_rx') }}</AppButton>
                    <span v-if="!offline.online" class="text-[12px] text-muted">{{ t('pharmacy.sell.rx_offline') }}</span>
                </div>
                <EmptyState v-if="!medicines.length" :icon="Pill" :title="t('pharmacy.sell.no_medicines')" :text="t('pharmacy.sell.no_medicines_text')" compact />
                <ul v-else-if="found.length" class="mt-3 divide-y divide-line">
                    <li v-for="row in found" :key="row.medicine_id" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ name(row) }} <span class="text-[12.5px] text-muted">{{ row.strength }} · {{ t(`pharmacy.forms.${row.form}`) }}</span></span>
                            <span class="block text-[12.5px] text-muted">{{ textIn(row.generic) }} · MRP {{ money(row.mrp_minor) }} · {{ t('pharmacy.sell.in_stock', { count: formatNumber(pieces(row.sellable_milli)) }) }}</span>
                        </span>
                        <AppBadge v-if="row.is_controlled" tone="warn"><ShieldAlert class="me-1 inline size-3.5" aria-hidden="true" />{{ t('pharmacy.sell.controlled') }}</AppBadge>
                        <AppBadge v-if="row.near_expiry" tone="bad" dot>{{ t('pharmacy.sell.expires', { date: formatDate(row.first_expiry) }) }}</AppBadge>
                        <AppButton v-if="!row.sellable_milli" size="sm" variant="ghost" :icon="Repeat" @click="substitutesOf = row">{{ t('pharmacy.sell.substitutes') }}</AppButton>
                        <template v-else>
                            <AppButton v-for="pack in packsOf(row, loose)" :key="pack" size="sm" :variant="pack === 'strip' ? 'primary' : 'secondary'" @click="add(row, pack)">+ {{ t(`pharmacy.packs.${pack}`) }}</AppButton>
                        </template>
                    </li>
                </ul>
                <p v-else-if="search.trim()" class="mt-4 text-center text-[13px] text-muted">{{ t('pharmacy.sell.not_found') }}</p>
                <p v-else class="mt-4 text-center text-[13px] text-muted">{{ t('pharmacy.sell.hint') }}</p>
            </section>

            <!-- The sale -->
            <section class="card flex flex-col p-4" :aria-label="t('pharmacy.sell.cart')">
                <h2 class="mb-2 text-[15px] font-semibold">{{ t('pharmacy.sell.cart') }}</h2>
                <div v-if="fromRx" class="mb-3 rounded-xl border border-brand/30 bg-brand-soft p-3 text-[13px]" role="status">
                    <div class="flex items-start gap-2">
                        <FileText class="mt-0.5 size-4 shrink-0 text-brand-text" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold">{{ fromRx.patient_name }} <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ fromRx.patient_mrn }}</span></span>
                            <span class="block text-[12.5px] text-muted">{{ fromRx.doctor_name }} · {{ formatDate(fromRx.date) }}</span>
                        </span>
                        <button type="button" class="grid size-7 place-items-center rounded-lg text-muted hover:bg-subtle" :aria-label="t('pharmacy.sell.rx_remove')" @click="dropRx"><X class="size-4" aria-hidden="true" /></button>
                    </div>
                    <p v-if="fromRx.amended" class="mt-1.5 text-[12px] text-muted">{{ t('pharmacy.sell.rx_amended') }}</p>
                    <p v-if="fromRx.earlier_sales.length" class="mt-1.5 flex items-start gap-1 text-[12.5px] font-medium text-warn">
                        <AlertTriangle class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ t('pharmacy.sell.rx_sold_before', { number: fromRx.earlier_sales[0].number, date: formatDate(fromRx.earlier_sales[0].sold_at) }) }}
                    </p>
                    <template v-if="fromRx.missing.length">
                        <p class="mt-2 text-[12.5px] font-medium">{{ t('pharmacy.sell.rx_missing') }}</p>
                        <ul class="mt-1 divide-y divide-brand/20">
                            <li v-for="(medicine, index) in fromRx.missing" :key="index" class="flex flex-wrap items-center gap-2 py-1.5">
                                <span class="min-w-0 flex-1">{{ medicine.medicine_text }} <span v-if="medicine.dose" class="text-muted">· {{ medicine.dose }}</span></span>
                                <AppButton v-if="medicine.row" size="sm" variant="ghost" :icon="Repeat" @click="substitutesOf = medicine.row">{{ t('pharmacy.sell.substitutes') }}</AppButton>
                                <AppButton v-else size="sm" variant="ghost" :icon="Search" @click="findWritten(medicine)">{{ t('pharmacy.sell.rx_find_medicine') }}</AppButton>
                            </li>
                        </ul>
                    </template>
                </div>
                <p v-if="!cart.length" class="py-6 text-center text-[13px] text-muted">{{ t('pharmacy.sell.cart_empty') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="line in cart" :key="line.key" class="py-2.5">
                        <div class="flex items-start gap-2">
                            <span class="min-w-0 flex-1 text-[13.5px] font-medium">{{ name(line.medicine) }}
                                <span v-if="line.medicine.near_expiry" class="ms-1 text-[12px] font-normal text-bad">● {{ formatDate(line.medicine.first_expiry) }}</span>
                            </span>
                            <span class="tabular text-[13.5px] font-semibold">{{ money(lineAmount(line)) }}</span>
                            <button type="button" class="grid size-7 place-items-center rounded-lg text-muted hover:bg-subtle hover:text-bad" :aria-label="t('pharmacy.sell.remove')" @click="cart = cart.filter((entry) => entry !== line)"><Trash2 class="size-4" aria-hidden="true" /></button>
                        </div>
                        <div class="mt-1.5 flex items-center gap-2">
                            <select v-model="line.pack" class="field-input h-8 w-auto py-0 text-[13px]" :aria-label="t('pharmacy.sell.pack')">
                                <option v-for="pack in packsOf(line.medicine, loose)" :key="pack" :value="pack">{{ t(`pharmacy.packs.${pack}`) }}</option>
                            </select>
                            <button type="button" class="grid size-8 place-items-center rounded-lg border border-line" :aria-label="t('pharmacy.sell.less')" :disabled="line.quantity <= 1" @click="line.quantity--"><Minus class="size-4" aria-hidden="true" /></button>
                            <input v-model.number="line.quantity" type="number" min="1" class="field-input tabular h-8 w-16 text-center" :aria-label="t('pharmacy.sell.quantity')" />
                            <button type="button" class="grid size-8 place-items-center rounded-lg border border-line" :aria-label="t('pharmacy.sell.more')" @click="line.quantity++"><Plus class="size-4" aria-hidden="true" /></button>
                            <span class="text-[12px] text-muted">= {{ t('pharmacy.sell.pieces', { count: formatNumber(linePieces(line)) }) }}</span>
                        </div>
                        <p v-if="line.dose" class="mt-1 text-[12px] text-muted">{{ line.prescribed ? t('pharmacy.sell.rx_suggested', { dose: line.dose, count: formatNumber(line.prescribed) }) : t('pharmacy.sell.rx_dose', { dose: line.dose }) }}</p>
                        <p v-if="short(line)" class="mt-1 flex items-center gap-1 text-[12px] text-bad"><AlertTriangle class="size-3.5" aria-hidden="true" />{{ t('pharmacy.sell.short', { count: formatNumber(pieces(line.medicine.sellable_milli)) }) }}</p>
                    </li>
                </ul>

                <fieldset v-if="hasControlled" class="mt-3 grid gap-2 rounded-xl border border-warn/40 bg-warn-soft p-3">
                    <legend class="px-1 text-[13px] font-semibold"><ShieldAlert class="me-1 inline size-4" aria-hidden="true" />{{ t('pharmacy.sell.prescription') }}</legend>
                    <AppField v-slot="{ id }" :label="t('pharmacy.sell.patient_name')" :error="errors['prescription.patient_name']?.[0]">
                        <input :id="id" v-model="prescription.patient_name" class="field-input" maxlength="120" :readonly="!!fromRx" />
                    </AppField>
                    <p v-if="fromRx" class="text-[12px] text-muted">{{ t('pharmacy.sell.rx_details') }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        <AppField v-slot="{ id }" :label="t('pharmacy.sell.bmdc')" :error="errors['prescription.prescriber_bmdc']?.[0]">
                            <input :id="id" v-model="prescription.prescriber_bmdc" class="field-input font-mono" dir="ltr" maxlength="20" placeholder="A-12345" :readonly="!!fromRx && !!fromRx.doctor_registration_no" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('pharmacy.sell.doctor')" optional>
                            <input :id="id" v-model="prescription.prescriber_name" class="field-input" maxlength="120" :readonly="!!fromRx" />
                        </AppField>
                    </div>
                </fieldset>

                <details class="mt-3 text-[13px]">
                    <summary class="cursor-pointer text-muted">{{ t('pharmacy.sell.customer') }}</summary>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <input v-model="customer.name" class="field-input" maxlength="120" :placeholder="t('pharmacy.sell.customer_name')" :aria-label="t('pharmacy.sell.customer_name')" />
                        <input v-model="customer.phone" class="field-input" dir="ltr" inputmode="tel" maxlength="20" :placeholder="t('pharmacy.sell.customer_phone')" :aria-label="t('pharmacy.sell.customer_phone')" />
                    </div>
                </details>

                <dl class="mt-3 grid grid-cols-2 gap-y-1 border-t border-line pt-3 text-[13.5px]">
                    <dt class="text-muted">{{ t('pharmacy.sell.subtotal') }}</dt><dd class="tabular text-end">{{ money(subtotal) }}</dd>
                    <dt class="text-muted"><label for="pharmacy-discount">{{ t('pharmacy.sell.discount') }}</label></dt>
                    <dd class="justify-self-end"><MoneyInput id="pharmacy-discount" v-model="discount" :currency="currency" class="w-32" /></dd>
                    <template v-if="taxOnTop"><dt class="text-muted">{{ t('pharmacy.sell.vat') }}</dt><dd class="tabular text-end">{{ money(taxOnTop) }}</dd></template>
                    <dt class="text-[16px] font-semibold">{{ t('pharmacy.sell.total') }}</dt><dd class="tabular text-end text-[18px] font-bold">{{ money(total) }}</dd>
                </dl>

                <div class="mt-3 flex flex-wrap gap-2" role="radiogroup" :aria-label="t('pharmacy.sell.method')">
                    <button v-for="option in register?.payment_methods ?? ['cash']" :key="option" type="button" role="radio" :aria-checked="method === option ? 'true' : 'false'"
                            class="rounded-lg border px-3 py-1.5 text-[13px] font-medium" :class="method === option ? 'border-brand bg-brand-soft text-brand-text' : 'border-line'" @click="method = option">
                        {{ t(`pharmacy.methods.${option}`) }}
                    </button>
                </div>
                <AppField v-if="method === 'cash'" v-slot="{ id }" class="mt-2" :label="t('pharmacy.sell.received')">
                    <MoneyInput :id="id" v-model="received" :currency="currency" />
                </AppField>
                <p v-if="change" class="mt-1 text-[13px]">{{ t('pharmacy.sell.change') }} <strong class="tabular">{{ money(change) }}</strong></p>
                <AppButton class="mt-4 w-full" variant="primary" size="lg" :loading="selling" :disabled="!canComplete || !can('pharmacy.sell')" @click="complete">
                    {{ t('pharmacy.sell.complete', { amount: money(total) }) }}
                </AppButton>
                <p v-if="needsPrescription && !(prescription.patient_name.trim() && prescription.prescriber_bmdc.trim())" class="mt-2 text-center text-[12px] text-muted">{{ t('pharmacy.sell.prescription_hint') }}</p>
            </section>
        </div>

        <AppDialog :open="rx.open" :title="t('pharmacy.sell.rx_title')" :icon="FileText" @close="rx.open = false">
            <form class="flex gap-2" novalidate @submit.prevent="findRx">
                <input v-model="rx.search" class="field-input" maxlength="60" :placeholder="t('pharmacy.sell.rx_search')" :aria-label="t('pharmacy.sell.rx_search')" data-autofocus />
                <AppButton type="submit" variant="primary" :icon="Search" :loading="rx.busy" :disabled="rx.search.trim().length < 2">{{ t('pharmacy.sell.rx_find') }}</AppButton>
            </form>
            <p class="mt-1.5 text-[12px] text-muted">{{ t('pharmacy.sell.rx_hint', { days: formatNumber(counter.data.value?.meta?.prescription_days ?? 30) }) }}</p>
            <p v-if="rx.error" class="mt-3 text-[13px] text-bad" role="alert">{{ rx.error }}</p>
            <p v-else-if="rx.rows && !rx.rows.length" class="mt-3 text-[13.5px] text-muted">{{ t('pharmacy.sell.rx_none') }}</p>
            <ul v-else-if="rx.rows" class="mt-3 divide-y divide-line">
                <li v-for="row in rx.rows" :key="row.id" class="flex items-center gap-3 py-2.5">
                    <span class="min-w-0 flex-1 text-[13.5px]">
                        <span class="block font-medium">{{ row.patient_name }} <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ row.patient_mrn }}</span></span>
                        <span class="block text-[12.5px] text-muted">{{ row.doctor_name }} · {{ formatDate(row.date) }}</span>
                    </span>
                    <AppButton size="sm" variant="primary" :disabled="rx.busy" @click="takeRx(row.id)">{{ t('pharmacy.sell.rx_take') }}</AppButton>
                </li>
            </ul>
        </AppDialog>

        <AppDialog :open="!!substitutesOf" :title="t('pharmacy.sell.substitutes_of', { name: substitutesOf ? name(substitutesOf) : '' })" :icon="Repeat" @close="substitutesOf = null">
            <p v-if="!substitutes.length" class="text-[13.5px] text-muted">{{ t('pharmacy.sell.no_substitutes') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="row in substitutes" :key="row.medicine_id" class="flex items-center gap-3 py-2.5">
                    <span class="min-w-0 flex-1 text-[13.5px]"><span class="font-medium">{{ name(row) }}</span> · MRP {{ money(row.mrp_minor) }} · {{ t('pharmacy.sell.in_stock', { count: formatNumber(pieces(row.sellable_milli)) }) }}</span>
                    <AppButton size="sm" variant="primary" :disabled="!row.sellable_milli" @click="add(row); substitutesOf = null">{{ t('pharmacy.sell.add') }}</AppButton>
                </li>
            </ul>
        </AppDialog>
    </PageFrame>
</template>
