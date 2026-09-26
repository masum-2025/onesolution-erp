<script setup>
import { computed } from 'vue';
import AppBadge from '@/components/AppBadge.vue';
import { statusTone } from '@/lib/billing';
import { countryName } from '@/lib/display';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * One invoice or credit note as a document: the brand it was issued under,
 * seller and buyer as they were at issue, lines and totals. Printable (the
 * app around it hides when printing).
 */
const props = defineProps({ invoice: { type: Object, required: true } });

const money = (amount) => formatMoney({ amount, currency: props.invoice.currency });
const isCredit = computed(() => props.invoice.type === 'credit_note');
const taxPercent = computed(() => formatNumber(props.invoice.tax_rate_bp / 100, { maximumFractionDigits: 2 }));
</script>

<template>
    <article class="card overflow-hidden print:border-0 print:shadow-none">
        <div class="h-1.5" :style="{ background: invoice.brand.primary_color }" aria-hidden="true" />
        <div class="space-y-8 p-6 sm:p-8">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-[18px] font-semibold text-fg">{{ invoice.brand.name }}</p>
                    <p v-if="invoice.brand.support_email" class="text-[12.5px] text-muted" dir="ltr">{{ invoice.brand.support_email }}</p>
                </div>
                <div class="text-end">
                    <p class="text-[12px] font-semibold tracking-wider text-muted uppercase">{{ t(isCredit ? 'billing.document.credit_note' : 'billing.document.invoice') }}</p>
                    <p class="tabular text-[17px] font-semibold text-fg" dir="ltr">{{ invoice.number }}</p>
                    <AppBadge v-if="!isCredit" class="mt-1" :tone="statusTone(invoice.status)">{{ t(`billing.status.${invoice.status}`) }}</AppBadge>
                </div>
            </header>

            <div class="grid gap-6 text-[13px] sm:grid-cols-3">
                <div>
                    <p class="mb-1 text-[11.5px] font-semibold tracking-wider text-faint uppercase">{{ t('billing.document.from') }}</p>
                    <p class="font-medium text-fg">{{ invoice.seller.name }}</p>
                    <p v-if="invoice.seller.address" class="whitespace-pre-line text-fg-2">{{ invoice.seller.address }}</p>
                    <p v-if="invoice.seller.tax_id" class="text-fg-2">{{ t('billing.document.tax_id', { id: invoice.seller.tax_id }) }}</p>
                    <p v-if="invoice.brand.name !== invoice.seller.name" class="mt-1 text-[12px] text-muted">{{ t('billing.document.on_behalf', { brand: invoice.brand.name }) }}</p>
                </div>
                <div>
                    <p class="mb-1 text-[11.5px] font-semibold tracking-wider text-faint uppercase">{{ t('billing.document.to') }}</p>
                    <p class="font-medium text-fg">{{ invoice.buyer_details.name }}</p>
                    <p v-if="invoice.buyer_details.country_code" class="text-fg-2">{{ countryName(invoice.buyer_details.country_code) }}</p>
                </div>
                <dl class="space-y-1">
                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('billing.document.issued') }}</dt><dd class="text-fg">{{ formatDate(invoice.issued_at) }}</dd></div>
                    <div v-if="invoice.due_at" class="flex justify-between gap-3"><dt class="text-muted">{{ t('billing.document.due') }}</dt><dd class="text-fg">{{ formatDate(invoice.due_at) }}</dd></div>
                    <div v-if="invoice.period_start" class="flex justify-between gap-3">
                        <dt class="text-muted">{{ t('billing.document.period') }}</dt>
                        <dd class="text-end text-fg">{{ formatDate(invoice.period_start) }} – {{ formatDate(invoice.period_end) }}</dd>
                    </div>
                    <div v-if="invoice.credits" class="flex justify-between gap-3"><dt class="text-muted">{{ t('billing.document.credits') }}</dt><dd class="tabular text-fg" dir="ltr">{{ invoice.credits.number }}</dd></div>
                </dl>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[480px] text-[13px]">
                    <thead>
                        <tr class="border-b border-line text-start text-[11.5px] font-semibold tracking-wider text-faint uppercase">
                            <th class="py-2 pe-3 text-start font-semibold">{{ t('billing.document.description') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ t('billing.document.quantity') }}</th>
                            <th class="px-3 py-2 text-end font-semibold">{{ t('billing.document.unit') }}</th>
                            <th class="py-2 ps-3 text-end font-semibold">{{ t('billing.document.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(line, index) in invoice.lines" :key="index">
                            <td class="py-2.5 pe-3 text-fg">{{ line.description }}</td>
                            <td class="tabular px-3 py-2.5 text-end text-fg-2">{{ formatNumber(line.quantity) }}</td>
                            <td class="tabular px-3 py-2.5 text-end text-fg-2">{{ money(line.unit_amount_minor) }}</td>
                            <td class="tabular py-2.5 ps-3 text-end font-medium text-fg">{{ money(line.amount_minor) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <dl class="ms-auto w-full max-w-xs space-y-1.5 text-[13px]">
                <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('billing.document.subtotal') }}</dt><dd class="tabular text-fg">{{ money(invoice.subtotal_minor) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('billing.document.tax', { rate: taxPercent }) }}</dt><dd class="tabular text-fg">{{ money(invoice.tax_minor) }}</dd></div>
                <div class="flex justify-between gap-3 border-t border-line pt-2 text-[15px] font-semibold">
                    <dt class="text-fg">{{ t(isCredit ? 'billing.document.credit_total' : 'billing.document.total') }}</dt>
                    <dd class="tabular text-fg">{{ money(invoice.total_minor) }}</dd>
                </div>
            </dl>

            <div class="space-y-1.5 border-t border-line pt-5 text-[12.5px] text-fg-2">
                <p v-if="invoice.reason">{{ t('billing.document.reason', { reason: invoice.reason }) }}</p>
                <p v-if="invoice.paid_at">{{ t('billing.document.paid', { date: formatDate(invoice.paid_at), reference: invoice.payment_reference ?? '—' }) }}</p>
                <p v-for="note in invoice.credit_notes" :key="note.id">
                    {{ t('billing.document.credited_by', { number: note.number, amount: money(note.total_minor), date: formatDate(note.issued_at) }) }}
                </p>
            </div>
        </div>
    </article>
</template>
