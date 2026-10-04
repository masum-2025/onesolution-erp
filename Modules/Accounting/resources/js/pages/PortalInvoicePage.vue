<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, CreditCard, Printer } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { brand } from '@/lib/brand';
import { newOpId } from '@/lib/billing';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { documentTone, isCredit, statusKey } from '../lib';

/**
 * One of the member's own invoices: lines, what was paid, and paying the
 * rest online in the company's own checkout (one op id per page, so a
 * second press opens the same payment).
 */
const route = useRoute();
const resource = useResource(() => api(`/api/portal/accounting/invoices/${route.params.id}`).then((response) => response.data));
const invoice = computed(() => resource.data.value);
const money = (amount) => formatMoney({ amount, currency: invoice.value?.currency });
const kind = computed(() => (invoice.value ? t(`accounting.kinds.${invoice.value.type}`) : ''));

const opId = newOpId();
const paying = ref(false);
async function pay() {
    paying.value = true;
    try {
        const { data } = await api(`/api/portal/accounting/invoices/${invoice.value.id}/pay`, { method: 'POST', body: { op_id: opId } });
        window.location.assign(data.checkout_url);
    } catch (error) {
        toast.error(error.message);
        paying.value = false;
    }
}

function printPage() {
    window.print();
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <div class="mb-4 flex items-center justify-between print:hidden">
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" to="/portal/invoices">{{ t('accounting.portal_page.back') }}</AppButton>
            <AppButton v-if="invoice" size="sm" :icon="Printer" @click="printPage">{{ t('accounting.portal_page.print') }}</AppButton>
        </div>
        <SkeletonRows v-if="resource.loading.value && !invoice" :rows="5" />
        <ErrorState v-else-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />

        <template v-else-if="invoice">
            <article class="card p-6 sm:p-8 print:border-0 print:p-0 print:shadow-none">
                <header class="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-5">
                    <div class="flex items-center gap-3">
                        <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-10 w-auto" />
                        <div class="text-[16px] font-semibold">{{ invoice.organization }}</div>
                    </div>
                    <div class="text-end">
                        <div class="text-[20px] font-semibold uppercase tracking-wide text-brand-text">{{ kind }}</div>
                        <div class="font-mono text-[13px]" dir="ltr">{{ invoice.number }}</div>
                        <AppBadge :tone="documentTone(invoice.status)" dot class="mt-1 print:hidden">{{ t(statusKey(invoice.type, invoice.status)) }}</AppBadge>
                    </div>
                </header>

                <div class="grid gap-3 py-5 text-[13px] sm:grid-cols-2">
                    <div>
                        <div class="text-[12px] uppercase tracking-wide text-muted">{{ t('accounting.documents.bill_to') }}</div>
                        <div class="mt-1 text-[14px] font-medium">{{ invoice.customer }}</div>
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 sm:justify-self-end">
                        <dt class="text-muted">{{ t('accounting.documents.issue_date') }}</dt><dd>{{ formatDate(invoice.issue_date) }}</dd>
                        <template v-if="invoice.due_date"><dt class="text-muted">{{ t('accounting.documents.due_date') }}</dt><dd>{{ formatDate(invoice.due_date) }}</dd></template>
                    </dl>
                </div>

                <!-- Phones: one line per row; wider screens: the table. -->
                <ul class="divide-y divide-line border-y border-line sm:hidden">
                    <li v-for="(line, index) in invoice.lines" :key="index" class="flex items-start justify-between gap-3 py-2.5 text-[13.5px]">
                        <span class="min-w-0">
                            <span class="block">{{ line.description }}</span>
                            <span class="tabular block text-[12px] text-muted">{{ formatNumber(line.quantity) }} × {{ money(line.unit_price_minor) }}</span>
                        </span>
                        <span class="tabular shrink-0">{{ money(line.amount_minor) }}</span>
                    </li>
                </ul>
                <div class="hidden sm:block">
                    <table class="w-full text-[13.5px]">
                        <thead class="border-y border-line text-[12px] text-muted">
                            <tr>
                                <th class="py-2 pe-3 text-start font-medium">{{ t('accounting.documents.description') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('accounting.documents.quantity') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('accounting.documents.price') }}</th>
                                <th class="py-2 ps-3 text-end font-medium">{{ t('accounting.documents.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="(line, index) in invoice.lines" :key="index">
                                <td class="py-2 pe-3">{{ line.description }}</td>
                                <td class="tabular px-3 py-2 text-end">{{ formatNumber(line.quantity) }}</td>
                                <td class="tabular px-3 py-2 text-end">{{ money(line.unit_price_minor) }}</td>
                                <td class="tabular py-2 ps-3 text-end">{{ money(line.amount_minor) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <dl class="ms-auto mt-4 grid max-w-xs grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-[14px]">
                    <template v-if="invoice.tax_minor">
                        <dt class="text-muted">{{ t('accounting.tax.net') }}</dt><dd class="tabular text-end">{{ money(invoice.net_minor) }}</dd>
                        <dt class="text-muted">{{ t('accounting.tax.vat') }}</dt><dd class="tabular text-end">{{ money(invoice.tax_minor) }}</dd>
                    </template>
                    <dt class="font-semibold">{{ t('accounting.documents.total') }}</dt><dd class="tabular text-end font-semibold">{{ money(invoice.total_minor) }}</dd>
                    <template v-if="!isCredit(invoice.type) && invoice.payments.length">
                        <dt class="text-muted">{{ t('accounting.documents.paid') }}</dt><dd class="tabular text-end">{{ money(invoice.total_minor - invoice.balance_minor) }}</dd>
                        <dt class="font-semibold">{{ t('accounting.documents.balance') }}</dt><dd class="tabular text-end font-semibold">{{ money(invoice.balance_minor) }}</dd>
                    </template>
                </dl>
                <p v-if="invoice.notes" class="mt-6 border-t border-line pt-4 text-[13px] text-muted">{{ invoice.notes }}</p>
            </article>

            <section v-if="invoice.payments.length" class="card mt-4 print:hidden">
                <header class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('accounting.portal_page.paid_note') }}</header>
                <ul class="divide-y divide-line">
                    <li v-for="(payment, index) in invoice.payments" :key="index" class="flex justify-between px-5 py-2.5 text-[13.5px]">
                        <span>{{ formatDate(payment.on) }}</span><span class="tabular">{{ money(payment.amount_minor) }}</span>
                    </li>
                </ul>
            </section>

            <div class="mt-5 flex justify-end print:hidden">
                <AppButton v-if="invoice.can_pay" variant="primary" size="lg" :icon="CreditCard" :loading="paying" @click="pay">
                    {{ t('accounting.portal_page.pay', { amount: money(invoice.balance_minor) }) }}
                </AppButton>
                <p v-else-if="!isCredit(invoice.type) && invoice.balance_minor <= 0" class="text-[13px] text-ok">{{ t('accounting.portal_page.none_due') }}</p>
            </div>
        </template>
    </div>
</template>
