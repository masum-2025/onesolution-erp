<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Printer, ReceiptText } from 'lucide-vue-next';
import { AppButton, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { billingApi, money } from '../api';

/**
 * The bill and money receipt on its own paper: the company and branch, the
 * patient, the services, totals, and every payment and refund.
 */
const pb = billingApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => pb.receipt(route.params.id));
const bill = computed(() => view.data.value?.data ?? null);
const m = (amount) => formatMoney(money(amount, bill.value?.currency));
const printPaper = () => window.print();
</script>

<template>
    <PageFrame :title="bill ? `${t('patient_billing.bill.title')} ${bill.number}` : ''" :resource="view" :not-found="{ icon: ReceiptText, title: t('patient_billing.bill.not_found') }" width="md" own-paper>
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'patient-billing-bill', params: { id: route.params.id } })">{{ t('patient_billing.receipt.back') }}</AppButton>
        </template>
        <template v-if="bill" #actions>
            <AppButton variant="primary" :icon="Printer" @click="printPaper">{{ t('patient_billing.bill.print') }}</AppButton>
        </template>
        <article v-if="bill" class="mx-auto max-w-[180mm] rounded-xl bg-white p-6 text-[10.5pt] text-black ring-1 ring-line print:p-0 print:ring-0">
            <header class="mb-4 text-center">
                <p class="text-[14pt] font-bold">{{ bill.organization }}</p>
                <p v-if="bill.branch && bill.branch !== bill.organization">{{ bill.branch }}</p>
                <p class="mt-2 font-semibold">{{ bill.status === 'final' ? t('patient_billing.receipt.bill') : t('patient_billing.receipt.provisional') }}</p>
            </header>
            <dl class="mb-4 grid grid-cols-2 gap-x-4 gap-y-1">
                <div><dt class="inline text-gray-600">{{ t('patient_billing.receipt.number') }}:</dt> <dd class="inline font-mono" dir="ltr">{{ bill.number }}</dd></div>
                <div><dt class="inline text-gray-600">{{ t('patient_billing.receipt.date') }}:</dt> <dd class="inline">{{ formatDateTime(bill.finalized_at ?? bill.created_at) }}</dd></div>
                <div><dt class="inline text-gray-600">{{ t('patient_billing.receipt.patient') }}:</dt> <dd class="inline">{{ bill.patient?.name }}</dd></div>
                <div><dt class="inline text-gray-600">{{ t('patient_billing.receipt.mrn') }}:</dt> <dd class="inline font-mono" dir="ltr">{{ bill.patient?.mrn }}</dd></div>
                <div v-if="bill.panel_name" class="col-span-2"><dt class="inline text-gray-600">{{ t('patient_billing.bill.panel') }}:</dt> <dd class="inline">{{ bill.panel_name }}</dd></div>
            </dl>
            <table class="w-full border-collapse">
                <thead><tr class="border-b border-black text-start"><th class="py-1 text-start">{{ t('patient_billing.bill.service') }}</th><th class="py-1 text-end">{{ t('patient_billing.bill.quantity') }}</th><th class="py-1 text-end">{{ t('patient_billing.bill.amount') }}</th></tr></thead>
                <tbody>
                    <tr v-for="line in bill.lines" :key="line.id" class="border-b border-gray-300"><td class="py-1">{{ line.description }}</td><td class="py-1 text-end tabular">{{ line.quantity_milli / 1000 }}</td><td class="py-1 text-end tabular">{{ m(line.gross_minor) }}</td></tr>
                </tbody>
            </table>
            <dl class="ms-auto mt-3 grid w-64 grid-cols-[1fr_auto] gap-x-3 tabular">
                <dt>{{ t('patient_billing.bill.subtotal') }}</dt><dd class="text-end">{{ m(bill.subtotal_minor) }}</dd>
                <template v-if="bill.panel_discount_minor + bill.discount_minor"><dt>{{ t('patient_billing.bill.discount') }}</dt><dd class="text-end">− {{ m(bill.panel_discount_minor + bill.discount_minor) }}</dd></template>
                <template v-if="bill.tax_minor"><dt>{{ bill.prices_include_tax ? t('patient_billing.bill.vat_inside') : t('patient_billing.bill.vat') }}</dt><dd class="text-end">{{ m(bill.tax_minor) }}</dd></template>
                <dt class="border-t border-black font-bold">{{ t('patient_billing.bill.total') }}</dt><dd class="border-t border-black text-end font-bold">{{ m(bill.total_minor) }}</dd>
                <dt>{{ t('patient_billing.bill.paid') }}</dt><dd class="text-end">{{ m(bill.paid_minor - bill.refunded_minor) }}</dd>
                <dt class="font-bold">{{ t('patient_billing.bill.due') }}</dt><dd class="text-end font-bold">{{ m(bill.due_minor) }}</dd>
            </dl>
            <section v-if="bill.payments.length" class="mt-4">
                <p class="font-semibold">{{ t('patient_billing.bill.money') }}</p>
                <p v-for="item in bill.payments" :key="item.id" class="flex justify-between"><span><span class="font-mono" dir="ltr">{{ item.number }}</span> · {{ t(`patient_billing.method.${item.method}`) }} · {{ formatDateTime(item.received_at) }}</span><span class="tabular">{{ item.kind === 'refund' ? '− ' : '' }}{{ m(item.amount_minor) }}</span></p>
            </section>
        </article>
    </PageFrame>
</template>
