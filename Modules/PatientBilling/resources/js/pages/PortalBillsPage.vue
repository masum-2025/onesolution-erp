<script setup>
import { computed } from 'vue';
import { ReceiptText } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';
import { money, portalBills } from '../api';

/**
 * The portal: a patient's (or a parent's) own final bills, what was on them
 * and what was paid.
 */
const view = useResource(() => portalBills());
const data = computed(() => view.data.value?.data ?? null);
const bills = computed(() => data.value?.bills ?? []);
const m = (amount, currency) => formatMoney(money(amount, currency));
</script>

<template>
    <PageFrame :title="t('patient_billing.portal.title')" :description="data ? t('patient_billing.portal.text', { org: data.organization }) : ''" :resource="view" width="md"
               :empty="{ when: !!data && !bills.length, icon: ReceiptText, title: t('patient_billing.portal.empty'), text: t('patient_billing.portal.empty_text') }">
        <section v-for="bill in bills" :key="bill.id" class="card mb-4">
            <header class="flex justify-between gap-3 border-b border-line px-5 py-3">
                <span><span class="block font-mono text-[14px] font-semibold" dir="ltr">{{ bill.number }}</span><span class="text-[12.5px] text-muted">{{ formatDateTime(bill.finalized_at) }}</span></span>
                <span class="text-end tabular"><span class="block font-semibold">{{ m(bill.total_minor, bill.currency) }}</span><span v-if="bill.due_minor" class="text-[12.5px] text-bad">{{ t('patient_billing.bills.due_short', { amount: m(bill.due_minor, bill.currency) }) }}</span></span>
            </header>
            <ul class="divide-y divide-line text-[13.5px]">
                <li v-for="line in bill.lines" :key="line.id" class="flex justify-between gap-3 px-5 py-2"><span>{{ line.description }} × {{ line.quantity_milli / 1000 }}</span><span class="tabular">{{ m(line.total_minor, bill.currency) }}</span></li>
            </ul>
            <p v-for="item in bill.payments" :key="item.id" class="flex justify-between gap-3 border-t border-line px-5 py-2 text-[12.5px] text-muted">
                <span>{{ t(item.kind === 'refund' ? 'patient_billing.portal.refund' : 'patient_billing.portal.paid') }} · {{ t(`patient_billing.method.${item.method}`) }} · {{ formatDateTime(item.received_at) }}</span>
                <span class="tabular">{{ m(item.amount_minor, bill.currency) }}</span>
            </p>
        </section>
    </PageFrame>
</template>
