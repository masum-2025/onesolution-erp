<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, CalendarClock, CheckCircle2, Circle, FileText, Handshake, Mail, MessageSquare, Phone, PenLine, Receipt, ShieldOff, UserCheck } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { api } from '@/lib/http';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { fieldText, phoneText, quoteTone } from '../lib';
import { useCrmSetup } from '../setup';
import ActivityDialog from '../components/ActivityDialog.vue';
import ContactDialog from '../components/ContactDialog.vue';
import DealDialog from '../components/DealDialog.vue';

/**
 * One contact: who they are and how to reach them, consent, the company's
 * own fields; the history (calls, visits, notes) with open follow-ups first;
 * deals, estimates and quotations; purchases at the counters and points;
 * what they owe in the books. From here: log or plan a follow-up, add a
 * deal or quote, make them a customer in the books, or remove their
 * details on their request.
 */
const crm = crmApi(currentOrganization().id);
const route = useRoute();
const setup = useCrmSetup();
const record = useResource(() => crm.contact(route.params.id));
const contact = computed(() => record.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: setup.currency.value });
const day = (date) => (date ? formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) : '—');
const stageName = (id) => setup.data.value?.pipelines?.flatMap((pipeline) => pipeline.stages).find((stage) => stage.id === id)?.name ?? '';
const fields = computed(() => setup.fields('contact'));
const kindIcon = { call: Phone, meeting: Handshake, visit: Handshake, note: MessageSquare, task: CalendarClock, sms: MessageSquare, email: Mail };
const sales = useResource(() => (can('pos.view') && route.params.id ? api(`/api/organizations/${currentOrganization().id}/pos/sales`, { query: { customer_id: route.params.id } }).catch(() => ({ data: [] })) : Promise.resolve({ data: [] })));

const editing = ref(false);
const dealing = ref(false);
const activity = ref(null);
function changed() {
    editing.value = false;
    dealing.value = false;
    activity.value = null;
    record.reload();
}
async function toggleDone(item) {
    try {
        await crm.updateActivity(item.id, { base_version: item.version, done: !item.done_at });
        record.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
async function makeCustomer() {
    try {
        await crm.makeCustomer(contact.value.id);
        toast.success(t('crm.contact.customer_made'));
        record.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
async function anonymize() {
    const confirmed = await confirmAction({ title: t('crm.contact.remove_title'), message: t('crm.contact.remove_text'), confirmLabel: t('crm.contact.remove'), reason: 'required', danger: true });
    if (!confirmed) return;
    try {
        await crm.anonymize(contact.value.id, { base_version: contact.value.version, reason: confirmed.reason });
        toast.success(t('crm.contact.removed'));
        record.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'crm-contacts' }" :icon="ArrowLeft" class="mb-3">{{ t('crm.contacts.title') }}</AppButton>
        <SkeletonRows v-if="record.loading.value && !contact" :rows="8" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="contact">
            <!-- Who -->
            <header class="card mb-5 flex flex-wrap items-center gap-4 p-5">
                <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-brand-soft text-[22px] font-semibold text-brand-text" aria-hidden="true">{{ contact.name.slice(0, 1) }}</span>
                <div class="min-w-0 flex-1">
                    <h1 class="text-[20px] font-semibold">{{ contact.name }}</h1>
                    <p v-if="contact.company_name" class="text-[13.5px] text-muted">{{ contact.company_name }}</p>
                    <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                        <a v-if="contact.phone" :href="`tel:${contact.phone}`" class="inline-flex items-center gap-1 text-brand-text hover:underline" dir="ltr"><Phone class="size-3.5" aria-hidden="true" />{{ phoneText(contact.phone) }}</a>
                        <a v-if="contact.email" :href="`mailto:${contact.email}`" class="inline-flex items-center gap-1 text-brand-text hover:underline" dir="ltr"><Mail class="size-3.5" aria-hidden="true" />{{ contact.email }}</a>
                    </p>
                    <p class="mt-2 flex flex-wrap gap-1">
                        <AppBadge v-if="contact.anonymized" tone="neutral">{{ t('crm.contact.anonymized') }}</AppBadge>
                        <AppBadge v-for="tag in contact.tags" :key="tag" tone="neutral">{{ tag }}</AppBadge>
                        <AppBadge v-if="contact.sms_consent" tone="ok" dot>{{ t('crm.contact.sms_ok') }}</AppBadge>
                        <AppBadge v-if="contact.email_consent" tone="ok" dot>{{ t('crm.contact.email_ok') }}</AppBadge>
                    </p>
                </div>
                <div v-if="!contact.anonymized && can('crm.edit')" class="flex flex-wrap gap-2">
                    <AppButton variant="primary" :icon="CalendarClock" @click="activity = { plan: true }">{{ t('crm.activities.plan') }}</AppButton>
                    <AppButton variant="secondary" :icon="MessageSquare" @click="activity = { plan: false }">{{ t('crm.activities.log') }}</AppButton>
                    <AppButton variant="ghost" :icon="PenLine" @click="editing = true">{{ t('crm.common.edit') }}</AppButton>
                </div>
            </header>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="grid content-start gap-5">
                    <!-- History -->
                    <section class="card">
                        <h2 class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('crm.contact.history') }}</h2>
                        <p v-if="!contact.activities.length" class="px-5 py-8 text-center text-[13px] text-muted">{{ t('crm.contact.no_history') }}</p>
                        <ul v-else class="divide-y divide-line">
                            <li v-for="item in contact.activities" :key="item.id" class="flex gap-3 px-5 py-3">
                                <button v-if="item.due_at && can('crm.edit')" type="button" class="mt-0.5 shrink-0 text-muted hover:text-ok" :aria-label="t(item.done_at ? 'crm.tasks.undo' : 'crm.tasks.done')" @click="toggleDone(item)">
                                    <CheckCircle2 v-if="item.done_at" class="size-5 text-ok" aria-hidden="true" /><Circle v-else class="size-5" aria-hidden="true" />
                                </button>
                                <component :is="kindIcon[item.kind] ?? MessageSquare" v-else class="mt-0.5 size-5 shrink-0 text-muted" aria-hidden="true" />
                                <div class="min-w-0 flex-1 text-[13.5px]">
                                    <p class="font-medium" :class="item.done_at && item.due_at ? 'text-muted line-through' : ''">{{ item.subject }}</p>
                                    <p v-if="item.body" class="mt-0.5 whitespace-pre-line text-[13px] text-fg-2">{{ item.body }}</p>
                                    <p class="mt-1 text-[12px] text-muted">
                                        {{ t(`crm.activity_kinds.${item.kind}`) }} ·
                                        <template v-if="item.due_at && !item.done_at">{{ t('crm.contact.due', { when: formatDateTime(item.due_at), who: setup.personName(item.assigned_to) }) }}</template>
                                        <template v-else>{{ formatDateTime(item.done_at ?? item.created_at) }} · {{ setup.personName(item.created_by) }}</template>
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <!-- Deals -->
                    <section class="card">
                        <header class="flex items-center justify-between border-b border-line px-5 py-3">
                            <h2 class="text-[14px] font-semibold">{{ t('crm.deals.title') }}</h2>
                            <AppButton v-if="!contact.anonymized && can('crm.edit')" size="sm" variant="ghost" :icon="Handshake" @click="dealing = true">{{ t('crm.deals.add') }}</AppButton>
                        </header>
                        <p v-if="!contact.deals.length" class="px-5 py-6 text-center text-[13px] text-muted">{{ t('crm.contact.no_deals') }}</p>
                        <ul v-else class="divide-y divide-line">
                            <li v-for="deal in contact.deals" :key="deal.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-[13.5px]">
                                <span class="min-w-0 flex-1 font-medium">{{ deal.title }}</span>
                                <AppBadge :tone="{ won: 'ok', lost: 'bad' }[deal.status] ?? 'brand'">{{ stageName(deal.stage_id) }}</AppBadge>
                                <span class="tabular w-28 text-end">{{ money(deal.value_minor) }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- Quotes -->
                    <section class="card">
                        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3">
                            <h2 class="text-[14px] font-semibold">{{ t('crm.quotes.title') }}</h2>
                            <span v-if="!contact.anonymized && can('crm.edit')" class="flex gap-1">
                                <AppButton size="sm" variant="ghost" :icon="FileText" :to="{ name: 'crm-quote-new', params: { kind: 'estimate' }, query: { contact: contact.id } }">{{ t('crm.quotes.new_estimate') }}</AppButton>
                                <AppButton size="sm" variant="ghost" :icon="FileText" :to="{ name: 'crm-quote-new', params: { kind: 'quotation' }, query: { contact: contact.id } }">{{ t('crm.quotes.new_quotation') }}</AppButton>
                            </span>
                        </header>
                        <p v-if="!contact.quotes.length" class="px-5 py-6 text-center text-[13px] text-muted">{{ t('crm.contact.no_quotes') }}</p>
                        <ul v-else class="divide-y divide-line">
                            <li v-for="quote in contact.quotes" :key="quote.id">
                                <RouterLink :to="{ name: 'crm-quote', params: { id: quote.id } }" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-[13.5px] hover:bg-surface-2">
                                    <span class="min-w-0 flex-1"><span class="font-mono" dir="ltr">{{ quote.number }}</span> <span class="text-muted">· {{ day(quote.issue_date) }}</span></span>
                                    <AppBadge :tone="quoteTone(quote)">{{ t(quote.expired ? 'crm.quote_status.expired' : `crm.quote_status.${quote.status}`) }}</AppBadge>
                                    <span class="tabular w-28 text-end font-medium">{{ money(quote.total_minor) }}</span>
                                </RouterLink>
                            </li>
                        </ul>
                    </section>
                </div>

                <aside class="grid content-start gap-5">
                    <!-- Purchases and books -->
                    <section class="card p-5">
                        <h2 class="mb-3 text-[14px] font-semibold">{{ t('crm.contact.buying') }}</h2>
                        <dl class="grid grid-cols-2 gap-3 text-[13px]">
                            <div><dt class="text-muted">{{ t('crm.contact.spent') }}</dt><dd class="tabular text-[16px] font-semibold">{{ money(contact.spent_minor) }}</dd></div>
                            <div><dt class="text-muted">{{ t('crm.contact.points_label') }}</dt><dd class="tabular text-[16px] font-semibold">{{ formatNumber(contact.points) }}</dd></div>
                            <div><dt class="text-muted">{{ t('crm.contact.purchases_label') }}</dt><dd class="tabular">{{ formatNumber(contact.purchases) }}</dd></div>
                            <div><dt class="text-muted">{{ t('crm.contact.last_purchase') }}</dt><dd>{{ day(contact.last_purchase_on) }}</dd></div>
                            <div v-if="contact.books.party_id" class="col-span-2"><dt class="text-muted">{{ t('crm.contact.owes') }}</dt><dd class="tabular text-[16px] font-semibold" :class="contact.books.owed_minor > 0 ? 'text-warn' : ''">{{ money(contact.books.owed_minor) }}</dd></div>
                        </dl>
                        <ul v-if="sales.data.value?.data?.length" class="mt-3 divide-y divide-line border-t border-line text-[12.5px]">
                            <li v-for="sale in sales.data.value.data.slice(0, 5)" :key="sale.id">
                                <RouterLink :to="{ name: 'pos-sale', params: { id: sale.id } }" class="flex justify-between gap-2 py-1.5 hover:underline">
                                    <span class="font-mono" dir="ltr">{{ sale.number }}</span>
                                    <span class="tabular">{{ sale.kind === 'return' ? '−' : '' }}{{ money(sale.total_minor) }}</span>
                                </RouterLink>
                            </li>
                        </ul>
                        <AppButton v-if="!contact.books.party_id && contact.books.can_make_customer && !contact.anonymized" class="mt-3" size="sm" variant="secondary" :icon="UserCheck" block @click="makeCustomer">{{ t('crm.contact.make_customer') }}</AppButton>
                        <p v-else-if="contact.books.party_id" class="mt-3 flex items-center gap-1.5 text-[12.5px] text-ok"><Receipt class="size-3.5" aria-hidden="true" />{{ t('crm.contact.in_books') }}</p>
                    </section>

                    <!-- Details -->
                    <section class="card p-5">
                        <h2 class="mb-3 text-[14px] font-semibold">{{ t('crm.contact.details') }}</h2>
                        <dl class="grid gap-2 text-[13px]">
                            <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('crm.contacts.source') }}</dt><dd>{{ contact.source ? t(`crm.sources.${contact.source}`) : '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('crm.contacts.owner') }}</dt><dd>{{ setup.personName(contact.owner_id) || '—' }}</dd></div>
                            <div v-if="contact.consent_at" class="flex justify-between gap-3"><dt class="text-muted">{{ t('crm.contact.consent_at') }}</dt><dd>{{ formatDateTime(contact.consent_at) }}</dd></div>
                            <div v-for="field in fields" :key="field.key" class="flex justify-between gap-3"><dt class="text-muted">{{ field.label }}</dt><dd class="text-end">{{ fieldText(field, contact.extra[field.key], money) }}</dd></div>
                        </dl>
                        <AppButton v-if="can('crm.manage') && !contact.anonymized" class="mt-4" size="sm" variant="danger-soft" :icon="ShieldOff" @click="anonymize">{{ t('crm.contact.remove') }}</AppButton>
                    </section>
                </aside>
            </div>

            <ContactDialog v-if="editing" :contact="contact" @close="editing = false" @saved="changed" />
            <DealDialog v-if="dealing" :contact-id="contact.id" @close="dealing = false" @saved="changed" />
            <ActivityDialog v-if="activity" :contact-id="contact.id" :plan="activity.plan" @close="activity = null" @saved="changed" />
        </template>
    </div>
</template>
