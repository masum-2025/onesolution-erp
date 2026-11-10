<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Ban, BadgePercent, CheckCheck, HandCoins, Plus, Printer, ReceiptText, Trash2, Undo2 } from 'lucide-vue-next';
import { AppButton, AppField, AppSegmented, MoneyInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { billingApi, money } from '../api';

/**
 * One bill: services (a wrong one taken off while open), the panel, a
 * discount (big ones wait for approval), totals, money in and out, and the
 * steps the person may take: take payment, finalize, void, refund, print.
 */
const pb = billingApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useResource(() => pb.setup());
const info = computed(() => setup.data.value?.data ?? {});
const can = computed(() => info.value.can ?? {});
const record = useResource(() => pb.bill(route.params.id));
const bill = computed(() => record.data.value?.data ?? null);
const m = (amount) => formatMoney(money(amount, bill.value?.currency));
const open = computed(() => bill.value?.status === 'open');
const pendingDiscount = computed(() => (bill.value?.discount_requests ?? []).find((request) => request.status === 'pending'));
const tones = { open: 'warn', final: 'ok', void: 'neutral' };
const busy = ref(false);

async function run(call, success) {
    busy.value = true;
    try {
        const answer = await call();
        if (answer?.data?.id === bill.value?.id && answer.data.lines) record.data.value = { data: answer.data };
        else record.reload();
        if (success) toast.success(success);
        return answer;
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') record.reload();
        return null;
    } finally {
        busy.value = false;
    }
}

// Services
const adding = reactive({ service_id: '', quantity: 1 });
const services = computed(() => info.value.options?.service_id ?? []);
async function addService() {
    const done = await run(() => pb.addLine(bill.value.id, { service_id: adding.service_id, quantity: Number(adding.quantity) || 1 }));
    if (done) Object.assign(adding, { service_id: '', quantity: 1 });
}
const removeLine = (line) => run(() => pb.voidLine(bill.value.id, line.id), t('patient_billing.bill.line_removed'));
const setPanel = (event) => run(() => pb.setPanel(bill.value.id, { panel_id: event.target.value || null, base_version: bill.value.version }));

// Discount
const discounting = ref(false);
const discount = reactive({ amount: null, reason: '' });
async function giveDiscount() {
    const done = await run(() => pb.discount(bill.value.id, { amount_minor: discount.amount ?? 0, reason: discount.reason.trim() }));
    if (done) {
        toast.success(done.waiting_approval ? t('patient_billing.bill.discount_waiting') : t('patient_billing.bill.discount_given'));
        discounting.value = false;
        Object.assign(discount, { amount: null, reason: '' });
    }
}

// Payment
const paying = ref(false);
const payment = reactive({ amount: null, method: 'cash', reference: '', op: '' });
const methods = computed(() => (info.value.methods ?? []).filter((value) => value !== 'advance' || (bill.value?.advance_balance_minor ?? 0) > 0)
    .map((value) => ({ value, label: t(`patient_billing.method.${value}`) })));
function startPayment() {
    Object.assign(payment, { amount: bill.value.due_minor, method: 'cash', reference: '', op: crypto.randomUUID() });
    paying.value = true;
}
async function takePayment() {
    const done = await run(() => pb.pay({ op_id: payment.op, kind: 'payment', bill_id: bill.value.id, method: payment.method, amount_minor: payment.amount, reference: payment.reference.trim() || null }));
    if (done) {
        toast.success(t('patient_billing.pay.taken', { number: done.data.number }));
        paying.value = false;
    }
}

// Finalize, void, refund
const finalizing = ref(false);
async function finalize() {
    if (await run(() => pb.finalize(bill.value.id, { base_version: bill.value.version }), t('patient_billing.bill.finalized'))) finalizing.value = false;
}
const voiding = ref(false);
const voidReason = ref('');
async function voidBill() {
    if (await run(() => pb.voidBill(bill.value.id, { reason: voidReason.value.trim(), base_version: bill.value.version }), t('patient_billing.bill.voided'))) voiding.value = false;
}
const refunding = ref(false);
const refund = reactive({ amount: null, method: 'cash', reason: '', reference: '', op: '' });
const refundMethods = computed(() => (info.value.methods ?? []).map((value) => ({ value, label: t(`patient_billing.method.${value}`) })));
function startRefund() {
    Object.assign(refund, { amount: null, method: 'cash', reason: '', reference: '', op: crypto.randomUUID() });
    refunding.value = true;
}
async function giveRefund() {
    const done = await run(() => pb.refund(bill.value.id, { op_id: refund.op, method: refund.method, amount_minor: refund.amount, reason: refund.reason.trim(), reference: refund.reference.trim() || null }));
    if (done) {
        toast.success(t('patient_billing.refund.given', { number: done.data.number }));
        refunding.value = false;
    }
}
</script>

<template>
    <PageFrame :title="bill ? `${t('patient_billing.bill.title')} ${bill.number}` : ''" :description="bill?.patient ? `${bill.patient.name} · ${bill.patient.mrn}` : ''" width="xl"
               :resource="record" :not-found="{ icon: ReceiptText, title: t('patient_billing.bill.not_found') }" guide="patient_billing.guide.bills">
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'patient-billing-bills' })">{{ t('patient_billing.bill.back') }}</AppButton>
        </template>
        <template v-if="bill" #actions>
            <AppBadge :tone="tones[bill.status]" dot>{{ t(`patient_billing.status.${bill.status}`) }}</AppBadge>
            <AppButton :icon="Printer" @click="router.push({ name: 'patient-billing-receipt', params: { id: bill.id } })">{{ t('patient_billing.bill.print') }}</AppButton>
            <AppButton v-if="can.collect && bill.status !== 'void' && bill.due_minor > 0" variant="primary" :icon="HandCoins" @click="startPayment">{{ t('patient_billing.pay.take') }}</AppButton>
            <AppButton v-if="can.collect && open" :icon="CheckCheck" :disabled="!bill.lines.length || !!pendingDiscount" @click="finalizing = true">{{ t('patient_billing.bill.finalize') }}</AppButton>
            <AppButton v-if="can.approve && bill.kept_minor > 0" variant="ghost" :icon="Undo2" @click="startRefund">{{ t('patient_billing.refund.title') }}</AppButton>
            <AppButton v-if="can.collect && open && !bill.paid_minor" variant="ghost" :icon="Ban" @click="voiding = true">{{ t('patient_billing.bill.void') }}</AppButton>
        </template>

        <template v-if="bill">
            <p v-if="bill.void_reason" class="mb-4 rounded-lg bg-subtle px-4 py-2 text-[13px]">{{ t('patient_billing.bill.void_reason', { reason: bill.void_reason }) }}</p>
            <p v-if="pendingDiscount" class="mb-4 rounded-lg bg-warn/10 px-4 py-2 text-[13px] text-warn" role="status">{{ t('patient_billing.bill.discount_pending', { amount: m(pendingDiscount.amount_minor), name: pendingDiscount.requested_by_name ?? '—' }) }}</p>
            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)]">
                <section class="card">
                    <div class="overflow-x-auto">
                        <table class="w-full text-[13.5px]">
                            <thead class="text-[12px] text-muted">
                                <tr class="border-b border-line">
                                    <th class="px-4 py-2 text-start font-medium">{{ t('patient_billing.bill.service') }}</th>
                                    <th class="px-2 py-2 text-end font-medium">{{ t('patient_billing.bill.quantity') }}</th>
                                    <th class="px-2 py-2 text-end font-medium">{{ t('patient_billing.bill.price') }}</th>
                                    <th class="px-2 py-2 text-end font-medium">{{ t('patient_billing.bill.discount') }}</th>
                                    <th class="px-4 py-2 text-end font-medium">{{ t('patient_billing.bill.amount') }}</th>
                                    <th v-if="open && can.collect" class="w-10 print:hidden" />
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="line in bill.lines" :key="line.id">
                                    <td class="px-4 py-2">{{ line.description }}<span v-if="line.source_module" class="block text-[11.5px] text-muted">{{ t(`patient_billing.sources.${line.source_module}`) }}</span></td>
                                    <td class="px-2 py-2 text-end tabular">{{ line.quantity_milli / 1000 }}</td>
                                    <td class="px-2 py-2 text-end tabular">{{ m(line.unit_price_minor) }}</td>
                                    <td class="px-2 py-2 text-end tabular text-muted">{{ line.discount_minor ? m(line.discount_minor) : '—' }}</td>
                                    <td class="px-4 py-2 text-end tabular font-medium">{{ m(line.total_minor) }}</td>
                                    <td v-if="open && can.collect" class="px-1 print:hidden">
                                        <button type="button" class="grid size-8 place-items-center rounded-lg text-muted hover:bg-subtle hover:text-bad" :aria-label="t('patient_billing.bill.remove_line')" @click="removeLine(line)"><Trash2 class="size-4" aria-hidden="true" /></button>
                                    </td>
                                </tr>
                                <tr v-if="!bill.lines.length"><td colspan="6" class="px-4 py-6 text-center text-[13px] text-muted">{{ t('patient_billing.bill.no_lines') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <form v-if="open && can.collect" class="grid grid-cols-1 gap-2 border-t border-line p-4 sm:grid-cols-[minmax(0,1fr)_6rem_auto] print:hidden" @submit.prevent="adding.service_id && addService()">
                        <select v-model="adding.service_id" class="field-input" :aria-label="t('patient_billing.bill.service')">
                            <option value="" disabled>{{ t('patient_billing.bill.pick_service') }}</option>
                            <option v-for="service in services" :key="service.id" :value="service.id">{{ service.code }} · {{ service.name }} — {{ m(service.price_minor) }}</option>
                        </select>
                        <input v-model.number="adding.quantity" type="number" min="1" max="10000" class="field-input tabular" :aria-label="t('patient_billing.bill.quantity')" />
                        <AppButton type="submit" :icon="Plus" :disabled="!adding.service_id" :loading="busy">{{ t('patient_billing.bill.add') }}</AppButton>
                    </form>
                </section>

                <aside class="grid content-start gap-4">
                    <section class="card p-4 text-[13.5px]">
                        <AppField v-if="open && can.collect && (info.options?.panel_id ?? []).length" v-slot="{ id }" :label="t('patient_billing.bill.panel')" class="mb-3 print:hidden">
                            <select :id="id" :value="bill.panel_id ?? ''" class="field-input" :disabled="busy" @change="setPanel">
                                <option value="">{{ t('patient_billing.bill.no_panel') }}</option>
                                <option v-for="panel in info.options.panel_id" :key="panel.id" :value="panel.id">{{ panel.name }}</option>
                            </select>
                        </AppField>
                        <p v-else-if="bill.panel_name" class="mb-3">{{ t('patient_billing.bill.panel') }}: <strong>{{ bill.panel_name }}</strong></p>
                        <dl class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1.5 tabular">
                            <dt class="text-muted">{{ t('patient_billing.bill.subtotal') }}</dt><dd class="text-end">{{ m(bill.subtotal_minor) }}</dd>
                            <template v-if="bill.panel_discount_minor"><dt class="text-muted">{{ t('patient_billing.bill.panel_discount') }}</dt><dd class="text-end">− {{ m(bill.panel_discount_minor) }}</dd></template>
                            <template v-if="bill.discount_minor"><dt class="text-muted">{{ t('patient_billing.bill.discount') }}<span v-if="bill.discount_reason" class="block text-[11.5px]">{{ bill.discount_reason }}</span></dt><dd class="text-end">− {{ m(bill.discount_minor) }}</dd></template>
                            <template v-if="bill.tax_minor"><dt class="text-muted">{{ bill.prices_include_tax ? t('patient_billing.bill.vat_inside') : t('patient_billing.bill.vat') }}</dt><dd class="text-end">{{ m(bill.tax_minor) }}</dd></template>
                            <dt class="border-t border-line pt-1.5 font-semibold">{{ t('patient_billing.bill.total') }}</dt><dd class="border-t border-line pt-1.5 text-end font-semibold">{{ m(bill.total_minor) }}</dd>
                            <dt class="text-muted">{{ t('patient_billing.bill.paid') }}</dt><dd class="text-end">{{ m(bill.paid_minor) }}</dd>
                            <template v-if="bill.refunded_minor"><dt class="text-muted">{{ t('patient_billing.bill.refunded') }}</dt><dd class="text-end">{{ m(bill.refunded_minor) }}</dd></template>
                            <dt class="font-semibold" :class="bill.due_minor ? 'text-bad' : 'text-ok'">{{ bill.panel_due_minor ? t('patient_billing.bill.due_panel') : t('patient_billing.bill.due') }}</dt>
                            <dd class="text-end font-semibold" :class="bill.due_minor ? 'text-bad' : 'text-ok'">{{ m(bill.due_minor) }}</dd>
                        </dl>
                        <p v-if="bill.advance_balance_minor" class="mt-3 text-[12.5px] text-muted">{{ t('patient_billing.bill.advance_left', { amount: m(bill.advance_balance_minor) }) }}</p>
                        <AppButton v-if="open && can.collect && !pendingDiscount && bill.lines.length" variant="ghost" size="sm" :icon="BadgePercent" class="mt-3 print:hidden" @click="discounting = true">{{ t('patient_billing.bill.give_discount') }}</AppButton>
                    </section>
                    <section class="card">
                        <h2 class="border-b border-line px-4 py-2.5 text-[13.5px] font-semibold">{{ t('patient_billing.bill.money') }}</h2>
                        <p v-if="!bill.payments.length" class="px-4 py-3 text-[13px] text-muted">{{ t('patient_billing.bill.no_money') }}</p>
                        <ul v-else class="divide-y divide-line text-[13px]">
                            <li v-for="item in bill.payments" :key="item.id" class="flex justify-between gap-3 px-4 py-2">
                                <span><span class="font-mono" dir="ltr">{{ item.number }}</span> · {{ t(`patient_billing.method.${item.method}`) }}<span class="block text-[11.5px] text-muted">{{ formatDateTime(item.received_at) }}<template v-if="item.received_by_name"> · {{ item.received_by_name }}</template><template v-if="item.reason"> · {{ item.reason }}</template></span></span>
                                <span class="tabular font-medium" :class="item.kind === 'refund' && 'text-bad'">{{ item.kind === 'refund' ? '− ' : '' }}{{ m(item.amount_minor) }}</span>
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>
        </template>

        <AppDialog :open="paying" :title="t('patient_billing.pay.take')" :description="bill ? t('patient_billing.pay.text', { due: m(bill.due_minor) }) : ''" :icon="HandCoins" @close="paying = false">
            <div class="grid gap-3">
                <AppField v-slot="{ id }" :label="t('patient_billing.pay.amount')" :hint="t('patient_billing.pay.amount_hint')"><MoneyInput :id="id" v-model="payment.amount" :currency="bill?.currency" /></AppField>
                <AppSegmented v-model="payment.method" :options="methods" :label="t('patient_billing.pay.method')" />
                <AppField v-if="['card', 'mobile'].includes(payment.method)" v-slot="{ id }" :label="t('patient_billing.pay.reference')" :hint="t('patient_billing.pay.reference_hint')">
                    <input :id="id" v-model="payment.reference" class="field-input" dir="ltr" maxlength="100" />
                </AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="paying = false">{{ t('patient_billing.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!payment.amount" @click="takePayment">{{ t('patient_billing.pay.take') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="discounting" :title="t('patient_billing.bill.give_discount')" :description="t('patient_billing.bill.discount_text', { percent: info.rules?.discount_approval_above_percent ?? 0 })" :icon="BadgePercent" @close="discounting = false">
            <div class="grid gap-3">
                <AppField v-slot="{ id }" :label="t('patient_billing.bill.discount_amount')"><MoneyInput :id="id" v-model="discount.amount" :currency="bill?.currency" /></AppField>
                <AppField v-slot="{ id }" :label="t('patient_billing.bill.discount_reason')" :hint="t('patient_billing.bill.discount_reason_hint')">
                    <input :id="id" v-model="discount.reason" class="field-input" maxlength="300" />
                </AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="discounting = false">{{ t('patient_billing.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="discount.amount === null || discount.reason.trim().length < 3" @click="giveDiscount">{{ t('patient_billing.bill.give_discount') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="finalizing" confirm :title="t('patient_billing.bill.finalize')" :description="bill ? t('patient_billing.bill.finalize_text', { total: m(bill.total_minor), due: m(bill.due_minor) }) : ''" :icon="CheckCheck" @close="finalizing = false">
            <template #footer>
                <AppButton variant="ghost" @click="finalizing = false">{{ t('patient_billing.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" @click="finalize">{{ t('patient_billing.bill.finalize') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="voiding" confirm tone="bad" :title="t('patient_billing.bill.void')" :description="t('patient_billing.bill.void_text')" :icon="Ban" @close="voiding = false">
            <AppField v-slot="{ id }" :label="t('patient_billing.bill.void_reason_label')">
                <input :id="id" v-model="voidReason" class="field-input" maxlength="300" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="voiding = false">{{ t('patient_billing.common.cancel') }}</AppButton>
                <AppButton variant="danger" :loading="busy" :disabled="voidReason.trim().length < 3" @click="voidBill">{{ t('patient_billing.bill.void') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="refunding" :title="t('patient_billing.refund.title')" :description="bill ? t('patient_billing.refund.text', { max: m(bill.kept_minor) }) : ''" :icon="Undo2" @close="refunding = false">
            <div class="grid gap-3">
                <AppField v-slot="{ id }" :label="t('patient_billing.pay.amount')"><MoneyInput :id="id" v-model="refund.amount" :currency="bill?.currency" /></AppField>
                <AppSegmented v-model="refund.method" :options="refundMethods" :label="t('patient_billing.refund.how')" />
                <AppField v-slot="{ id }" :label="t('patient_billing.refund.reason')" :hint="t('patient_billing.refund.reason_hint')">
                    <input :id="id" v-model="refund.reason" class="field-input" maxlength="300" />
                </AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="refunding = false">{{ t('patient_billing.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!refund.amount || refund.reason.trim().length < 3" @click="giveRefund">{{ t('patient_billing.refund.give') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
