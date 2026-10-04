<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Banknote, Ban, BookOpen, Check, PenLine, Printer, Send, Trash2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { brand } from '@/lib/brand';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { daysUntilDue, documentTone, isCredit, sideOf, statusKey, todayIn } from '../lib';
import AllocationPicker from '../components/AllocationPicker.vue';

/**
 * One invoice, credit note, bill or vendor credit: a printable layout with
 * the company's name, the party, lines and totals; what paid it; and the
 * steps the reader may take (from the server's "can"; checked again there).
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();
const router = useRouter();

const entry = useResource(() => books.document(route.params.id));
const document = computed(() => entry.data.value?.data ?? null);
watch(() => route.params.id, (id) => id && entry.reload());
const party = useResource(() => (document.value ? books.party(document.value.party_id) : Promise.resolve(null)), { immediate: false });
watch(() => document.value?.party_id, (id) => id && party.reload());

const money = (amount) => formatMoney({ amount, currency: document.value?.currency });
const kind = computed(() => (document.value ? t(`accounting.kinds.${document.value.type}`) : ''));
const today = todayIn(session.me?.context?.settings?.timezone);
const busy = ref(null);

const dueText = computed(() => {
    const value = document.value;
    if (!value || isCredit(value.type) || !['posted', 'partly_paid'].includes(value.status)) return '';
    const days = daysUntilDue(value.due_date, today);
    if (days === 0) return t('accounting.documents.due_today');
    return days > 0 ? t('accounting.documents.due_in', { days: formatNumber(days) }) : t('accounting.documents.overdue_by', { days: formatNumber(-days) });
});

async function step(name, body = {}) {
    busy.value = name;
    try {
        const { data } = await books.documentStep(document.value.id, name, { base_version: document.value.version, ...body });
        toast.success(t(`accounting.documents.done.${name}`));
        entry.data.value = { data };
        return true;
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('accounting.common.conflict'));
            entry.reload();
            return true;
        }
        if (error.errors) return error.errors;
        toast.error(error.message);
        return true;
    } finally {
        busy.value = null;
    }
}

async function approve() {
    const confirmed = await confirmAction({ title: t('accounting.journal.approve_title'), message: `${kind.value} · ${money(document.value.total_minor)}`, confirmLabel: t('accounting.journal.steps.approve') });
    if (confirmed) step('approve');
}

async function withReason(name) {
    const confirmed = await confirmAction({
        title: name === 'void' ? t('accounting.documents.void_title', { number: document.value.number }) : t('accounting.journal.reject_title'),
        message: name === 'void' ? t('accounting.documents.void_text') : t('accounting.journal.reject_text'),
        confirmLabel: t(name === 'void' ? 'accounting.journal.steps.void' : 'accounting.journal.steps.reject'),
        reason: 'required',
        danger: true,
    });
    if (confirmed) step(name, { reason: confirmed.reason });
}

async function remove() {
    const confirmed = await confirmAction({ title: t('accounting.documents.delete_title'), message: t('accounting.documents.delete_text'), confirmLabel: t('accounting.journal.delete'), danger: true });
    if (!confirmed) return;
    try {
        await books.deleteDocument(document.value.id, document.value.version);
        toast.success(t('accounting.documents.deleted'));
        router.push({ name: sideOf(document.value.type) === 'sales' ? 'accounting-sales' : 'accounting-purchases' });
    } catch (error) {
        toast.error(error.message);
        entry.reload();
    }
}

// Applying a credit: the party's open invoices (or bills).
const applying = ref(false);
const openDocuments = ref([]);
const shares = ref({});
const picker = ref(null);
const applyErrors = ref({});
async function openApply() {
    const owed = document.value.type === 'credit_note' ? 'invoice' : 'bill';
    openDocuments.value = (await books.documents({ party_id: document.value.party_id, type: owed, status: 'open', per_page: 100 })).data;
    shares.value = {};
    applyErrors.value = {};
    applying.value = true;
}
async function apply() {
    const result = await step('apply', { allocations: picker.value.payload() });
    if (result === true) applying.value = false;
    else applyErrors.value = result;
}

function printPage() {
    window.print();
}
</script>

<template>
    <div>
        <PageHeader :title="document ? `${kind} ${document.number ?? ''}` : ''" :description="document?.party_name ?? ''" class="print:hidden">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: document && sideOf(document.type) === 'purchases' ? 'accounting-purchases' : 'accounting-sales' }" :icon="ArrowLeft">{{ t('accounting.common.back') }}</AppButton>
                <AppButton v-if="document?.number" :icon="Printer" @click="printPage">{{ t('accounting.documents.print') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="entry.loading.value && !document" class="card"><SkeletonRows :rows="6" /></section>
        <section v-else-if="entry.error.value" class="card"><ErrorState compact :error="entry.error.value" @retry="entry.reload()" /></section>

        <div v-else-if="document" class="grid gap-5">
            <div class="flex flex-wrap items-center gap-2 print:hidden">
                <AppBadge :tone="documentTone(document.status)" dot>{{ t(statusKey(document.type, document.status)) }}</AppBadge>
                <AppBadge v-if="document.is_opening" tone="neutral">{{ t('accounting.opening.title') }}</AppBadge>
                <span v-if="dueText" class="text-[13px]" :class="daysUntilDue(document.due_date, today) < 0 ? 'text-bad' : 'text-muted'">{{ dueText }}</span>
                <span v-if="document.reject_reason && document.status === 'rejected'" class="rounded-lg bg-bad-soft px-3 py-1 text-[13px] text-bad">{{ t('accounting.journal.rejected', { reason: document.reject_reason }) }}</span>
                <span v-if="document.void_reason" class="rounded-lg bg-subtle px-3 py-1 text-[13px] text-muted">{{ document.void_reason }}</span>
            </div>

            <!-- The document itself: what the customer receives (prints on its own). -->
            <article class="card p-6 sm:p-8 print:border-0 print:p-0 print:shadow-none">
                <header class="flex flex-wrap items-start justify-between gap-4 border-b border-line pb-5">
                    <div class="flex items-center gap-3">
                        <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-10 w-auto" />
                        <div>
                            <div class="text-[16px] font-semibold">{{ org.name }}</div>
                            <div class="text-[12.5px] text-muted">{{ brand.name }}</div>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="text-[20px] font-semibold uppercase tracking-wide text-brand-text">{{ kind }}</div>
                        <div class="font-mono text-[13px]" dir="ltr">{{ document.number ?? '—' }}</div>
                    </div>
                </header>

                <div class="grid gap-4 py-5 sm:grid-cols-2">
                    <div>
                        <div class="text-[12px] uppercase tracking-wide text-muted">{{ sideOf(document.type) === 'sales' ? t('accounting.documents.bill_to') : t('accounting.documents.from') }}</div>
                        <div class="mt-1 text-[14px] font-medium">{{ document.party_name }}</div>
                        <div v-if="party.data.value" class="text-[12.5px] text-muted">
                            <div v-if="party.data.value.data.address">{{ [party.data.value.data.address.line1, party.data.value.data.address.city].filter(Boolean).join(', ') }}</div>
                            <div>{{ [party.data.value.data.phone, party.data.value.data.email].filter(Boolean).join(' · ') }}</div>
                        </div>
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[13px] sm:justify-self-end">
                        <dt class="text-muted">{{ t('accounting.documents.issue_date') }}</dt><dd>{{ formatDate(document.issue_date) }}</dd>
                        <template v-if="!isCredit(document.type)"><dt class="text-muted">{{ t('accounting.documents.due_date') }}</dt><dd>{{ formatDate(document.due_date) }}</dd></template>
                        <template v-if="document.reference"><dt class="text-muted">{{ t('accounting.documents.reference') }}</dt><dd>{{ document.reference }}</dd></template>
                    </dl>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-[13.5px]">
                        <thead class="border-y border-line text-[12px] text-muted">
                            <tr>
                                <th class="py-2 pe-3 text-start font-medium">{{ t('accounting.documents.description') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('accounting.documents.quantity') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('accounting.documents.price') }}</th>
                                <th class="py-2 ps-3 text-end font-medium">{{ t('accounting.documents.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="line in document.lines" :key="line.line_no">
                                <td class="py-2 pe-3">{{ line.description }}<span class="block text-[11.5px] text-muted print:hidden">{{ line.account_code }} · {{ line.account_name }}<template v-if="line.tax_minor"> · {{ t('accounting.tax.vat') }} {{ money(line.tax_minor) }}</template></span></td>
                                <td class="tabular px-3 py-2 text-end">{{ formatNumber(line.quantity) }}</td>
                                <td class="tabular px-3 py-2 text-end">{{ money(line.unit_price_minor) }}</td>
                                <td class="tabular py-2 ps-3 text-end">{{ money(line.amount_minor) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <dl class="ms-auto mt-4 grid max-w-xs grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-[14px]">
                    <template v-if="document.tax_minor">
                        <dt class="text-muted">{{ t('accounting.tax.net') }}</dt><dd class="tabular text-end">{{ money(document.net_minor) }}</dd>
                        <dt class="text-muted">{{ t('accounting.tax.vat') }}</dt><dd class="tabular text-end">{{ money(document.tax_minor) }}</dd>
                    </template>
                    <dt class="font-semibold">{{ t('accounting.documents.total') }}</dt><dd class="tabular text-end font-semibold">{{ money(document.total_minor) }}</dd>
                    <template v-if="!isCredit(document.type) && document.allocated_minor > 0">
                        <dt class="text-muted">{{ t('accounting.documents.paid') }}</dt><dd class="tabular text-end">{{ money(document.allocated_minor) }}</dd>
                        <dt class="font-semibold">{{ t('accounting.documents.balance') }}</dt><dd class="tabular text-end font-semibold">{{ money(document.balance_minor) }}</dd>
                    </template>
                </dl>
                <p v-if="document.notes" class="mt-6 border-t border-line pt-4 text-[13px] text-muted">{{ document.notes }}</p>
            </article>

            <section v-if="document.allocations.length || document.status === 'posted' || document.status === 'partly_paid'" class="card print:hidden">
                <header class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('accounting.documents.payments') }}</header>
                <p v-if="!document.allocations.length" class="px-5 py-4 text-[13px] text-muted">{{ t('accounting.documents.no_payments') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="(allocation, index) in document.allocations" :key="index" class="flex items-center justify-between px-5 py-2.5 text-[13.5px]">
                        <RouterLink
                            v-if="allocation.settlement_id || allocation.credit_document_id || allocation.document_id"
                            class="font-medium text-brand-strong hover:underline"
                            :to="allocation.settlement_id ? { name: 'accounting-settlement', params: { id: allocation.settlement_id } } : { name: 'accounting-document', params: { id: isCredit(document.type) ? allocation.document_id : allocation.credit_document_id } }"
                        >
                            {{ formatDate(allocation.allocated_on) }}
                        </RouterLink>
                        <span class="tabular">{{ money(allocation.amount_minor) }}</span>
                    </li>
                </ul>
            </section>

            <div class="flex flex-wrap justify-end gap-2 print:hidden">
                <AppButton v-if="document.journal_id" variant="ghost" :icon="BookOpen" :to="{ name: 'accounting-journal', params: { id: document.journal_id } }">{{ t('accounting.documents.journal') }}</AppButton>
                <AppButton v-if="document.can.edit" variant="danger-soft" :icon="Trash2" @click="remove">{{ t('accounting.journal.delete') }}</AppButton>
                <AppButton v-if="document.can.edit" :icon="PenLine" :to="{ name: 'accounting-document-edit', params: { id: document.id } }">{{ t('accounting.journal.edit') }}</AppButton>
                <AppButton v-if="document.can.submit" variant="primary" :icon="Send" :loading="busy === 'submit'" @click="step('submit')">{{ t('accounting.journal.steps.submit') }}</AppButton>
                <AppButton v-if="document.can.reject" variant="danger-soft" :icon="X" @click="withReason('reject')">{{ t('accounting.journal.steps.reject') }}</AppButton>
                <AppButton v-if="document.can.approve" variant="primary" :icon="Check" :loading="busy === 'approve'" @click="approve">{{ t('accounting.journal.steps.approve') }}</AppButton>
                <AppButton v-if="document.can.void" variant="danger-soft" :icon="Ban" @click="withReason('void')">{{ t('accounting.journal.steps.void') }}</AppButton>
                <AppButton v-if="document.can.apply" variant="primary" @click="openApply">{{ t('accounting.documents.apply') }}</AppButton>
                <AppButton
                    v-if="!isCredit(document.type) && ['posted', 'partly_paid'].includes(document.status) && can(sideOf(document.type) === 'sales' ? 'accounting.sell' : 'accounting.buy')"
                    variant="primary"
                    :icon="Banknote"
                    :to="{ name: 'accounting-settlement-new', query: { type: sideOf(document.type) === 'sales' ? 'receipt' : 'payment', party_id: document.party_id, document_id: document.id } }"
                >
                    {{ sideOf(document.type) === 'sales' ? t('accounting.documents.receive') : t('accounting.documents.pay') }}
                </AppButton>
            </div>
        </div>

        <AppDialog :open="applying" :title="t('accounting.documents.apply_title')" :description="t('accounting.documents.apply_text', { party: document?.party_name ?? '' })" size="lg" @close="applying = false">
            <AllocationPicker
                ref="picker"
                v-model="shares"
                :documents="openDocuments"
                :available="document?.balance_minor ?? 0"
                :currency="document?.currency ?? 'BDT'"
                :errors="applyErrors"
            />
            <template #footer>
                <AppButton variant="ghost" @click="applying = false">{{ t('accounting.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy === 'apply'" @click="apply">{{ t('accounting.documents.apply') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
