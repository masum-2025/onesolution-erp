<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Ban, Banknote, PackageCheck, PackageX, Printer, Undo2 } from 'lucide-vue-next';
import { AppButton, AppDialog, AppField, MoneyInput, newOpId, PageFrame, StatusBadge, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney, minorToDecimalString } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { NEXT_STEP, onlineOrdersApi, STATUS_TONES } from '../api';

/**
 * One order: its customer, items and totals, where it stands and its
 * history; the next step forward, taking money, calling it off or taking
 * it back. The server decides who may do what; a refusal says why.
 */
const orders = onlineOrdersApi(currentOrganization().id);
const route = useRoute();
const record = useResource(() => orders.order(route.params.id));
const order = computed(() => record.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: order.value?.currency });
const quantity = (milli) => minorToDecimalString(milli ?? 0, 3).replace(/\.?0+$/, '') || '0';
const busy = ref(false);

const nextStep = computed(() => NEXT_STEP[order.value?.status] ?? null);
const canCancel = computed(() => ['draft', 'confirmed', 'packed', 'shipped'].includes(order.value?.status));
const canReturn = computed(() => order.value?.status === 'delivered');
const canPay = computed(() => order.value && order.value.due_minor > 0 && !['cancelled', 'returned'].includes(order.value.status));

async function run(call, done) {
    busy.value = true;
    try {
        const { data } = await call();
        record.data.value = { data };
        toast.success(done);
        return true;
    } catch (error) {
        toast.error(error.message);
        if (error.status === 409) record.reload();
        return false;
    } finally {
        busy.value = false;
    }
}

// Sending out: who carries it (optional).
const shipping = reactive({ open: false, courier: '', tracking: '' });
function forward() {
    if (nextStep.value === 'ship') {
        shipping.open = true;
        return;
    }
    run(() => orders.step(order.value.id, nextStep.value, { base_version: order.value.version }), t(`online_orders.order.done.${nextStep.value}`));
}
async function ship() {
    if (await run(() => orders.step(order.value.id, 'ship', { base_version: order.value.version, courier: shipping.courier || null, tracking: shipping.tracking || null }), t('online_orders.order.done.ship'))) {
        shipping.open = false;
    }
}

// Money in: the whole amount owed by default.
const paying = reactive({ open: false, method: 'cash', amount: 0, reference: '', opId: '' });
function openPay() {
    Object.assign(paying, { open: true, method: 'cash', amount: order.value.due_minor, reference: '', opId: newOpId() });
}
async function pay() {
    if (await run(() => orders.pay(order.value.id, { op_id: paying.opId, method: paying.method, amount_minor: paying.amount, reference: paying.reference || null }), t('online_orders.order.done.pay'))) {
        paying.open = false;
    }
}

// Calling off or taking back: a reason, and how money kept goes back.
const closing = reactive({ open: false, step: 'cancel', reason: '', refund: 'cash' });
const refundAmount = computed(() => {
    if (!order.value) return 0;
    const held = order.value.paid_minor - order.value.refunded_minor;
    return closing.step === 'return' ? Math.max(0, held - order.value.delivery_minor) : held;
});
function openClose(step) {
    Object.assign(closing, { open: true, step, reason: '', refund: 'cash' });
}
async function close() {
    const body = { base_version: order.value.version, reason: closing.reason, ...(refundAmount.value > 0 ? { refund_method: closing.refund } : {}) };
    if (await run(() => orders.step(order.value.id, closing.step, body), t(`online_orders.order.done.${closing.step}`))) {
        closing.open = false;
    }
}
const METHODS = ['cash', 'mobile', 'bank'];
const printPage = () => window.print();
</script>

<template>
    <PageFrame :title="order ? order.number : ''" :description="order ? t(`online_orders.channel.${order.channel}`) : ''" width="lg" :resource="record" :not-found="{ icon: PackageX, title: t('online_orders.order.not_found') }">
        <template #before><AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="{ name: 'online-orders' }">{{ t('online_orders.order.back') }}</AppButton></template>
        <template #actions>
            <template v-if="order">
                <AppButton :icon="Printer" class="print:hidden" @click="printPage">{{ t('online_orders.order.print') }}</AppButton>
                <AppButton v-if="canPay" :icon="Banknote" :disabled="busy" @click="openPay">{{ t('online_orders.order.take_payment') }}</AppButton>
                <AppButton v-if="nextStep" variant="primary" :icon="PackageCheck" :loading="busy" @click="forward">{{ t(`online_orders.order.step.${nextStep}`) }}</AppButton>
            </template>
        </template>
        <template v-if="order">
            <div class="grid gap-4 lg:grid-cols-[1fr_18rem]">
                <div class="grid content-start gap-4">
                    <section class="card p-5">
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <StatusBadge :status="order.status" :tones="STATUS_TONES">{{ t(`online_orders.status.${order.status}`) }}</StatusBadge>
                            <span class="text-[12.5px] text-muted">{{ t(`online_orders.payment.${order.payment_method}`) }}</span>
                        </div>
                        <p class="text-[14px] font-medium">{{ order.customer_name }} · <span dir="ltr">{{ order.customer_phone }}</span></p>
                        <p class="mt-1 whitespace-pre-line text-[13.5px] text-muted">{{ order.address }}</p>
                        <p v-if="order.courier || order.tracking" class="mt-2 text-[13px]">{{ t('online_orders.order.courier', { courier: order.courier ?? '—', tracking: order.tracking ?? '—' }) }}</p>
                        <p v-if="order.note" class="mt-2 text-[13px]">{{ order.note }}</p>
                        <p v-if="order.cancel_reason" class="mt-2 text-[13px] text-bad">{{ order.cancel_reason }}</p>
                    </section>
                    <section class="card">
                        <table class="w-full text-[13.5px]">
                            <thead class="text-start text-[12px] text-muted">
                                <tr><th class="px-5 py-2 text-start font-medium">{{ t('online_orders.fields.item') }}</th><th class="px-2 py-2 text-end font-medium">{{ t('online_orders.fields.quantity') }}</th><th class="px-5 py-2 text-end font-medium">{{ t('online_orders.fields.amount') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="line in order.lines" :key="line.id">
                                    <td class="px-5 py-2">{{ line.name }}<span class="block font-mono text-[12px] text-muted" dir="ltr">{{ line.sku }}</span></td>
                                    <td class="tabular px-2 py-2 text-end">{{ quantity(line.quantity_milli) }}</td>
                                    <td class="tabular px-5 py-2 text-end">{{ money(line.total_minor) }}<span v-if="line.discount_minor" class="block text-[12px] text-muted">{{ t('online_orders.order.discount', { amount: money(line.discount_minor) }) }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                    <section class="card p-5">
                        <h2 class="mb-3 text-[15px] font-semibold">{{ t('online_orders.order.history') }}</h2>
                        <ol class="grid gap-2 text-[13px]">
                            <li v-for="(entry, index) in order.history" :key="index" class="flex flex-wrap gap-x-3">
                                <span class="text-muted">{{ formatDateTime(entry.at) }}</span>
                                <span>{{ t(`online_orders.status.${entry.to}`) }}</span>
                                <span v-if="entry.note" class="text-muted">— {{ entry.note }}</span>
                            </li>
                            <li v-for="payment in order.payments" :key="payment.id" class="flex flex-wrap gap-x-3">
                                <span class="text-muted">{{ formatDateTime(payment.created_at) }}</span>
                                <span>{{ t(`online_orders.order.money.${payment.kind}`, { amount: money(payment.amount_minor), method: t(`online_orders.method.${payment.method}`) }) }}</span>
                                <span v-if="payment.reference" class="font-mono text-muted" dir="ltr">{{ payment.reference }}</span>
                            </li>
                        </ol>
                    </section>
                </div>
                <aside class="grid content-start gap-4">
                    <section class="card grid gap-2 p-5 text-[13.5px]">
                        <div class="flex justify-between"><span class="text-muted">{{ t('online_orders.fields.goods') }}</span><span class="tabular">{{ money(order.total_minor - order.delivery_minor) }}</span></div>
                        <div v-if="order.tax_minor" class="flex justify-between text-[12.5px] text-muted"><span>{{ t(order.prices_include_tax ? 'online_orders.order.vat_inside' : 'online_orders.order.vat_added') }}</span><span class="tabular">{{ money(order.tax_minor) }}</span></div>
                        <div class="flex justify-between"><span class="text-muted">{{ t('online_orders.fields.delivery') }}</span><span class="tabular">{{ money(order.delivery_minor) }}</span></div>
                        <div class="flex justify-between border-t border-line pt-2 text-[15px] font-semibold"><span>{{ t('online_orders.fields.total') }}</span><span class="tabular">{{ money(order.total_minor) }}</span></div>
                        <div class="flex justify-between"><span class="text-muted">{{ t('online_orders.fields.paid') }}</span><span class="tabular">{{ money(order.paid_minor - order.refunded_minor) }}</span></div>
                        <div class="flex justify-between font-semibold" :class="order.due_minor > 0 ? 'text-warn' : ''"><span>{{ t('online_orders.fields.due') }}</span><span class="tabular">{{ money(order.due_minor) }}</span></div>
                    </section>
                    <section v-if="canCancel || canReturn" class="card grid gap-2 p-5 print:hidden">
                        <AppButton v-if="canReturn" variant="danger-soft" :icon="Undo2" :disabled="busy" @click="openClose('return')">{{ t('online_orders.order.step.return') }}</AppButton>
                        <AppButton v-if="canCancel" variant="danger-soft" :icon="Ban" :disabled="busy" @click="openClose('cancel')">{{ t('online_orders.order.step.cancel') }}</AppButton>
                        <p class="text-[12px] text-muted">{{ t(order.status === 'shipped' || canReturn ? 'online_orders.order.supervisor_note' : 'online_orders.order.cancel_note') }}</p>
                    </section>
                </aside>
            </div>

            <AppDialog :open="shipping.open" :title="t('online_orders.order.step.ship')" :description="t('online_orders.order.ship_text')" @close="shipping.open = false">
                <form class="grid gap-4" @submit.prevent="ship">
                    <AppField :label="t('online_orders.fields.courier')" optional v-slot="{ id }"><input :id="id" v-model="shipping.courier" class="field-input" maxlength="80" /></AppField>
                    <AppField :label="t('online_orders.fields.tracking')" optional v-slot="{ id }"><input :id="id" v-model="shipping.tracking" class="field-input" dir="ltr" maxlength="80" /></AppField>
                    <div class="flex justify-end gap-2"><AppButton @click="shipping.open = false">{{ t('online_orders.order.cancel') }}</AppButton><AppButton type="submit" variant="primary" :loading="busy">{{ t('online_orders.order.step.ship') }}</AppButton></div>
                </form>
            </AppDialog>

            <AppDialog :open="paying.open" :title="t('online_orders.order.take_payment')" :description="t('online_orders.order.pay_text', { amount: money(order.due_minor) })" @close="paying.open = false">
                <form class="grid gap-4" @submit.prevent="pay">
                    <AppField :label="t('online_orders.fields.method')" v-slot="{ id }">
                        <select :id="id" v-model="paying.method" class="field-input"><option v-for="method in METHODS" :key="method" :value="method">{{ t(`online_orders.method.${method}`) }}</option></select>
                    </AppField>
                    <AppField :label="t('online_orders.fields.amount')" v-slot="{ id }"><MoneyInput :id="id" v-model="paying.amount" :currency="order.currency" /></AppField>
                    <AppField :label="t('online_orders.fields.reference')" optional v-slot="{ id }"><input :id="id" v-model="paying.reference" class="field-input" dir="ltr" maxlength="80" /></AppField>
                    <div class="flex justify-end gap-2"><AppButton @click="paying.open = false">{{ t('online_orders.order.cancel') }}</AppButton><AppButton type="submit" variant="primary" :loading="busy" :disabled="!paying.amount">{{ t('online_orders.order.take_payment') }}</AppButton></div>
                </form>
            </AppDialog>

            <AppDialog :open="closing.open" :title="t(`online_orders.order.step.${closing.step}`)" :description="t(`online_orders.order.${closing.step}_text`)" tone="bad" confirm @close="closing.open = false">
                <form class="grid gap-4" @submit.prevent="close">
                    <AppField :label="t('online_orders.fields.reason')" v-slot="{ id }"><textarea :id="id" v-model="closing.reason" class="field-input" rows="2" minlength="3" maxlength="300" required /></AppField>
                    <AppField v-if="refundAmount > 0" :label="t('online_orders.order.refund', { amount: money(refundAmount) })" v-slot="{ id }">
                        <select :id="id" v-model="closing.refund" class="field-input"><option v-for="method in METHODS" :key="method" :value="method">{{ t(`online_orders.method.${method}`) }}</option></select>
                    </AppField>
                    <div class="flex justify-end gap-2"><AppButton @click="closing.open = false">{{ t('online_orders.order.keep') }}</AppButton><AppButton type="submit" variant="danger" :loading="busy">{{ t(`online_orders.order.step.${closing.step}`) }}</AppButton></div>
                </form>
            </AppDialog>
        </template>
    </PageFrame>
</template>
