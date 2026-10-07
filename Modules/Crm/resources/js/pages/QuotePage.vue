<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, CheckCircle2, FileText, PenLine, Printer, Repeat, Send, Trash2, XCircle } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { brand } from '@/lib/brand';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { fieldText, formatQuantity, phoneText, quoteTone } from '../lib';
import { useCrmSetup } from '../setup';

/**
 * One estimate or quotation, laid out to print on A4 with the company's
 * brand: the customer, days, lines (with the line fields marked to print),
 * totals with VAT, the quote's own printed fields, notes and terms. Steps:
 * change it, mark it sent, accepted (and make the invoice) or declined, turn
 * an estimate into a quotation, delete a draft.
 */
const crm = crmApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useCrmSetup();
const record = useResource(() => crm.quote(route.params.id));
const quote = computed(() => record.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: quote.value?.currency });
const day = (date) => (date ? formatDate(`${date}T00:00:00Z`, { dateStyle: 'long', timeZone: 'UTC' }) : '—');
const printedQuoteFields = computed(() => setup.fields('quote').filter((field) => field.on_print && quote.value?.extra?.[field.key] !== undefined));
const printedLineFields = computed(() => setup.fields('quote_line').filter((field) => field.on_print));
const busy = ref(null);
const printPage = () => window.print();

async function step(name, body = {}) {
    busy.value = name;
    try {
        const { data } = await crm.quoteStep(quote.value.id, name, { base_version: quote.value.version, ...body });
        toast.success(t(`crm.quotes.done.${name}`, { number: data.number }));
        if (name === 'convert') router.push({ name: 'crm-quote', params: { id: data.id } });
        record.reload();
    } catch (error) {
        if (error.code === 'version_conflict') record.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
const accepting = ref(false);
const withInvoice = ref(true);
async function decline() {
    const confirmed = await confirmAction({ title: t('crm.quotes.decline_title'), message: t('crm.quotes.decline_text'), confirmLabel: t('crm.quotes.steps.decline'), reason: 'required', danger: true });
    if (confirmed) step('decline', { reason: confirmed.reason });
}
async function remove() {
    const confirmed = await confirmAction({ title: t('crm.quotes.delete_title'), message: t('crm.quotes.delete_text'), confirmLabel: t('crm.common.delete'), danger: true });
    if (!confirmed) return;
    try {
        await crm.deleteQuote(quote.value.id, quote.value.version);
        toast.success(t('crm.quotes.deleted'));
        router.push({ name: 'crm-quotes' });
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div class="mx-auto max-w-4xl">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2 print:hidden">
            <AppButton variant="ghost" size="sm" :to="{ name: 'crm-quotes' }" :icon="ArrowLeft">{{ t('crm.quotes.title') }}</AppButton>
            <div v-if="quote" class="flex flex-wrap gap-2">
                <AppButton v-if="quote.can.edit" size="sm" variant="ghost" :icon="PenLine" :to="{ name: 'crm-quote-edit', params: { id: quote.id } }">{{ t('crm.common.edit') }}</AppButton>
                <AppButton v-if="quote.can.delete" size="sm" variant="ghost" :icon="Trash2" :aria-label="t('crm.common.delete')" @click="remove" />
                <AppButton v-if="quote.can.decline" size="sm" variant="ghost" :icon="XCircle" @click="decline">{{ t('crm.quotes.steps.decline') }}</AppButton>
                <AppButton v-if="quote.can.convert" size="sm" variant="secondary" :icon="Repeat" :loading="busy === 'convert'" @click="step('convert')">{{ t('crm.quotes.steps.convert') }}</AppButton>
                <AppButton v-if="quote.can.send" size="sm" variant="secondary" :icon="Send" :loading="busy === 'send'" @click="step('send')">{{ t(quote.status === 'sent' ? 'crm.quotes.steps.send_again' : 'crm.quotes.steps.send') }}</AppButton>
                <AppButton v-if="quote.can.accept" size="sm" variant="primary" :icon="CheckCircle2" @click="accepting = true">{{ t('crm.quotes.steps.accept') }}</AppButton>
                <AppButton size="sm" variant="secondary" :icon="Printer" @click="printPage">{{ t('crm.quotes.print') }}</AppButton>
            </div>
        </div>
        <SkeletonRows v-if="record.loading.value && !quote" :rows="8" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="quote">
            <div class="mb-3 flex flex-wrap gap-2 print:hidden">
                <AppBadge :tone="quoteTone(quote)" dot>{{ t(quote.expired ? 'crm.quote_status.expired' : `crm.quote_status.${quote.status}`) }}</AppBadge>
                <AppBadge v-if="quote.decline_reason" tone="bad">{{ quote.decline_reason }}</AppBadge>
                <RouterLink v-if="quote.invoice_id" :to="{ name: 'accounting-document', params: { id: quote.invoice_id } }" class="inline-flex items-center gap-1 text-[13px] text-brand-text hover:underline">
                    <FileText class="size-3.5" aria-hidden="true" />{{ t('crm.quotes.open_invoice') }}
                </RouterLink>
                <RouterLink v-if="quote.from_quote_id" :to="{ name: 'crm-quote', params: { id: quote.from_quote_id } }" class="text-[13px] text-brand-text hover:underline">{{ t('crm.quotes.from_estimate') }}</RouterLink>
            </div>

            <article class="card quote-sheet p-8 text-[13px] print:border-0 print:p-0 print:shadow-none">
                <header class="flex flex-wrap items-start justify-between gap-6 border-b border-line pb-5">
                    <div>
                        <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="mb-2 h-10 w-auto" />
                        <p class="text-[17px] font-semibold">{{ quote.company }}</p>
                    </div>
                    <div class="text-end">
                        <p class="text-[22px] font-semibold uppercase tracking-wide text-brand-text">{{ t(`crm.quote_kinds.${quote.kind}`) }}</p>
                        <p class="font-mono" dir="ltr">{{ quote.number }}</p>
                        <p class="mt-1 text-muted">{{ t('crm.quotes.issued', { date: day(quote.issue_date) }) }}</p>
                        <p v-if="quote.valid_until" class="text-muted">{{ t('crm.quotes.valid', { date: day(quote.valid_until) }) }}</p>
                    </div>
                </header>

                <section class="grid gap-6 py-5 sm:grid-cols-2">
                    <div>
                        <p class="mb-1 text-[11.5px] font-semibold uppercase tracking-wide text-muted">{{ t('crm.quotes.for') }}</p>
                        <p class="font-semibold">{{ quote.contact?.company_name || quote.contact?.name }}</p>
                        <p v-if="quote.contact?.company_name">{{ quote.contact.name }}</p>
                        <p v-if="quote.contact?.address" class="whitespace-pre-line">{{ Object.values(quote.contact.address).filter(Boolean).join(', ') }}</p>
                        <p v-if="quote.contact?.phone" dir="ltr" class="text-start">{{ phoneText(quote.contact.phone) }}</p>
                        <p v-if="quote.contact?.email" dir="ltr" class="text-start">{{ quote.contact.email }}</p>
                    </div>
                    <dl v-if="quote.subject || printedQuoteFields.length" class="grid content-start gap-1">
                        <div v-if="quote.subject" class="flex justify-between gap-3"><dt class="text-muted">{{ t('crm.quotes.subject') }}</dt><dd class="text-end font-medium">{{ quote.subject }}</dd></div>
                        <div v-for="field in printedQuoteFields" :key="field.key" class="flex justify-between gap-3"><dt class="text-muted">{{ field.label }}</dt><dd class="text-end">{{ fieldText(field, quote.extra[field.key], money) }}</dd></div>
                    </dl>
                </section>

                <table class="w-full">
                    <thead class="border-y border-line text-[11.5px] uppercase tracking-wide text-muted">
                        <tr>
                            <th class="py-2 pe-2 text-start font-medium">#</th>
                            <th class="py-2 pe-2 text-start font-medium">{{ t('crm.quotes.description') }}</th>
                            <th class="py-2 pe-2 text-end font-medium">{{ t('crm.quotes.quantity') }}</th>
                            <th class="py-2 pe-2 text-end font-medium">{{ t('crm.quotes.price') }}</th>
                            <th class="py-2 pe-2 text-end font-medium">{{ t('crm.quotes.vat') }}</th>
                            <th class="py-2 text-end font-medium">{{ t('crm.quotes.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="line in quote.lines" :key="line.id" class="align-top">
                            <td class="py-2 pe-2 text-muted">{{ formatNumber(line.line_no) }}</td>
                            <td class="py-2 pe-2">
                                {{ line.description }}
                                <span v-if="line.discount_minor" class="block text-[12px] text-muted">{{ t('crm.quotes.less', { amount: money(line.discount_minor) }) }}</span>
                                <span v-for="field in printedLineFields.filter((field) => line.extra[field.key] !== undefined)" :key="field.key" class="block text-[12px] text-muted">{{ field.label }}: {{ fieldText(field, line.extra[field.key], money) }}</span>
                            </td>
                            <td class="tabular py-2 pe-2 text-end whitespace-nowrap">{{ formatQuantity(line.quantity_milli) }} {{ line.unit }}</td>
                            <td class="tabular py-2 pe-2 text-end">{{ money(line.unit_price_minor) }}</td>
                            <td class="tabular py-2 pe-2 text-end">{{ line.tax_rate_bp ? `${formatNumber(line.tax_rate_bp / 100)}%` : '—' }}</td>
                            <td class="tabular py-2 text-end font-medium">{{ money(line.total_minor) }}</td>
                        </tr>
                    </tbody>
                </table>

                <dl class="ms-auto mt-4 grid w-full max-w-xs grid-cols-[1fr_auto] gap-x-6 gap-y-1 border-t border-line pt-3">
                    <dt class="text-muted">{{ t('crm.quotes.subtotal') }}</dt><dd class="tabular text-end">{{ money(quote.subtotal_minor) }}</dd>
                    <template v-if="quote.discount_minor"><dt class="text-muted">{{ t('crm.quotes.discounts') }}</dt><dd class="tabular text-end">−{{ money(quote.discount_minor) }}</dd></template>
                    <dt class="text-muted">{{ t(quote.prices_include_tax ? 'crm.quotes.vat_included' : 'crm.quotes.vat') }}</dt><dd class="tabular text-end">{{ money(quote.tax_minor) }}</dd>
                    <dt class="text-[15px] font-semibold">{{ t('crm.quotes.total') }}</dt><dd class="tabular text-end text-[15px] font-semibold">{{ money(quote.total_minor) }}</dd>
                </dl>

                <section v-if="quote.notes || quote.terms" class="mt-6 grid gap-4 border-t border-line pt-4 sm:grid-cols-2">
                    <div v-if="quote.notes"><p class="mb-1 font-semibold">{{ t('crm.quotes.notes') }}</p><p class="whitespace-pre-line">{{ quote.notes }}</p></div>
                    <div v-if="quote.terms"><p class="mb-1 font-semibold">{{ t('crm.quotes.terms') }}</p><p class="whitespace-pre-line">{{ quote.terms }}</p></div>
                </section>
            </article>

            <AppDialog :open="accepting" :title="t('crm.quotes.accept_title', { number: quote.number })" :description="t('crm.quotes.accept_text')" :icon="CheckCircle2" @close="accepting = false">
                <AppSwitch v-if="quote.can.invoice" v-model="withInvoice" :label="t('crm.quotes.make_invoice')" show-label />
                <p v-else class="text-[13px] text-muted">{{ t('crm.quotes.no_invoice') }}</p>
                <template #footer>
                    <AppButton variant="ghost" @click="accepting = false">{{ t('crm.common.cancel') }}</AppButton>
                    <AppButton variant="primary" :loading="busy === 'accept'" @click="accepting = false; step('accept', { invoice: quote.can.invoice && withInvoice })">{{ t('crm.quotes.steps.accept') }}</AppButton>
                </template>
            </AppDialog>
        </template>
    </div>
</template>

<style scoped>
@media print {
    @page {
        size: A4;
        margin: 14mm;
    }
}
</style>
