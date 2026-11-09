<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, PackagePlus, Plus, Search, Trash2 } from 'lucide-vue-next';
import { AppButton, AppField, AppSegmented, cleanNumber, ErrorState, MoneyInput, newOpId, PageFrame, SkeletonRows, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { decimalStringToMinor, formatMoney, minorToDecimalString } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { i18n, t } from '@/lib/i18n';
import { onlineOrdersApi } from '../api';

/**
 * A new order typed in by staff (a phone call, a message): the customer,
 * where it goes, how it is paid and the items. The total here is a guide;
 * the server prices it again (VAT, the zone's charge) and its numbers are kept.
 */
const orders = onlineOrdersApi(currentOrganization().id);
const router = useRouter();
const options = useResource(() => orders.options());
const settings = computed(() => options.data.value?.data ?? null);
const currency = computed(() => settings.value?.currency ?? null);

// A channel (the Facebook inbox) may open this with the customer's name and phone filled in.
const prefill = useRoute().query;
const form = reactive({
    customer_name: String(prefill.customer_name ?? '').slice(0, 150), customer_phone: String(prefill.customer_phone ?? '').slice(0, 20),
    address: '', warehouse_id: '', delivery_zone: '', delivery_minor: 0, payment_method: 'cod', note: '',
});
const lines = ref([]); // { item, quantity: text }
const errors = ref({});
const saving = ref(false);
const opId = newOpId();

watch(settings, (value) => {
    if (!value) return;
    form.warehouse_id ||= value.warehouses[0]?.id ?? '';
    form.delivery_zone ||= value.zones[0]?.code ?? '';
    if (!value.cod_allowed) form.payment_method = 'prepaid';
}, { immediate: true });

const zoneName = (zone) => zone.name?.[i18n.locale] ?? zone.name?.en ?? zone.code;
const zoneCharge = computed(() => (settings.value?.zones ?? []).find((zone) => zone.code === form.delivery_zone)?.charge_minor ?? 0);
const deliveryMinor = computed(() => ((settings.value?.zones ?? []).length ? zoneCharge.value : form.delivery_minor ?? 0));
const paymentOptions = computed(() => [
    ...(settings.value?.cod_allowed ? [{ value: 'cod', label: t('online_orders.payment.cod') }] : []),
    { value: 'prepaid', label: t('online_orders.payment.prepaid') },
]);

// Items to add, searched in the chosen warehouse (what can still go out is shown).
const search = ref('');
const found = useResource(() => (form.warehouse_id ? orders.items({ warehouse_id: form.warehouse_id, ...(search.value.trim() ? { search: search.value.trim() } : {}) }) : Promise.resolve({ data: [] })), { immediate: false });
watch(() => form.warehouse_id, () => form.warehouse_id && found.reload(), { immediate: true });
const results = computed(() => (found.data.value?.data ?? []).filter((item) => !lines.value.some((line) => line.item.id === item.id)));

const milli = (text) => {
    const clean = cleanNumber(text ?? '');
    return /^\d+(\.\d{1,3})?$/.test(clean) ? decimalStringToMinor(clean, 3) : 0;
};
const shownQuantity = (value) => minorToDecimalString(value ?? 0, 3).replace(/\.?0+$/, '') || '0';
function add(item) {
    lines.value.push({ item, quantity: '1' });
}
const lineAmount = (line) => Math.floor((milli(line.quantity) * line.item.sale_price_minor + 500) / 1000);
const goods = computed(() => lines.value.reduce((sum, line) => sum + lineAmount(line), 0));
const total = computed(() => goods.value + deliveryMinor.value);

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const zones = settings.value?.zones ?? [];
        const { data } = await orders.place({
            op_id: opId,
            warehouse_id: form.warehouse_id,
            customer_name: form.customer_name,
            customer_phone: form.customer_phone,
            address: form.address,
            payment_method: form.payment_method,
            ...(zones.length ? { delivery_zone: form.delivery_zone } : { delivery_minor: form.delivery_minor ?? 0 }),
            note: form.note || null,
            lines: lines.value.map((line) => ({ item_id: line.item.id, quantity_milli: milli(line.quantity) })),
        });
        toast.success(t('online_orders.new.saved', { number: data.number }));
        router.push({ name: 'online-order', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const first = (key) => errors.value[key]?.[0] ?? null;
</script>

<template>
    <PageFrame :title="t('online_orders.new.title')" :description="t('online_orders.new.text')" width="lg">
        <template #before><AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="{ name: 'online-orders' }">{{ t('online_orders.order.back') }}</AppButton></template>
        <SkeletonRows v-if="options.loading.value && !settings" :rows="6" />
        <ErrorState v-else-if="options.error.value" :error="options.error.value" @retry="options.reload()" />
        <form v-else-if="settings" class="grid gap-4 lg:grid-cols-[1fr_20rem]" @submit.prevent="save">
            <div class="grid content-start gap-4">
                <section class="card grid gap-4 p-5 sm:grid-cols-2">
                    <h2 class="text-[15px] font-semibold sm:col-span-2">{{ t('online_orders.new.customer') }}</h2>
                    <AppField :label="t('online_orders.fields.customer')" :error="first('customer_name')" v-slot="{ id }">
                        <input :id="id" v-model="form.customer_name" class="field-input" autocomplete="name" required />
                    </AppField>
                    <AppField :label="t('online_orders.fields.phone')" :error="first('customer_phone')" v-slot="{ id }">
                        <input :id="id" v-model="form.customer_phone" class="field-input" type="tel" dir="ltr" inputmode="tel" autocomplete="tel-national" required />
                    </AppField>
                    <AppField class="sm:col-span-2" :label="t('online_orders.fields.address')" :error="first('address')" v-slot="{ id }">
                        <textarea :id="id" v-model="form.address" class="field-input min-h-20" rows="2" autocomplete="street-address" required />
                    </AppField>
                </section>

                <section class="card p-5">
                    <h2 class="mb-3 text-[15px] font-semibold">{{ t('online_orders.new.items') }}</h2>
                    <div class="relative mb-3">
                        <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                        <input v-model="search" class="field-input ps-9" :placeholder="t('online_orders.new.find_item')" :aria-label="t('online_orders.new.find_item')" @keydown.enter.prevent="found.reload()" />
                    </div>
                    <ul v-if="results.length" class="mb-4 max-h-56 divide-y divide-line overflow-y-auto rounded-lg border border-line">
                        <li v-for="item in results" :key="item.id" class="flex items-center gap-3 px-3 py-2">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[13.5px]">{{ item.name }}</span>
                                <span class="block text-[12px] text-muted"><span dir="ltr" class="font-mono">{{ item.sku }}</span> · {{ formatMoney({ amount: item.sale_price_minor, currency }) }}<template v-if="item.keeps_stock"> · {{ t('online_orders.new.available', { quantity: shownQuantity(item.available_milli) }) }}</template></span>
                            </span>
                            <AppButton size="sm" :icon="Plus" :disabled="item.keeps_stock && item.available_milli <= 0" @click="add(item)">{{ t('online_orders.new.add') }}</AppButton>
                        </li>
                    </ul>
                    <p v-if="!lines.length" class="rounded-lg bg-surface-2 p-4 text-center text-[13px] text-muted">{{ t('online_orders.new.no_items') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="(line, index) in lines" :key="line.item.id" class="flex flex-wrap items-center gap-3 py-2">
                            <span class="min-w-0 flex-1 text-[13.5px]">{{ line.item.name }}</span>
                            <input v-model="line.quantity" class="field-input w-20 text-end" dir="ltr" inputmode="decimal" :aria-label="t('online_orders.fields.quantity')" />
                            <span class="tabular w-28 text-end text-[13.5px]">{{ formatMoney({ amount: lineAmount(line), currency }) }}</span>
                            <AppButton variant="ghost" size="icon-sm" :icon="Trash2" :aria-label="t('online_orders.new.remove', { item: line.item.name })" @click="lines.splice(index, 1)" />
                        </li>
                    </ul>
                    <p v-if="first('lines')" class="mt-2 text-[12.5px] text-bad">{{ first('lines') }}</p>
                </section>
            </div>

            <aside class="grid content-start gap-4">
                <section class="card grid gap-4 p-5">
                    <AppField :label="t('online_orders.fields.warehouse')" :error="first('warehouse_id')" v-slot="{ id }">
                        <select :id="id" v-model="form.warehouse_id" class="field-input" required>
                            <option v-for="warehouse in settings.warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                        </select>
                    </AppField>
                    <AppField v-if="settings.zones.length" :label="t('online_orders.fields.zone')" :error="first('delivery_zone')" v-slot="{ id }">
                        <select :id="id" v-model="form.delivery_zone" class="field-input">
                            <option v-for="zone in settings.zones" :key="zone.code" :value="zone.code">{{ zoneName(zone) }} · {{ formatMoney({ amount: zone.charge_minor, currency }) }}</option>
                        </select>
                    </AppField>
                    <AppField v-else :label="t('online_orders.fields.delivery')" :hint="t('online_orders.new.no_zones')" :error="first('delivery_minor')" v-slot="{ id }">
                        <MoneyInput :id="id" v-model="form.delivery_minor" :currency="currency" />
                    </AppField>
                    <div class="grid gap-1">
                        <span class="text-[12.5px] text-muted">{{ t('online_orders.fields.payment') }}</span>
                        <AppSegmented v-model="form.payment_method" :options="paymentOptions" :label="t('online_orders.fields.payment')" block />
                    </div>
                    <AppField :label="t('online_orders.fields.note')" optional v-slot="{ id }">
                        <textarea :id="id" v-model="form.note" class="field-input" rows="2" maxlength="500" />
                    </AppField>
                </section>
                <section class="card grid gap-2 p-5 text-[13.5px]">
                    <div class="flex justify-between"><span class="text-muted">{{ t('online_orders.fields.goods') }}</span><span class="tabular">{{ formatMoney({ amount: goods, currency }) }}</span></div>
                    <div class="flex justify-between"><span class="text-muted">{{ t('online_orders.fields.delivery') }}</span><span class="tabular">{{ formatMoney({ amount: deliveryMinor, currency }) }}</span></div>
                    <div class="flex justify-between border-t border-line pt-2 text-[15px] font-semibold"><span>{{ t('online_orders.fields.total') }}</span><span class="tabular">{{ formatMoney({ amount: total, currency }) }}</span></div>
                    <p class="text-[12px] text-muted">{{ t('online_orders.new.estimate') }}</p>
                    <AppButton type="submit" variant="primary" class="mt-2" :icon="PackagePlus" :loading="saving" :disabled="!lines.length">{{ t('online_orders.new.save') }}</AppButton>
                </section>
            </aside>
        </form>
    </PageFrame>
</template>
