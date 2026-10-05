<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Printer, Undo2 } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { brand } from '@/lib/brand';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { readPref } from '@/lib/storage';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { posApi } from '../api';
import { formatQuantity, quantityToMilli } from '../lib';

/**
 * A sale's or return's receipt, printable on a narrow (80 mm) receipt
 * printer or A4, on the company's branding. Supervisors take goods back
 * from here: which lines, how many, why; paid back in cash at this counter.
 */
const pos = posApi(currentOrganization().id);
const route = useRoute();
const record = useResource(() => pos.sale(route.params.id));
const sale = computed(() => record.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: sale.value?.currency });
const printPage = () => window.print();
// Straight from the till: print once it is here.
const stop = watch(sale, (value) => {
    if (value && route.query.print === '1') {
        setTimeout(printPage, 300);
        stop();
    }
});

// Taking goods back.
const returning = ref(false);
const back = reactive({});
const reason = ref('');
const saving = ref(false);
function startReturn() {
    for (const line of sale.value.lines) back[line.id] = '';
    reason.value = '';
    returning.value = true;
}
const backTotal = computed(() => (sale.value?.lines ?? []).reduce((total, line) => {
    const quantity = quantityToMilli(back[line.id] ?? '', 3);
    return total + (quantity ? Math.floor((line.total_minor * quantity + Math.floor(line.quantity_milli / 2)) / line.quantity_milli) : 0);
}, 0));
async function giveBack() {
    const lines = [];
    for (const line of sale.value.lines) {
        const text = String(back[line.id] ?? '').trim();
        if (!text) continue;
        const quantity = quantityToMilli(text, 3);
        if (!quantity || quantity > line.quantity_milli - line.returned_milli) {
            toast.error(t('pos.receipt.too_many', { item: line.name }));
            return;
        }
        lines.push({ line_id: line.id, quantity_milli: quantity });
    }
    if (!lines.length) return;
    saving.value = true;
    try {
        const { data } = await pos.giveBack(sale.value.id, {
            op_id: globalThis.crypto?.randomUUID?.() ?? `${Date.now()}`, register_id: readPref('pos.register') ?? sale.value.register_id, reason: reason.value.trim(), lines,
        });
        toast.success(t('pos.receipt.returned', { amount: money(data.total_minor), number: data.number }));
        returning.value = false;
        record.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-md">
        <div class="mb-4 flex items-center justify-between gap-2 print:hidden">
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="{ name: 'pos-sales' }">{{ t('pos.sales.title') }}</AppButton>
            <div class="flex gap-2">
                <AppButton v-if="sale && sale.kind === 'sale' && can('pos.supervise')" size="sm" variant="secondary" :icon="Undo2" @click="startReturn">{{ t('pos.receipt.give_back') }}</AppButton>
                <AppButton v-if="sale" size="sm" :icon="Printer" @click="printPage">{{ t('pos.receipt.print') }}</AppButton>
            </div>
        </div>
        <SkeletonRows v-if="record.loading.value && !sale" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <article v-else-if="sale" class="card receipt p-5 font-mono text-[12.5px] print:border-0 print:p-0 print:shadow-none">
            <header class="border-b border-dashed border-line pb-3 text-center">
                <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="mx-auto mb-1 h-10 w-auto" />
                <p class="font-sans text-[15px] font-semibold">{{ sale.company ?? brand.name }}</p>
                <p class="mt-1 font-sans text-[13px] font-semibold uppercase tracking-wide">{{ t(sale.kind === 'return' ? 'pos.receipt.return_title' : 'pos.receipt.title') }}</p>
                <p dir="ltr">{{ sale.number }}</p>
                <p>{{ formatDateTime(sale.sold_at) }}</p>
                <p v-if="sale.customer_name" class="font-sans">{{ sale.customer_name }}</p>
                <div class="mt-1 flex justify-center gap-1 print:hidden">
                    <AppBadge v-if="sale.offline" tone="neutral">{{ t('pos.receipt.offline') }}</AppBadge>
                    <AppBadge v-if="sale.review_reason" tone="warn">{{ t(`pos.review.${sale.review_reason}`) }}</AppBadge>
                </div>
            </header>
            <ul class="divide-y divide-dashed divide-line py-2">
                <li v-for="line in sale.lines" :key="line.id" class="py-1.5">
                    <p class="font-sans text-[13px]">{{ line.name }}</p>
                    <p class="flex justify-between gap-2">
                        <span>{{ formatQuantity(line.quantity_milli) }} × {{ money(line.unit_price_minor) }}<template v-if="line.discount_minor"> − {{ money(line.discount_minor) }}</template></span>
                        <span class="tabular">{{ money(line.total_minor) }}</span>
                    </p>
                    <p v-if="line.returned_milli" class="font-sans text-[11.5px] text-muted">{{ t('pos.receipt.came_back', { quantity: formatQuantity(line.returned_milli) }) }}</p>
                </li>
            </ul>
            <dl class="grid grid-cols-[1fr_auto] gap-y-0.5 border-t border-dashed border-line pt-2">
                <template v-if="sale.discount_minor"><dt>{{ t('pos.till.discounts') }}</dt><dd class="text-end">−{{ money(sale.discount_minor) }}</dd></template>
                <dt>{{ t(sale.prices_include_tax ? 'pos.till.vat_included' : 'pos.till.vat') }}</dt><dd class="text-end">{{ money(sale.tax_minor) }}</dd>
                <dt class="font-sans text-[15px] font-semibold">{{ t('pos.receipt.total') }}</dt><dd class="text-end text-[15px] font-semibold">{{ money(sale.total_minor) }}</dd>
                <template v-for="payment in sale.payments" :key="payment.method"><dt>{{ t(`pos.methods.${payment.method}`) }}</dt><dd class="text-end">{{ money(payment.amount_minor) }}</dd></template>
                <template v-if="sale.change_minor"><dt>{{ t('pos.till.change') }}</dt><dd class="text-end">{{ money(sale.change_minor) }}</dd></template>
            </dl>
            <p v-if="sale.reason" class="mt-2 font-sans text-[12px]">{{ t('pos.receipt.reason', { reason: sale.reason }) }}</p>
            <p v-if="sale.returns?.length" class="mt-2 font-sans text-[12px] text-muted print:hidden">{{ t('pos.receipt.returns', { numbers: sale.returns.join(', ') }) }}</p>
            <p class="mt-3 border-t border-dashed border-line pt-2 text-center font-sans text-[11.5px] text-muted">{{ t('pos.receipt.thanks') }}</p>
        </article>

        <AppDialog :open="returning" :title="t('pos.receipt.give_back')" :description="t('pos.receipt.give_back_text')" :icon="Undo2" @close="returning = false">
            <form id="pos-return" class="grid gap-3" novalidate @submit.prevent="giveBack">
                <div v-for="line in sale?.lines ?? []" :key="line.id" class="flex items-center gap-3 text-[13.5px]">
                    <span class="min-w-0 flex-1">{{ line.name }} <span class="block text-[12px] text-muted">{{ t('pos.receipt.can_return', { quantity: formatQuantity(line.quantity_milli - line.returned_milli) }) }}</span></span>
                    <input v-model="back[line.id]" inputmode="decimal" class="field-input w-24 tabular text-end" dir="ltr" placeholder="0" :disabled="line.returned_milli >= line.quantity_milli" :aria-label="line.name" />
                </div>
                <AppField v-slot="{ id }" :label="t('pos.receipt.why')">
                    <input :id="id" v-model="reason" class="field-input" maxlength="300" :placeholder="t('pos.receipt.why_hint')" />
                </AppField>
                <p class="rounded-xl bg-subtle px-4 py-3 text-[13px]">{{ t('pos.receipt.pay_back', { amount: money(backTotal) }) }}</p>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="returning = false">{{ t('pos.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="pos-return" :loading="saving" :disabled="!backTotal || reason.trim().length < 3">{{ t('pos.receipt.give_back') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>

<style scoped>
@media print {
    @page {
        size: 80mm auto;
        margin: 4mm;
    }
    .receipt {
        width: 72mm;
        margin: 0 auto;
    }
}
</style>
