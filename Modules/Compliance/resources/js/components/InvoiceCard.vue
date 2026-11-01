<script setup>
import { ref } from 'vue';
import { BanknoteArrowUp, BookPlus, TriangleAlert } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AdviceDialog from './AdviceDialog.vue';
import { adviceTone } from '../lib';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDecimal, formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * An invoice a connected provider sent, as it last came (paid or voided
 * there follows here), and taking it into the books once as a draft bill
 * when the company keeps books here.
 */
const props = defineProps({
    invoice: { type: Object, required: true },
    calls: { type: Object, required: true },
    showFactory: Boolean,
});
const emit = defineEmits(['billed']);

const busy = ref(false);
const adviceOpen = ref(false);
const money = (amount) => formatMoney({ amount, currency: props.invoice.currency_code });
const quantity = (milli) => formatDecimal((milli / 1000).toString());
const tone = { posted: 'warn', partly_paid: 'warn', paid: 'ok', void: 'outline' };

async function bill() {
    const answer = await confirmAction({
        title: t('compliance.invoices.bill_title', { number: props.invoice.number ?? '' }),
        message: t('compliance.invoices.bill_text', { from: props.invoice.from, total: money(props.invoice.total_minor) }),
        confirmLabel: t('compliance.invoices.bill'),
        reason: 'none',
    });
    if (!answer) return;
    busy.value = true;
    try {
        const { data } = await props.calls.billInvoice(props.invoice.id, props.invoice.version);
        toast.success(t('compliance.invoices.bill_done'));
        emit('billed', data);
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <article class="rounded-xl border border-line">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-2.5">
            <p class="min-w-0 text-[13.5px]">
                <span class="font-semibold">{{ t('compliance.invoices.title', { number: invoice.number ?? '' }) }}</span>
                <span v-if="showFactory" class="text-muted"> · {{ invoice.factory.name }}</span>
                <span class="block text-[12.5px] text-muted">
                    {{ invoice.from }}<template v-if="invoice.issue_date"> · {{ formatDate(invoice.issue_date) }}</template><template v-if="invoice.due_date"> · {{ t('compliance.invoices.due', { date: formatDate(invoice.due_date) }) }}</template>
                </span>
            </p>
            <AppBadge :tone="tone[invoice.status] ?? 'neutral'">{{ t(`compliance.invoices.status_${invoice.status}`) }}</AppBadge>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[28rem] text-[13px]">
                <thead>
                    <tr class="text-[12px] text-muted">
                        <th scope="col" class="px-4 py-1.5 text-start font-medium">{{ t('compliance.quotes.item') }}</th>
                        <th scope="col" class="px-2 py-1.5 text-end font-medium">{{ t('compliance.quotes.quantity') }}</th>
                        <th scope="col" class="px-2 py-1.5 text-end font-medium">{{ t('compliance.quotes.price') }}</th>
                        <th scope="col" class="px-4 py-1.5 text-end font-medium">{{ t('compliance.quotes.amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="(line, index) in invoice.lines" :key="index">
                        <td class="px-4 py-1.5">{{ line.description }}</td>
                        <td class="tabular px-2 py-1.5 text-end">{{ quantity(line.quantity_milli) }}</td>
                        <td class="tabular px-2 py-1.5 text-end">{{ money(line.unit_price_minor) }}</td>
                        <td class="tabular px-4 py-1.5 text-end">{{ money(line.total_minor) }}</td>
                    </tr>
                </tbody>
                <tfoot class="text-[13px]">
                    <tr v-if="invoice.tax_minor"><td colspan="3" class="px-4 pt-2 text-end text-muted">{{ t('compliance.quotes.vat') }}</td><td class="tabular px-4 pt-2 text-end">{{ money(invoice.tax_minor) }}</td></tr>
                    <tr><td colspan="3" class="px-4 pt-2 text-end font-semibold">{{ t('compliance.quotes.total') }}</td><td class="tabular px-4 pt-2 text-end font-semibold">{{ money(invoice.total_minor) }}</td></tr>
                    <tr><td colspan="3" class="px-4 py-2 text-end text-muted">{{ t('compliance.invoices.balance') }}</td>
                        <td class="tabular px-4 py-2 text-end" :class="invoice.balance_minor > 0 ? 'text-warn' : ''">{{ money(invoice.balance_minor) }}</td></tr>
                </tfoot>
            </table>
        </div>
        <!-- Taken into the books: the bill; voided after that: someone must look at the bill. -->
        <p v-if="invoice.bill_id && invoice.status === 'void'" class="flex items-start gap-1.5 px-4 pb-2 text-[12.5px] text-bad">
            <TriangleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />{{ t('compliance.invoices.void_after_bill') }}
        </p>
        <p v-if="invoice.bill_id" class="px-4 pb-3 text-[12.5px] text-muted">
            {{ t('compliance.invoices.billed_on', { date: formatDate(invoice.billed_at) }) }}
            · <RouterLink :to="{ name: 'accounting-document', params: { id: invoice.bill_id } }" class="font-medium text-brand-strong hover:underline">{{ t('compliance.invoices.open_bill') }}</RouterLink>
        </p>
        <p v-else-if="!invoice.books && invoice.status !== 'void'" class="px-4 pb-3 text-[12.5px] text-muted">{{ t('compliance.invoices.no_books') }}</p>
        <!-- Payments told to the provider and its answer. -->
        <section v-if="invoice.advices?.length" class="border-t border-line px-4 py-2.5">
            <h3 class="text-[12.5px] font-semibold text-muted">{{ t('compliance.advices.told') }}</h3>
            <ul class="mt-1.5 space-y-1.5">
                <li v-for="advice in invoice.advices" :key="advice.id" class="text-[13px]">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="tabular font-medium">{{ money(advice.amount_minor) }}</span>
                        <span class="text-muted">{{ formatDate(advice.paid_on) }} · {{ t(`compliance.advices.methods.${advice.method}`) }}<template v-if="advice.reference"> · <span dir="ltr">{{ advice.reference }}</span></template></span>
                        <AppBadge :tone="adviceTone(advice.status)">{{ t(`compliance.advices.status_${advice.status}`) }}</AppBadge>
                    </div>
                    <p v-if="advice.reply_reason" class="mt-0.5 text-[12.5px] text-muted">{{ t('compliance.advices.their_reason', { reason: advice.reply_reason }) }}</p>
                </li>
            </ul>
        </section>
        <footer v-if="invoice.can.bill || invoice.can.advise" class="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-2.5">
            <AppButton v-if="invoice.can.advise" size="sm" :icon="BanknoteArrowUp" @click="adviceOpen = true">{{ t('compliance.advices.open') }}</AppButton>
            <AppButton v-if="invoice.can.bill" size="sm" variant="primary" :icon="BookPlus" :loading="busy" @click="bill">{{ t('compliance.invoices.bill') }}</AppButton>
        </footer>
        <AdviceDialog :open="adviceOpen" :invoice="invoice" :calls="calls" @close="adviceOpen = false" @sent="emit('billed', $event)" />
    </article>
</template>
