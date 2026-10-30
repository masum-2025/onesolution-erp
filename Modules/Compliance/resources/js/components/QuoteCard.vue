<script setup>
import { ref } from 'vue';
import { CheckCircle2, XCircle } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDecimal, formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * A quotation a connected provider sent: its lines and totals as they came,
 * and accepting or declining it (with a reason); the provider is told at once.
 */
const props = defineProps({
    quote: { type: Object, required: true },
    calls: { type: Object, required: true },
    showFactory: Boolean,
});
const emit = defineEmits(['decided']);

const busy = ref(false);
const money = (amount) => formatMoney({ amount, currency: props.quote.currency_code });
const quantity = (milli) => formatDecimal((milli / 1000).toString());
const tone = { pending: 'warn', accepted: 'ok', declined: 'bad', replaced: 'outline' };

async function decide(step) {
    const answer = await confirmAction({
        title: t(`compliance.quotes.${step}_title`, { number: props.quote.number ?? '' }),
        message: t(`compliance.quotes.${step}_text`, { from: props.quote.from, total: money(props.quote.total_minor) }),
        confirmLabel: t(`compliance.quotes.${step}`),
        danger: step === 'decline',
        reason: step === 'decline' ? 'required' : 'none',
    });
    if (!answer) return;
    busy.value = true;
    try {
        const { data } = await props.calls.decideQuote(props.quote.id, step, props.quote.version, answer.reason);
        toast.success(t(`compliance.quotes.${step}_done`));
        emit('decided', data);
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
                <span class="font-semibold">{{ t('compliance.quotes.title', { number: quote.number ?? '' }) }}</span>
                <span v-if="showFactory" class="text-muted"> · {{ quote.factory.name }}</span>
                <span class="block text-[12.5px] text-muted">
                    {{ quote.from }}<template v-if="quote.issue_date"> · {{ formatDate(quote.issue_date) }}</template><template v-if="quote.valid_until"> · {{ t('compliance.quotes.valid_until', { date: formatDate(quote.valid_until) }) }}</template>
                </span>
            </p>
            <AppBadge :tone="tone[quote.status] ?? 'neutral'">{{ t(`compliance.quotes.status_${quote.status}`) }}</AppBadge>
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
                    <tr v-for="(line, index) in quote.lines" :key="index">
                        <td class="px-4 py-1.5">{{ line.description }}</td>
                        <td class="tabular px-2 py-1.5 text-end">{{ quantity(line.quantity_milli) }}<template v-if="line.unit"> {{ line.unit }}</template></td>
                        <td class="tabular px-2 py-1.5 text-end">{{ money(line.unit_price_minor) }}</td>
                        <td class="tabular px-4 py-1.5 text-end">{{ money(line.total_minor) }}</td>
                    </tr>
                </tbody>
                <tfoot class="text-[13px]">
                    <tr v-if="quote.tax_minor"><td colspan="3" class="px-4 pt-2 text-end text-muted">{{ t('compliance.quotes.vat') }}</td><td class="tabular px-4 pt-2 text-end">{{ money(quote.tax_minor) }}</td></tr>
                    <tr><td colspan="3" class="px-4 py-2 text-end font-semibold">{{ t('compliance.quotes.total') }}</td><td class="tabular px-4 py-2 text-end font-semibold">{{ money(quote.total_minor) }}</td></tr>
                </tfoot>
            </table>
        </div>
        <p v-if="quote.terms" class="px-4 pb-2 text-[12.5px] text-fg-2">{{ quote.terms }}</p>
        <p v-if="quote.reason" class="px-4 pb-3 text-[12.5px] text-muted">{{ t('compliance.quotes.declined_because', { reason: quote.reason }) }}</p>
        <footer v-if="quote.can.decide" class="flex flex-wrap justify-end gap-2 border-t border-line px-4 py-2.5">
            <AppButton size="sm" variant="ghost" class="text-bad" :icon="XCircle" :disabled="busy" @click="decide('decline')">{{ t('compliance.quotes.decline') }}</AppButton>
            <AppButton size="sm" variant="primary" :icon="CheckCircle2" :loading="busy" @click="decide('accept')">{{ t('compliance.quotes.accept') }}</AppButton>
        </footer>
    </article>
</template>
