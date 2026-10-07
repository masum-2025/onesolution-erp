<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowLeft, Banknote, CloudOff, CreditCard, Hourglass, Maximize, Minimize, Minus, PauseCircle, Percent, Phone, Plus, Printer, ReceiptText, ScanBarcode, Smartphone, Store, Trash2, UserRound, X } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { enqueueOffline, offline, offlineRecords } from '@/lib/offline';
import { readPref, writePref } from '@/lib/storage';
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { posApi } from '../api';
import { addToCart, amountToMinor, discountShare, formatQuantity, holdCart, MAX_HELD, minorToText, percentToBp, priceCart, quickCash, readHeld, resumeCart, saleBody, settle } from '../lib';

/**
 * The counter. Choose a counter (remembered on this device), open a shift
 * with a float, then sell: scan a barcode or tap an item (again for one
 * more), change quantities and discounts, and take payment — cash with
 * quick amounts and the change worked out, card, mobile wallet, or a mix.
 * Without a connection the sale is kept on the device and sent later.
 * A cart can be put on hold (a customer went back for something) and taken
 * up again; held carts stay on this device per counter, without names.
 * Laid out like a counter terminal: the bill on the left (scan box, lines
 * as a table, totals, hold, payment buttons), item buttons by category on
 * the right. It fills the window (no sidebar or header), can take the whole
 * screen, and works from the keyboard: F2 scan, F4 cash, F6 card, F7 mobile,
 * F8 hold, F9 a new bill, Delete removes the chosen line, + and − change it.
 */
const pos = posApi(currentOrganization().id);
// Whole screen, like a counter terminal (the browser's full screen; Esc leaves it too).
const fullScreen = ref(Boolean(globalThis.document?.fullscreenElement));
const syncFullScreen = () => { fullScreen.value = Boolean(document.fullscreenElement); };
async function toggleFullScreen() {
    try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await document.documentElement.requestFullscreen();
    } catch {
        // Not allowed here (e.g. an embedded view): the till still fills the window.
    }
}
onMounted(() => document.addEventListener('fullscreenchange', syncFullScreen));
onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', syncFullScreen);
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
});
const router = useRouter();
const registers = useResource(() => pos.registers());
const registerList = computed(() => (registers.data.value?.data ?? []).filter((row) => row.is_active));
const registerId = ref(readPref('pos.register') ?? '');
const register = computed(() => registerList.value.find((row) => row.id === registerId.value) ?? null);
watch(registerList, (list) => {
    if (!register.value && list.length) registerId.value = list[0].id;
}, { immediate: true });
watch(registerId, (id) => id && writePref('pos.register', id));
const shift = computed(() => register.value?.session ?? null);

// The catalogue: online from the server, offline from what the device kept.
const catalogue = reactive({ items: [], units: {}, categories: {}, meta: {}, loading: false, error: null });
async function loadCatalogue() {
    if (!register.value) return;
    catalogue.loading = true;
    catalogue.error = null;
    try {
        const response = await pos.catalogue(register.value.id);
        Object.assign(catalogue, { items: response.data.items, units: response.data.units, categories: response.data.categories ?? {}, meta: response.meta });
    } catch (error) {
        const kept = offline.enabled ? await offlineRecords('pos.sale') : [];
        if (kept.length) Object.assign(catalogue, { items: kept, meta: { ...catalogue.meta, offline: true } });
        else catalogue.error = error;
    } finally {
        catalogue.loading = false;
    }
}
watch(register, loadCatalogue);
onMounted(loadCatalogue);
const currency = computed(() => catalogue.meta.currency ?? registers.data.value?.meta?.currency);
const money = (amount) => formatMoney({ amount, currency: currency.value });

// Finding items.
const search = ref('');
const scanBox = ref(null);
// Item buttons by category (only categories that have items to sell).
const category = ref('');
const categoryTabs = computed(() => Object.entries(catalogue.categories).filter(([id]) => catalogue.items.some((item) => item.category_id === id)).map(([id, name]) => ({ id, name })));
const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();
    const list = needle
        ? catalogue.items.filter((item) => item.name.toLowerCase().includes(needle) || item.sku.toLowerCase().includes(needle) || item.barcode === search.value.trim())
        : catalogue.items.filter((item) => !category.value || item.category_id === category.value);
    return list.slice(0, 120);
});
function scanned() {
    const code = search.value.trim();
    const exact = catalogue.items.find((item) => item.barcode === code || item.sku.toLowerCase() === code.toLowerCase());
    if (exact) {
        add(exact);
        search.value = '';
    } else if (shown.value.length === 1) {
        add(shown.value[0]);
        search.value = '';
    } else {
        toast.error(t('pos.till.not_found', { code }));
    }
}

// The cart.
const cart = ref([]);
const customer = ref('');
// A returning customer by mobile number (CRM's contact when it is on, else earlier sales here).
const phone = ref('');
const found = ref(null);
const looking = ref(false);
async function lookUp() {
    const typed = phone.value.trim();
    found.value = null;
    if (typed.replace(/[^0-9০-৯]/g, '').length < 10) return;
    looking.value = true;
    try {
        const { data } = await pos.customer(typed);
        found.value = data;
        if (data.found && data.name && !customer.value.trim()) customer.value = data.name;
    } catch (error) {
        found.value = { error: error.message };
    } finally {
        looking.value = false;
    }
}
const includeTax = computed(() => catalogue.meta.prices_include_tax ?? true);
const priced = computed(() => priceCart(cart.value, includeTax.value));
const overLimit = computed(() => discountShare(priced.value.discount, priced.value.subtotal) > percentToBp(catalogue.meta.max_discount_percent ?? '0') && !can('pos.supervise'));
const decimals = (line) => catalogue.units[line.unit_id]?.decimals ?? 0;
const step = (line) => 10 ** (3 - Math.min(3, decimals(line)));
// The chosen bill line (keys and the discount act on it); the last one added by default.
const chosen = ref(-1);
function add(item) {
    addToCart(cart.value, item);
    chosen.value = cart.value.findIndex((line) => line.item_id === item.id);
    nextTick(() => scanBox.value?.focus());
}
function removeLine(index) {
    cart.value.splice(index, 1);
    chosen.value = Math.min(chosen.value, cart.value.length - 1);
}
const itemCount = computed(() => cart.value.reduce((sum, line) => sum + line.quantity_milli, 0));
function bump(line, by) {
    const next = line.quantity_milli + by * Math.max(step(line), 1000);
    if (next <= 0) removeLine(cart.value.indexOf(line));
    else line.quantity_milli = next;
}
const discountFor = ref(null);
const discountText = ref('');
function editDiscount(line) {
    discountFor.value = line;
    discountText.value = minorToText(line.discount_minor || null, currency.value);
}
function saveDiscount() {
    const value = discountText.value.trim() === '' ? 0 : amountToMinor(discountText.value, currency.value);
    if (value === null) {
        toast.error(t('pos.till.bad_amount'));
        return;
    }
    discountFor.value.discount_minor = value;
    discountFor.value = null;
}
function clearCart() {
    cart.value = [];
    chosen.value = -1;
    customer.value = '';
    phone.value = '';
    found.value = null;
}

// Carts on hold at this counter (this device only; prices refreshed on resume).
const heldKey = computed(() => `pos.held.${registerId.value}`);
const held = ref([]);
watch(heldKey, () => {
    held.value = readHeld(readPref(heldKey.value));
}, { immediate: true });
const saveHeld = (list) => {
    held.value = list;
    writePref(heldKey.value, list.length ? JSON.stringify(list) : null);
};
const showHeld = ref(false);
function hold() {
    if (!cart.value.length) return;
    if (held.value.length >= MAX_HELD) {
        toast.error(t('pos.till.held_full', { count: formatNumber(MAX_HELD) }));
        return;
    }
    saveHeld(holdCart(held.value, cart.value, globalThis.crypto?.randomUUID?.() ?? `${Date.now()}`, new Date().toISOString()));
    clearCart();
    toast.success(t('pos.till.held_done'));
}
function resume(id) {
    // What is in the cart now goes on hold in its place.
    let list = held.value;
    if (cart.value.length) list = holdCart(list.filter((entry) => entry.id !== id), cart.value, globalThis.crypto?.randomUUID?.() ?? `${Date.now()}`, new Date().toISOString()).concat(list.filter((entry) => entry.id === id));
    const result = resumeCart(list, id, catalogue.items);
    customer.value = '';
    cart.value = result.cart;
    saveHeld(result.held);
    showHeld.value = false;
    if (result.dropped) toast.error(t('pos.till.held_dropped', { count: formatNumber(result.dropped) }));
}
function discardHeld(id) {
    saveHeld(held.value.filter((entry) => entry.id !== id));
    if (!held.value.length) showHeld.value = false;
}
const heldTotal = (entry) => priceCart(resumeCart([entry], entry.id, catalogue.items).cart, includeTax.value).total;

// Opening a shift.
const floatText = ref('');
const opening = ref(false);
async function openShift() {
    const float = floatText.value.trim() === '' ? 0 : amountToMinor(floatText.value, currency.value);
    if (float === null) {
        toast.error(t('pos.till.bad_amount'));
        return;
    }
    opening.value = true;
    try {
        await pos.openShift(register.value.id, float);
        toast.success(t('pos.till.shift_opened'));
        await registers.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        opening.value = false;
    }
}

// Taking payment.
const paying = ref(false);
const payments = reactive({ cash: '', card: '', mobile: '', reference: '' });
const paymentRows = computed(() => (register.value?.payment_methods ?? ['cash']).map((method) => ({ method, amount_minor: amountToMinor(payments[method] || '0', currency.value) ?? 0, reference: method !== 'cash' ? payments.reference : null })));
const settlement = computed(() => settle(priced.value.total, paymentRows.value));
const icons = { cash: Banknote, card: CreditCard, mobile: Smartphone };
function startPayment(method = null) {
    if (!cart.value.length || overLimit.value) return;
    const methods = register.value?.payment_methods ?? ['cash'];
    Object.assign(payments, { cash: '', card: '', mobile: '', reference: '' });
    payments[methods.includes(method) ? method : methods[0]] = minorToText(priced.value.total, currency.value);
    paying.value = true;
}
const methods = computed(() => register.value?.payment_methods ?? ['cash']);

// Keyboard keys at the counter (not while a dialog is open).
function onKey(event) {
    if (!shift.value || paying.value || done.value || discountFor.value || showHeld.value) return;
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName) && event.target !== scanBox.value;
    const keys = { F2: () => scanBox.value?.focus(), F4: () => startPayment('cash'), F6: () => startPayment('card'), F7: () => startPayment('mobile'), F8: hold, F9: clearCart };
    if (keys[event.key]) {
        event.preventDefault();
        keys[event.key]();
        return;
    }
    if (typing || search.value || chosen.value < 0 || !cart.value[chosen.value]) return;
    if (event.key === 'Delete') removeLine(chosen.value);
    else if (event.key === '+') bump(cart.value[chosen.value], 1);
    else if (event.key === '-') bump(cart.value[chosen.value], -1);
    else return;
    event.preventDefault();
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
const completing = ref(false);
const done = ref(null);
async function complete() {
    if (!settlement.value.ok) return;
    const opId = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${cart.value.length}`;
    const body = saleBody(cart.value, paymentRows.value, { op_id: opId, register_id: register.value.id, ...(customer.value.trim() ? { customer_name: customer.value.trim() } : {}), ...(phone.value.trim() ? { customer_phone: phone.value.trim() } : {}) });
    completing.value = true;
    try {
        if (globalThis.navigator?.onLine === false) throw Object.assign(new Error('offline'), { code: 'network' });
        const { data } = await pos.sell(body);
        done.value = { id: data.id, number: data.number, change: data.change_minor, offline: false };
    } catch (error) {
        if (error.code !== 'network') {
            toast.error(error.message);
            return;
        }
        if (!offline.enabled || catalogue.meta.offline_sales === false) {
            toast.error(t('pos.till.offline_off'));
            return;
        }
        // The queue gives the sale its own op id. No customer details are kept on the device (personal data).
        const data = { ...body };
        delete data.op_id;
        delete data.customer_name;
        delete data.customer_phone;
        await enqueueOffline('pos.sale', 'create', { ...data, session_id: shift.value?.id ?? null });
        done.value = { id: null, number: null, change: settlement.value.change, offline: true };
    } finally {
        completing.value = false;
    }
    paying.value = false;
    clearCart();
}
function nextSale() {
    done.value = null;
    nextTick(() => scanBox.value?.focus());
}
</script>

<template>
    <div>
        <SkeletonRows v-if="registers.loading.value && !registers.data.value" :rows="6" />
        <ErrorState v-else-if="registers.error.value" :error="registers.error.value" @retry="registers.reload()" />
        <EmptyState v-else-if="!registerList.length" :icon="Store" :title="t('pos.till.no_registers')" :text="t('pos.till.no_registers_text')">
            <AppButton v-if="can('pos.manage')" variant="primary" :to="{ name: 'pos-registers' }">{{ t('pos.registers.add') }}</AppButton>
        </EmptyState>

        <template v-else>
            <!-- The counter bar -->
            <div class="mb-3 flex flex-wrap items-center gap-2 rounded-2xl bg-side px-3 py-2 text-side-fg">
                <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="!text-side-fg hover:!bg-white/10" :to="{ name: 'pos-sales' }">{{ t('pos.till.leave') }}</AppButton>
                <select v-if="registerList.length > 1" v-model="registerId" class="field-input h-8 w-auto py-0 font-medium" :aria-label="t('pos.till.counter')">
                    <option v-for="row in registerList" :key="row.id" :value="row.id">{{ row.name }}</option>
                </select>
                <h1 v-else class="text-[16px] font-semibold">{{ register?.name }}</h1>
                <AppBadge v-if="shift" tone="ok" dot>{{ t('pos.till.shift_open') }}</AppBadge>
                <AppBadge v-if="!offline.online || catalogue.meta.offline" tone="warn" dot><CloudOff class="me-1 inline size-3.5" aria-hidden="true" />{{ t('pos.till.offline') }}</AppBadge>
                <AppBadge v-if="offline.pending" tone="brand">{{ t('pos.till.pending', { count: formatNumber(offline.pending) }) }}</AppBadge>
                <span class="flex-1" />
                <span class="hidden text-[12px] opacity-75 xl:inline">{{ t('pos.till.keys') }}</span>
                <AppButton v-if="shift" variant="ghost" size="sm" :icon="ReceiptText" class="!text-side-fg hover:!bg-white/10" :to="{ name: 'pos-shift', params: { id: shift.id } }">{{ t('pos.till.close_shift') }}</AppButton>
                <AppButton variant="ghost" size="sm" :icon="fullScreen ? Minimize : Maximize" class="!text-side-fg hover:!bg-white/10" @click="toggleFullScreen">{{ t(fullScreen ? 'pos.till.exit_full_screen' : 'pos.till.full_screen') }}</AppButton>
            </div>

            <!-- No shift yet -->
            <section v-if="!shift" class="card mx-auto mt-10 max-w-md p-6 text-center">
                <span class="mx-auto mb-3 grid size-14 place-items-center rounded-2xl bg-brand-soft text-brand-text"><Store class="size-7" aria-hidden="true" /></span>
                <h2 class="text-[17px] font-semibold">{{ t('pos.till.open_title') }}</h2>
                <p class="mt-1 text-[13px] text-muted">{{ t('pos.till.open_text') }}</p>
                <form class="mt-4 grid gap-3 text-start" novalidate @submit.prevent="openShift">
                    <AppField v-slot="{ id }" :label="t('pos.till.float')" :hint="t('pos.till.float_hint')">
                        <input :id="id" v-model="floatText" inputmode="decimal" class="field-input tabular text-end text-[18px]" dir="ltr" placeholder="0.00" />
                    </AppField>
                    <AppButton type="submit" variant="primary" size="lg" :loading="opening">{{ t('pos.till.open') }}</AppButton>
                </form>
            </section>

            <!-- Selling: the bill (left), item buttons (right) -->
            <div v-else class="grid grid-cols-1 gap-3 lg:h-[calc(100dvh-5.25rem)] lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                <!-- The bill -->
                <section class="card flex min-h-0 flex-col overflow-hidden">
                    <div class="grid gap-2 border-b border-line p-3 sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)]">
                        <div class="relative">
                            <ScanBarcode class="pointer-events-none absolute inset-y-0 start-3 my-auto size-5 text-muted" aria-hidden="true" />
                            <input ref="scanBox" v-model="search" autofocus class="field-input h-11 ps-10 text-[15px]" :placeholder="t('pos.till.scan')" :aria-label="t('pos.till.scan')" @keydown.enter.prevent="scanned" />
                        </div>
                        <div class="relative">
                            <Phone class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                            <input v-model="phone" type="tel" inputmode="tel" dir="ltr" class="field-input h-11 ps-9 tabular" maxlength="20" :placeholder="t('pos.till.phone')" :aria-label="t('pos.till.phone')" @blur="lookUp" @keydown.enter.prevent="lookUp" />
                        </div>
                        <div class="relative">
                            <UserRound class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                            <input v-model="customer" class="field-input h-11 ps-9" maxlength="150" :placeholder="t('pos.till.customer')" :aria-label="t('pos.till.customer')" />
                        </div>
                        <p v-if="looking" class="text-[12px] text-muted sm:col-span-3">{{ t('pos.till.looking') }}</p>
                        <p v-else-if="found?.error" class="text-[12px] text-bad sm:col-span-3">{{ found.error }}</p>
                        <p v-else-if="found?.found" class="rounded-lg bg-ok-soft px-3 py-1.5 text-[12px] text-ok sm:col-span-3">
                            {{ t('pos.till.returning', { name: found.name ?? '', count: formatNumber(found.purchases) }) }}<template v-if="found.points !== null"> · {{ t('pos.till.points', { count: formatNumber(found.points) }) }}</template>
                        </p>
                        <p v-else-if="found" class="text-[12px] text-muted sm:col-span-3">{{ t('pos.till.new_customer') }}</p>
                    </div>

                    <!-- Lines -->
                    <div class="min-h-40 flex-1 overflow-y-auto">
                        <p v-if="!cart.length" class="grid h-full place-items-center px-4 py-10 text-center text-[14px] text-muted">{{ t('pos.till.cart_empty') }}</p>
                        <table v-else class="w-full text-[13.5px]">
                            <thead class="sticky top-0 z-10 bg-subtle text-[11.5px] uppercase tracking-wide text-muted">
                                <tr>
                                    <th class="w-8 px-2 py-2 text-start font-medium">#</th>
                                    <th class="px-2 py-2 text-start font-medium">{{ t('pos.till.item') }}</th>
                                    <th class="px-2 py-2 text-center font-medium">{{ t('pos.till.quantity') }}</th>
                                    <th class="hidden px-2 py-2 text-end font-medium sm:table-cell">{{ t('pos.till.price') }}</th>
                                    <th class="px-2 py-2 text-end font-medium">{{ t('pos.till.line_total') }}</th>
                                    <th class="w-8" />
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="(line, index) in cart" :key="line.item_id" class="cursor-pointer" :class="chosen === index ? 'bg-brand-soft' : 'hover:bg-surface-2'" @click="chosen = index">
                                    <td class="px-2 py-2 text-muted tabular">{{ formatNumber(index + 1) }}</td>
                                    <td class="px-2 py-2">
                                        <span class="block font-medium leading-snug">{{ line.name }}</span>
                                        <button type="button" class="text-[11.5px] font-medium text-brand-text hover:underline" @click.stop="editDiscount(line)">
                                            {{ line.discount_minor ? t('pos.till.discount_of', { amount: money(line.discount_minor) }) : t('pos.till.discount') }}
                                        </button>
                                    </td>
                                    <td class="px-1 py-2">
                                        <span class="flex items-center justify-center gap-1">
                                            <AppButton size="icon-sm" variant="secondary" :icon="Minus" :aria-label="t('pos.till.less')" @click.stop="bump(line, -1)" />
                                            <span class="tabular w-10 text-center font-semibold">{{ formatQuantity(line.quantity_milli) }}</span>
                                            <AppButton size="icon-sm" variant="secondary" :icon="Plus" :aria-label="t('pos.till.more')" @click.stop="bump(line, 1)" />
                                        </span>
                                    </td>
                                    <td class="hidden px-2 py-2 text-end tabular sm:table-cell">{{ money(line.unit_price_minor) }}</td>
                                    <td class="px-2 py-2 text-end tabular font-semibold">{{ money(priced.lines[index].total) }}</td>
                                    <td class="px-1 py-2"><AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('pos.till.remove')" @click.stop="removeLine(index)" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals and actions -->
                    <footer class="border-t border-line bg-surface-2 p-3">
                        <div class="grid grid-cols-[1fr_auto] items-end gap-3">
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5 text-[13px]">
                                <dt class="text-muted">{{ t('pos.till.items_count') }}</dt><dd class="tabular">{{ formatQuantity(itemCount) }}</dd>
                                <dt class="text-muted">{{ t('pos.till.subtotal') }}</dt><dd class="tabular">{{ money(priced.subtotal) }}</dd>
                                <template v-if="priced.discount"><dt class="text-muted">{{ t('pos.till.discounts') }}</dt><dd class="tabular text-bad">−{{ money(priced.discount) }}</dd></template>
                                <dt class="text-muted">{{ t(includeTax ? 'pos.till.vat_included' : 'pos.till.vat') }}</dt><dd class="tabular">{{ money(priced.tax) }}</dd>
                            </dl>
                            <div class="text-end">
                                <p class="text-[12px] font-medium uppercase tracking-wide text-muted">{{ t('pos.till.total') }}</p>
                                <p class="tabular text-[34px] font-bold leading-none text-brand-text">{{ money(priced.total) }}</p>
                            </div>
                        </div>
                        <p v-if="overLimit" class="mt-2 rounded-lg bg-warn-soft px-3 py-2 text-[12px] text-warn">{{ t('pos.till.over_limit', { percent: catalogue.meta.max_discount_percent }) }}</p>
                        <div class="mt-3 grid grid-cols-4 gap-2">
                            <AppButton variant="secondary" :icon="PauseCircle" :disabled="!cart.length" @click="hold">{{ t('pos.till.hold') }} <kbd class="kbd">F8</kbd></AppButton>
                            <AppButton variant="secondary" :icon="Hourglass" :disabled="!held.length" @click="showHeld = true">{{ t('pos.till.held', { count: formatNumber(held.length) }) }}</AppButton>
                            <AppButton variant="secondary" :icon="Percent" :disabled="chosen < 0 || !cart[chosen]" @click="editDiscount(cart[chosen])">{{ t('pos.till.discount') }}</AppButton>
                            <AppButton variant="danger-soft" :icon="X" :disabled="!cart.length" @click="clearCart">{{ t('pos.till.new_bill') }} <kbd class="kbd">F9</kbd></AppButton>
                        </div>
                        <div class="mt-2 grid gap-2" :class="methods.length > 2 ? 'grid-cols-3' : methods.length === 2 ? 'grid-cols-2' : 'grid-cols-1'">
                            <button v-for="method in methods" :key="method" type="button" :disabled="!cart.length || overLimit"
                                class="flex h-14 items-center justify-center gap-2 rounded-xl text-[16px] font-semibold text-white shadow-sm transition active:translate-y-px disabled:opacity-45"
                                :class="{ cash: 'bg-ok hover:brightness-110', card: 'bg-brand hover:brightness-110', mobile: 'bg-side hover:brightness-125' }[method]" @click="startPayment(method)">
                                <component :is="icons[method]" class="size-5" aria-hidden="true" />{{ t(`pos.methods.${method}`) }}
                                <kbd class="kbd !border-white/40 !bg-white/15 !text-white">{{ { cash: 'F4', card: 'F6', mobile: 'F7' }[method] }}</kbd>
                            </button>
                        </div>
                    </footer>
                </section>

                <!-- Item buttons -->
                <section class="card flex min-h-0 flex-col overflow-hidden">
                    <div class="flex gap-1.5 overflow-x-auto border-b border-line p-2" role="tablist" :aria-label="t('pos.till.categories')">
                        <button type="button" role="tab" :aria-selected="!category" class="shrink-0 rounded-lg px-3 py-2 text-[13px] font-medium"
                            :class="!category ? 'bg-brand text-brand-fg' : 'bg-subtle hover:bg-surface-2'" @click="category = ''; search = ''">{{ t('pos.till.all_items') }}</button>
                        <button v-for="tab in categoryTabs" :key="tab.id" type="button" role="tab" :aria-selected="category === tab.id" class="shrink-0 rounded-lg px-3 py-2 text-[13px] font-medium"
                            :class="category === tab.id ? 'bg-brand text-brand-fg' : 'bg-subtle hover:bg-surface-2'" @click="category = tab.id; search = ''">{{ tab.name }}</button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto p-2">
                        <SkeletonRows v-if="catalogue.loading && !catalogue.items.length" :rows="4" />
                        <ErrorState v-else-if="catalogue.error" compact :error="catalogue.error" @retry="loadCatalogue" />
                        <p v-else-if="!shown.length" class="px-5 py-8 text-center text-[13.5px] text-muted">{{ t('pos.till.nothing_found') }}</p>
                        <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                            <button v-for="item in shown" :key="item.id" type="button"
                                class="flex min-h-[5.5rem] flex-col items-start justify-between rounded-xl border border-line bg-surface p-2.5 text-start shadow-xs transition hover:border-brand hover:shadow-md active:scale-[0.97]"
                                :class="item.on_hand_milli !== null && item.on_hand_milli <= 0 ? 'opacity-50' : ''" @click="add(item)">
                                <span class="line-clamp-2 text-[13px] font-medium leading-snug">{{ item.name }}</span>
                                <span class="mt-1.5 flex w-full items-end justify-between gap-1">
                                    <span class="tabular text-[14px] font-bold text-brand-text">{{ money(item.sale_price_minor) }}</span>
                                    <span v-if="item.on_hand_milli !== null" class="tabular text-[11px] text-muted">{{ formatQuantity(item.on_hand_milli) }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </section>
            </div>
        </template>

        <!-- Discount for a line -->
        <AppDialog :open="discountFor !== null" :title="t('pos.till.discount')" :description="discountFor?.name ?? ''" size="sm" @close="discountFor = null">
            <form id="pos-discount" novalidate @submit.prevent="saveDiscount">
                <AppField v-slot="{ id }" :label="t('pos.till.discount_amount')">
                    <input :id="id" v-model="discountText" autofocus inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="discountFor = null">{{ t('pos.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="pos-discount">{{ t('pos.common.save') }}</AppButton>
            </template>
        </AppDialog>

        <!-- Payment -->
        <AppDialog :open="paying" :title="t('pos.till.payment')" :icon="Banknote" @close="paying = false">
            <div class="mb-4 rounded-2xl bg-brand-soft px-5 py-4 text-center">
                <p class="text-[12.5px] text-muted">{{ t('pos.till.to_pay') }}</p>
                <p class="tabular text-[30px] font-semibold text-brand-text">{{ money(priced.total) }}</p>
            </div>
            <form id="pos-pay" class="grid gap-3" novalidate @submit.prevent="complete">
                <div v-for="method in register?.payment_methods ?? []" :key="method">
                    <AppField v-slot="{ id }" :label="t(`pos.methods.${method}`)">
                        <div class="relative">
                            <component :is="icons[method]" class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4.5 text-muted" aria-hidden="true" />
                            <input :id="id" v-model="payments[method]" inputmode="decimal" class="field-input h-11 ps-10 tabular text-end text-[16px]" dir="ltr" placeholder="0.00" />
                        </div>
                    </AppField>
                    <div v-if="method === 'cash'" class="mt-2 flex flex-wrap gap-2">
                        <button v-for="amount in quickCash(priced.total, currency)" :key="amount" type="button" class="rounded-lg border border-line px-3 py-1.5 text-[13px] font-medium tabular hover:border-brand hover:bg-brand-soft" @click="payments.cash = minorToText(amount, currency)">
                            {{ money(amount) }}
                        </button>
                    </div>
                </div>
                <AppField v-if="(register?.payment_methods ?? []).some((method) => method !== 'cash')" v-slot="{ id }" :label="t('pos.till.reference')" optional>
                    <input :id="id" v-model="payments.reference" class="field-input" maxlength="80" :placeholder="t('pos.till.reference_hint')" />
                </AppField>
            </form>
            <div class="mt-4 grid grid-cols-2 gap-3 text-center">
                <div class="rounded-xl bg-subtle px-3 py-2.5"><p class="text-[12px] text-muted">{{ t('pos.till.paid') }}</p><p class="tabular text-[17px] font-semibold">{{ money(settlement.paid) }}</p></div>
                <div class="rounded-xl px-3 py-2.5" :class="settlement.ok ? 'bg-ok-soft' : 'bg-warn-soft'">
                    <p class="text-[12px] text-muted">{{ settlement.reason === 'short' ? t('pos.till.still_due') : t('pos.till.change') }}</p>
                    <p class="tabular text-[17px] font-semibold" :class="settlement.ok ? 'text-ok' : 'text-warn'">{{ money(settlement.reason === 'short' ? settlement.due : settlement.change) }}</p>
                </div>
            </div>
            <p v-if="settlement.reason === 'change_without_cash'" class="mt-2 text-[12.5px] text-warn">{{ t('pos.till.change_without_cash') }}</p>
            <template #footer>
                <AppButton variant="ghost" @click="paying = false">{{ t('pos.common.cancel') }}</AppButton>
                <AppButton variant="primary" size="lg" type="submit" form="pos-pay" :loading="completing" :disabled="!settlement.ok">{{ t('pos.till.complete') }}</AppButton>
            </template>
        </AppDialog>

        <!-- Done -->
        <AppDialog :open="done !== null" :title="done?.offline ? t('pos.till.kept_offline') : t('pos.till.sold')" :icon="ReceiptText" size="sm" @close="nextSale">
            <div class="text-center">
                <p class="text-[12.5px] text-muted">{{ t('pos.till.give_change') }}</p>
                <p class="tabular text-[34px] font-semibold text-ok">{{ money(done?.change ?? 0) }}</p>
                <p v-if="done?.number" class="mt-1 font-mono text-[12.5px] text-muted" dir="ltr">{{ done.number }}</p>
                <p v-if="done?.offline" class="mt-2 text-[12.5px] text-muted">{{ t('pos.till.offline_text') }}</p>
            </div>
            <template #footer>
                <AppButton v-if="done?.id" variant="secondary" :icon="Printer" @click="router.push({ name: 'pos-sale', params: { id: done.id }, query: { print: '1' } })">{{ t('pos.till.receipt') }}</AppButton>
                <AppButton variant="primary" autofocus @click="nextSale">{{ t('pos.till.next') }}</AppButton>
            </template>
        </AppDialog>
        <AppDialog :open="showHeld" :title="t('pos.till.held_title')" :description="t('pos.till.held_text')" :icon="Hourglass" @close="showHeld = false">
            <ul class="divide-y divide-line">
                <li v-for="entry in held" :key="entry.id" class="flex items-center gap-3 py-2.5">
                    <span class="min-w-0 flex-1 text-[13.5px]">
                        <span class="block font-medium">{{ t('pos.till.held_lines', { count: formatNumber(entry.lines.length) }) }} · <span class="tabular">{{ money(heldTotal(entry)) }}</span></span>
                        <span class="block text-[12px] text-muted">{{ formatDateTime(entry.at) }}</span>
                    </span>
                    <AppButton size="sm" variant="primary" @click="resume(entry.id)">{{ t('pos.till.resume') }}</AppButton>
                    <AppButton size="sm" variant="ghost" :icon="Trash2" :aria-label="t('pos.till.discard')" @click="discardHeld(entry.id)" />
                </li>
            </ul>
        </AppDialog>
    </div>
</template>

<style scoped>
.kbd {
    margin-inline-start: 0.25rem;
    border: 1px solid var(--color-line-strong, #d0d5dd);
    border-radius: 4px;
    padding: 0 0.3rem;
    font-size: 10.5px;
    font-weight: 600;
    line-height: 1.45;
    opacity: 0.85;
}
</style>
