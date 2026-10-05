<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Banknote, CloudOff, CreditCard, Minus, Plus, Printer, ReceiptText, ScanBarcode, ShoppingCart, Smartphone, Store, X } from 'lucide-vue-next';
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
import { formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { posApi } from '../api';
import { addToCart, amountToMinor, discountShare, formatQuantity, minorToText, percentToBp, priceCart, quickCash, saleBody, settle } from '../lib';

/**
 * The counter. Choose a counter (remembered on this device), open a shift
 * with a float, then sell: scan a barcode or tap an item (again for one
 * more), change quantities and discounts, and take payment — cash with
 * quick amounts and the change worked out, card, mobile wallet, or a mix.
 * Without a connection the sale is kept on the device and sent later.
 */
const pos = posApi(currentOrganization().id);
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
const catalogue = reactive({ items: [], units: {}, meta: {}, loading: false, error: null });
async function loadCatalogue() {
    if (!register.value) return;
    catalogue.loading = true;
    catalogue.error = null;
    try {
        const response = await pos.catalogue(register.value.id);
        Object.assign(catalogue, { items: response.data.items, units: response.data.units, meta: response.meta });
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
const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();
    const list = needle ? catalogue.items.filter((item) => item.name.toLowerCase().includes(needle) || item.sku.toLowerCase().includes(needle) || item.barcode === search.value.trim()) : catalogue.items;
    return list.slice(0, 60);
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
const includeTax = computed(() => catalogue.meta.prices_include_tax ?? true);
const priced = computed(() => priceCart(cart.value, includeTax.value));
const overLimit = computed(() => discountShare(priced.value.discount, priced.value.subtotal) > percentToBp(catalogue.meta.max_discount_percent ?? '0') && !can('pos.supervise'));
const decimals = (line) => catalogue.units[line.unit_id]?.decimals ?? 0;
const step = (line) => 10 ** (3 - Math.min(3, decimals(line)));
function add(item) {
    addToCart(cart.value, item);
    nextTick(() => scanBox.value?.focus());
}
function bump(line, by) {
    const next = line.quantity_milli + by * Math.max(step(line), 1000);
    if (next <= 0) cart.value = cart.value.filter((row) => row !== line);
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
    customer.value = '';
}

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
function startPayment() {
    Object.assign(payments, { cash: '', card: '', mobile: '', reference: '' });
    payments[(register.value?.payment_methods ?? ['cash'])[0]] = minorToText(priced.value.total, currency.value);
    paying.value = true;
}
const completing = ref(false);
const done = ref(null);
async function complete() {
    if (!settlement.value.ok) return;
    const opId = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${cart.value.length}`;
    const body = saleBody(cart.value, paymentRows.value, { op_id: opId, register_id: register.value.id, ...(customer.value.trim() ? { customer_name: customer.value.trim() } : {}) });
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
        // The queue gives the sale its own op id.
        const data = { ...body };
        delete data.op_id;
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
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <select v-if="registerList.length > 1" v-model="registerId" class="field-input w-auto font-medium" :aria-label="t('pos.till.counter')">
                    <option v-for="row in registerList" :key="row.id" :value="row.id">{{ row.name }}</option>
                </select>
                <h1 v-else class="text-[20px] font-semibold">{{ register?.name }}</h1>
                <AppBadge v-if="shift" tone="ok" dot>{{ t('pos.till.shift_open') }}</AppBadge>
                <AppBadge v-if="!offline.online || catalogue.meta.offline" tone="warn" dot><CloudOff class="me-1 inline size-3.5" aria-hidden="true" />{{ t('pos.till.offline') }}</AppBadge>
                <AppBadge v-if="offline.pending" tone="brand">{{ t('pos.till.pending', { count: formatNumber(offline.pending) }) }}</AppBadge>
                <span class="flex-1" />
                <AppButton v-if="shift" variant="ghost" size="sm" :icon="ReceiptText" :to="{ name: 'pos-shift', params: { id: shift.id } }">{{ t('pos.till.close_shift') }}</AppButton>
            </div>

            <!-- No shift yet -->
            <section v-if="!shift" class="card mx-auto max-w-md p-6 text-center">
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

            <!-- Selling -->
            <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
                <section class="min-w-0">
                    <div class="relative mb-3">
                        <ScanBarcode class="pointer-events-none absolute inset-y-0 start-3.5 my-auto size-5 text-muted" aria-hidden="true" />
                        <input ref="scanBox" v-model="search" autofocus class="field-input h-12 ps-11 text-[15px]" :placeholder="t('pos.till.scan')" :aria-label="t('pos.till.scan')" @keydown.enter.prevent="scanned" />
                    </div>
                    <SkeletonRows v-if="catalogue.loading && !catalogue.items.length" :rows="4" />
                    <ErrorState v-else-if="catalogue.error" compact :error="catalogue.error" @retry="loadCatalogue" />
                    <p v-else-if="!shown.length" class="card px-5 py-8 text-center text-[13.5px] text-muted">{{ t('pos.till.nothing_found') }}</p>
                    <div v-else class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-4">
                        <button v-for="item in shown" :key="item.id" type="button"
                            class="card flex min-h-24 flex-col items-start justify-between p-3 text-start transition hover:border-brand hover:shadow-md active:scale-[0.98]"
                            :class="item.on_hand_milli !== null && item.on_hand_milli <= 0 ? 'opacity-60' : ''" @click="add(item)">
                            <span class="line-clamp-2 text-[13.5px] font-medium leading-snug">{{ item.name }}</span>
                            <span class="mt-2 flex w-full items-end justify-between gap-2">
                                <span class="tabular text-[15px] font-semibold text-brand-text">{{ money(item.sale_price_minor) }}</span>
                                <span v-if="item.on_hand_milli !== null" class="tabular text-[11.5px] text-muted">{{ formatQuantity(item.on_hand_milli) }}</span>
                            </span>
                        </button>
                    </div>
                </section>

                <!-- The cart -->
                <aside class="card flex flex-col lg:sticky lg:top-4 lg:max-h-[calc(100vh-8rem)]">
                    <header class="flex items-center justify-between border-b border-line px-4 py-3">
                        <h2 class="flex items-center gap-2 text-[15px] font-semibold"><ShoppingCart class="size-4.5" aria-hidden="true" />{{ t('pos.till.cart') }}</h2>
                        <AppButton v-if="cart.length" size="sm" variant="ghost" :icon="X" @click="clearCart">{{ t('pos.till.clear') }}</AppButton>
                    </header>
                    <p v-if="!cart.length" class="px-4 py-10 text-center text-[13px] text-muted">{{ t('pos.till.cart_empty') }}</p>
                    <ul v-else class="min-h-0 flex-1 divide-y divide-line overflow-y-auto">
                        <li v-for="(line, index) in cart" :key="line.item_id" class="px-4 py-2.5">
                            <div class="flex items-start justify-between gap-2">
                                <span class="min-w-0 text-[13.5px] font-medium">{{ line.name }}</span>
                                <span class="tabular shrink-0 text-[13.5px] font-semibold">{{ money(priced.lines[index].total) }}</span>
                            </div>
                            <div class="mt-1.5 flex items-center gap-2">
                                <AppButton size="icon-sm" variant="secondary" :icon="Minus" :aria-label="t('pos.till.less')" @click="bump(line, -1)" />
                                <span class="tabular w-12 text-center text-[14px] font-semibold">{{ formatQuantity(line.quantity_milli) }}</span>
                                <AppButton size="icon-sm" variant="secondary" :icon="Plus" :aria-label="t('pos.till.more')" @click="bump(line, 1)" />
                                <span class="text-[12px] text-muted">× {{ money(line.unit_price_minor) }}</span>
                                <span class="flex-1" />
                                <button type="button" class="text-[12px] font-medium text-brand-text hover:underline" @click="editDiscount(line)">
                                    {{ line.discount_minor ? t('pos.till.discount_of', { amount: money(line.discount_minor) }) : t('pos.till.discount') }}
                                </button>
                            </div>
                        </li>
                    </ul>
                    <footer class="border-t border-line px-4 py-3">
                        <input v-model="customer" class="field-input mb-3" maxlength="150" :placeholder="t('pos.till.customer')" :aria-label="t('pos.till.customer')" />
                        <dl class="grid grid-cols-[1fr_auto] gap-y-1 text-[13px]">
                            <dt class="text-muted">{{ t('pos.till.subtotal') }}</dt><dd class="tabular text-end">{{ money(priced.subtotal) }}</dd>
                            <template v-if="priced.discount"><dt class="text-muted">{{ t('pos.till.discounts') }}</dt><dd class="tabular text-end text-bad">−{{ money(priced.discount) }}</dd></template>
                            <dt class="text-muted">{{ t(includeTax ? 'pos.till.vat_included' : 'pos.till.vat') }}</dt><dd class="tabular text-end">{{ money(priced.tax) }}</dd>
                        </dl>
                        <p v-if="overLimit" class="mt-2 rounded-lg bg-warn-soft px-3 py-2 text-[12px] text-warn">{{ t('pos.till.over_limit', { percent: catalogue.meta.max_discount_percent }) }}</p>
                        <AppButton variant="primary" size="lg" class="mt-3 w-full justify-between text-[17px]" :disabled="!cart.length || overLimit" @click="startPayment">
                            <span>{{ t('pos.till.pay') }}</span><span class="tabular">{{ money(priced.total) }}</span>
                        </AppButton>
                    </footer>
                </aside>
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
    </div>
</template>
