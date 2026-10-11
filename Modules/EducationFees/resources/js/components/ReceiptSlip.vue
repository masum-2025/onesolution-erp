<script setup>
import { computed } from 'vue';
import { brand } from '@/lib/brand';
import { formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { billTitle, day } from '../labels';

/**
 * A money receipt as it prints: on A5, or on an 80 mm roll for thermal
 * printers (narrower, everything in one column). The institution's own
 * name and logo; voided receipts say so across them.
 */
const props = defineProps({
    receipt: { type: Object, required: true },
    size: { type: String, default: 'a5' },
});
const money = (minor) => formatMoney({ amount: minor ?? 0, currency: props.receipt.currency });
const thermal = computed(() => props.size === 'thermal');
</script>

<template>
    <article
        class="relative mx-auto overflow-hidden bg-surface text-fg print:shadow-none"
        :class="thermal ? 'w-[80mm] max-w-full p-4 text-[11.5px]' : 'w-full max-w-[148mm] p-6 text-[13px] sm:p-8'"
    >
        <p v-if="receipt.status === 'voided'" class="pointer-events-none absolute inset-0 grid place-items-center -rotate-[18deg] text-[44px] font-black tracking-[0.3em] text-bad/20" aria-hidden="true">
            {{ t('education_fees.receipt.voided') }}
        </p>
        <header :class="thermal ? 'text-center' : 'flex items-start justify-between gap-4'">
            <div :class="thermal ? 'flex flex-col items-center gap-1' : 'flex items-center gap-3'">
                <img v-if="brand.logo_url" :src="brand.logo_url" alt="" :class="thermal ? 'h-8 w-auto' : 'h-11 w-auto'" />
                <div>
                    <div class="font-semibold" :class="thermal ? 'text-[13px]' : 'text-[16px]'">{{ receipt.institution ?? brand.name }}</div>
                    <div v-if="receipt.campus" class="text-muted">{{ receipt.campus }}</div>
                </div>
            </div>
            <div :class="thermal ? 'mt-2' : 'text-end'">
                <div class="font-semibold uppercase tracking-wide text-brand-text" :class="thermal ? 'text-[12px]' : 'text-[15px]'">{{ t('education_fees.receipt.title') }}</div>
                <div class="text-muted">{{ t('education_fees.receipt.number') }} <span class="font-mono text-fg" dir="ltr">{{ receipt.number }}</span></div>
                <div class="text-muted">{{ t('education_fees.receipt.date') }} {{ day(receipt.received_on) }}</div>
            </div>
        </header>

        <dl class="mt-4 grid gap-x-4 gap-y-1 border-y border-dashed border-line py-3" :class="thermal ? 'grid-cols-1' : 'grid-cols-[auto_1fr]'">
            <dt class="text-muted">{{ t('education_fees.receipt.student') }}</dt>
            <dd class="font-medium">{{ receipt.student?.name ?? '—' }} <span class="font-mono text-muted" dir="ltr">{{ receipt.student?.code }}</span></dd>
            <dt class="text-muted">{{ t('education_fees.receipt.method') }}</dt>
            <dd>{{ t(`education_fees.methods.${receipt.method}`) }}<template v-if="receipt.reference"> · <span class="font-mono" dir="ltr">{{ receipt.reference }}</span></template></dd>
        </dl>

        <table class="mt-3 w-full">
            <caption class="sr-only">{{ t('education_fees.receipt.bills') }}</caption>
            <tbody>
                <tr v-for="bill in receipt.bills ?? []" :key="bill.bill_id">
                    <td class="py-1 pe-2">{{ billTitle(bill) }} <span class="font-mono text-muted" dir="ltr">{{ bill.number }}</span></td>
                    <td class="tabular py-1 text-end">{{ money(bill.amount_minor) }}</td>
                </tr>
                <tr v-if="receipt.advance_minor">
                    <td class="py-1 pe-2">{{ t('education_fees.receipt.to_advance') }}</td>
                    <td class="tabular py-1 text-end">{{ money(receipt.advance_minor) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="border-t border-line">
                    <th scope="row" class="pt-2 text-start font-semibold">{{ t('education_fees.receipt.total') }}</th>
                    <td class="tabular pt-2 text-end font-semibold" :class="thermal ? 'text-[14px]' : 'text-[18px]'">{{ money(receipt.amount_minor) }}</td>
                </tr>
            </tfoot>
        </table>

        <footer class="mt-5 flex items-end justify-between gap-4 text-muted" :class="thermal ? 'flex-col items-center text-center' : ''">
            <div>
                <p>{{ t('education_fees.receipt.received_by') }}: <span class="text-fg">{{ receipt.collector ?? t('education_fees.receipt.online') }}</span></p>
                <p class="mt-1 text-[11px]">{{ t('education_fees.receipt.computer_made') }}</p>
            </div>
            <p class="font-medium text-fg">{{ t('education_fees.receipt.thanks') }}</p>
        </footer>
    </article>
</template>
