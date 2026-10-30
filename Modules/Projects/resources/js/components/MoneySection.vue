<script setup>
import { computed, ref, watch } from 'vue';
import { FilePlus, Receipt, UserPlus } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { formatDate, formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import BillDialog from './BillDialog.vue';

/**
 * A project's money, as CRM and Accounting have it now: its quotes and
 * invoices (each opens in its own module), what was quoted, invoiced and
 * is still owed. Hidden when neither module is used here.
 */
const props = defineProps({
    project: { type: Object, required: true },
    today: { type: String, default: '' },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['client-changed']);

const money = ref(null);
const error = ref(null);
const dialog = ref({ open: false, mode: 'quote' });
const busy = ref(false);
const currency = computed(() => money.value?.totals.currency_code ?? currentOrganization()?.settings?.currency_code ?? null);

async function load() {
    error.value = null;
    try {
        money.value = (await props.calls.money(props.project.id)).data;
    } catch (caught) {
        error.value = caught;
    }
}
watch(() => [props.project.id, props.project.version], load, { immediate: true });

const shown = computed(() => money.value && (money.value.state.crm || money.value.state.books || money.value.quotes.length || money.value.invoices.length));
// Why a button is missing, in words.
const why = computed(() => {
    const state = money.value?.state;
    if (!state) return null;
    if (!state.client_in_crm) return state.crm ? 'projects.money.why_client' : 'projects.money.why_crm_client';
    if (!state.books) return 'projects.money.why_books';
    // Everything is in place, yet a button is missing: the person's own permissions (CRM's or Accounting's).
    if (!money.value.can.quote || !money.value.can.invoice) return 'projects.money.why_permission';
    return null;
});
const tone = (status) => ({ draft: 'neutral', sent: 'warn', accepted: 'ok', declined: 'bad', expired: 'outline', converted: 'outline', posted: 'warn', partly_paid: 'warn', paid: 'ok', void: 'outline', pending_approval: 'neutral', rejected: 'bad' }[status] ?? 'neutral');

async function clientToCrm() {
    busy.value = true;
    try {
        await props.calls.clientToCrm(props.project.id, props.project.version);
        toast.success(t('projects.money.client_added'));
        emit('client-changed');
    } catch (caught) {
        toast.error(caught.message);
    } finally {
        busy.value = false;
    }
}
function saved() {
    dialog.value.open = false;
    load();
}
</script>

<template>
    <section v-if="error || !money || shown" class="card mb-5" :aria-label="t('projects.money.title')">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3">
            <h2 class="text-[14.5px] font-semibold">{{ t('projects.money.title') }}</h2>
            <span v-if="money" class="flex flex-wrap gap-2">
                <AppButton v-if="money.can.client_to_crm" size="sm" :icon="UserPlus" :loading="busy" @click="clientToCrm">{{ t('projects.money.client_to_crm') }}</AppButton>
                <AppButton v-if="money.can.quote" size="sm" :icon="FilePlus" @click="dialog = { open: true, mode: 'quote' }">{{ t('projects.money.new_quote') }}</AppButton>
                <AppButton v-if="money.can.invoice" size="sm" variant="primary" :icon="Receipt" @click="dialog = { open: true, mode: 'invoice' }">{{ t('projects.money.new_invoice') }}</AppButton>
            </span>
        </header>
        <ErrorState v-if="error" compact :error="error" @retry="load" />
        <SkeletonRows v-else-if="!money" class="px-5 py-4" :rows="2" />
        <div v-else class="space-y-4 px-5 py-4">
            <dl class="grid grid-cols-3 gap-3 text-[13px]">
                <div class="rounded-xl bg-subtle px-3 py-2"><dt class="text-muted">{{ t('projects.money.quoted') }}</dt><dd class="tabular text-[15px] font-semibold">{{ formatMoney({ amount: money.totals.quoted, currency }) }}</dd></div>
                <div class="rounded-xl bg-subtle px-3 py-2"><dt class="text-muted">{{ t('projects.money.invoiced') }}</dt><dd class="tabular text-[15px] font-semibold">{{ formatMoney({ amount: money.totals.invoiced, currency }) }}</dd></div>
                <div class="rounded-xl bg-subtle px-3 py-2"><dt class="text-muted">{{ t('projects.money.outstanding') }}</dt>
                    <dd class="tabular text-[15px] font-semibold" :class="money.totals.outstanding > 0 ? 'text-warn' : ''">{{ formatMoney({ amount: money.totals.outstanding, currency }) }}</dd></div>
            </dl>
            <p v-if="why" class="text-[12.5px] text-muted">{{ t(why) }}</p>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div>
                    <h3 class="mb-1.5 text-[13px] font-semibold text-fg-2">{{ t('projects.money.quotes') }}</h3>
                    <p v-if="!money.quotes.length" class="text-[12.5px] text-muted">{{ t('projects.money.no_quotes') }}</p>
                    <ul v-else class="divide-y divide-line rounded-xl border border-line">
                        <li v-for="quote in money.quotes" :key="quote.id">
                            <RouterLink :to="{ name: 'crm-quote', params: { id: quote.id } }" class="flex items-center gap-2 px-3 py-2 text-[13px] hover:bg-subtle">
                                <span class="min-w-0 flex-1 truncate"><span class="font-mono" dir="ltr">{{ quote.number }}</span> · {{ t(`projects.money.${quote.kind}`) }}<span class="text-muted"> · {{ formatDate(quote.issue_date) }}</span></span>
                                <span class="tabular">{{ formatMoney({ amount: quote.total_minor, currency: quote.currency_code }) }}</span>
                                <AppBadge :tone="tone(quote.status)">{{ t(`projects.money.statuses.${quote.status}`) }}</AppBadge>
                            </RouterLink>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="mb-1.5 text-[13px] font-semibold text-fg-2">{{ t('projects.money.invoices') }}</h3>
                    <p v-if="!money.invoices.length" class="text-[12.5px] text-muted">{{ t('projects.money.no_invoices') }}</p>
                    <ul v-else class="divide-y divide-line rounded-xl border border-line">
                        <li v-for="invoice in money.invoices" :key="invoice.id">
                            <RouterLink :to="{ name: 'accounting-document', params: { id: invoice.id } }" class="flex items-center gap-2 px-3 py-2 text-[13px] hover:bg-subtle">
                                <span class="min-w-0 flex-1 truncate"><span class="font-mono" dir="ltr">{{ invoice.number ?? t('projects.money.draft_no_number') }}</span><span class="text-muted"> · {{ formatDate(invoice.issue_date) }}</span></span>
                                <span class="tabular">{{ formatMoney({ amount: invoice.total_minor, currency: invoice.currency_code }) }}</span>
                                <AppBadge :tone="tone(invoice.status)">{{ t(`projects.money.statuses.${invoice.status}`) }}</AppBadge>
                            </RouterLink>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <BillDialog :open="dialog.open" :mode="dialog.mode" :project="project" :currency="currency" :today="today" :calls="calls" @close="dialog.open = false" @saved="saved" />
    </section>
</template>
