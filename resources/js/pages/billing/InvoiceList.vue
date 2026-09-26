<script setup>
import { RouterLink } from 'vue-router';
import { ChevronRight } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import { statusTone } from '@/lib/billing';
import { formatDate, formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';

/** A list of invoices and credit notes; each opens its document. */
defineProps({
    invoices: { type: Array, required: true },
    base: { type: String, required: true }, // '/partner/billing/invoices' or '/billing/invoices'
    showBuyer: Boolean,
});
</script>

<template>
    <ul class="divide-y divide-line">
        <li v-for="invoice in invoices" :key="invoice.id">
            <RouterLink :to="`${base}/${invoice.id}`" class="flex items-center gap-3 px-5 py-3.5 transition hover:bg-subtle/60">
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                        <span class="tabular" dir="ltr">{{ invoice.number }}</span>
                        <AppBadge v-if="invoice.type === 'credit_note'" tone="outline">{{ t('billing.document.credit_note') }}</AppBadge>
                        <AppBadge v-else :tone="statusTone(invoice.status)">{{ t(`billing.status.${invoice.status}`) }}</AppBadge>
                    </p>
                    <p class="mt-0.5 text-[12.5px] text-muted">
                        <template v-if="showBuyer">{{ invoice.buyer }} · </template>
                        <template v-if="invoice.period_start">{{ formatDate(invoice.period_start) }} – {{ formatDate(invoice.period_end) }} · </template>
                        <template v-if="invoice.credits">{{ t('billing.list.credits', { number: invoice.credits.number }) }} · </template>
                        <template v-if="invoice.type === 'credit_note'">{{ formatDate(invoice.issued_at) }}</template>
                        <template v-else-if="invoice.status === 'issued' || invoice.status === 'overdue'">{{ t('billing.list.due', { date: formatDate(invoice.due_at) }) }}</template>
                        <template v-else-if="invoice.paid_at">{{ t('billing.list.paid', { date: formatDate(invoice.paid_at) }) }}</template>
                        <template v-else>{{ formatDate(invoice.issued_at) }}</template>
                    </p>
                </div>
                <span class="tabular shrink-0 text-[14px] font-semibold text-fg">
                    <template v-if="invoice.type === 'credit_note'">−</template>{{ formatMoney({ amount: invoice.total_minor, currency: invoice.currency }) }}
                </span>
                <ChevronRight class="size-4 shrink-0 text-faint rtl:rotate-180" aria-hidden="true" />
            </RouterLink>
        </li>
    </ul>
</template>
