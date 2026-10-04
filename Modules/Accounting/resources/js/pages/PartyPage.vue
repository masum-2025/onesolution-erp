<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Banknote, FilePlus, PenLine, Printer } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { can, currentOrganization, session } from '@/lib/session';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { documentTone, isCredit, todayIn } from '../lib';
import PartyDialog from '../components/PartyDialog.vue';

/**
 * One customer or vendor: what they owe (or are owed), their documents,
 * and a printable statement for any range.
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();

const entry = useResource(() => books.party(route.params.id));
const party = computed(() => entry.data.value?.data ?? null);
const setup = useResource(() => books.setup());
const currency = computed(() => setup.data.value?.data.currency);
const money = (amount) => formatMoney({ amount, currency: currency.value });
const editing = ref(false);

const side = ref('sales');
watch(party, (value) => {
    if (value && !value.is_customer) side.value = 'purchases';
});
const tab = ref('documents');
const tabs = computed(() => [
    { key: 'documents', label: t('accounting.parties.documents') },
    { key: 'statement', label: t('accounting.parties.statement') },
]);

const documents = useResource(() => books.documents({ party_id: route.params.id, side: side.value, per_page: 50 }));
const today = todayIn(session.me?.context?.settings?.timezone);
// This calendar year so far by default.
const range = reactive({ from: `${today.slice(0, 4)}-01-01`, to: today });
const statement = useResource(() => books.report('statement', { side: side.value, party_id: route.params.id, from: range.from, to: range.to }), { immediate: false });
watch([tab, side], () => {
    if (tab.value === 'statement') statement.reload();
    else documents.reload();
});

function printPage() {
    window.print();
}

const kinds = computed(() => (side.value === 'sales' ? ['invoice', 'credit_note'] : ['bill', 'vendor_credit']));
</script>

<template>
    <div>
        <PageHeader :title="party?.name ?? ''" :description="party ? [party.code, party.phone, party.email].filter(Boolean).join(' · ') : ''">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: party?.is_customer ? 'accounting-customers' : 'accounting-vendors' }" :icon="ArrowLeft" class="print:hidden">{{ t('accounting.common.back') }}</AppButton>
                <AppButton v-if="party && (can('accounting.sell') || can('accounting.buy'))" :icon="PenLine" class="print:hidden" @click="editing = true">{{ t('accounting.parties.edit') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="entry.loading.value && !party" class="card"><SkeletonRows :rows="5" /></section>
        <section v-else-if="entry.error.value" class="card"><ErrorState compact :error="entry.error.value" @retry="entry.reload()" /></section>

        <div v-else-if="party" class="grid gap-5">
            <section class="grid gap-3 sm:grid-cols-2 print:hidden">
                <div v-if="party.is_customer" class="card p-5">
                    <div class="text-[12.5px] text-muted">{{ party.balances.sales >= 0 ? t('accounting.parties.owes') : t('accounting.parties.advance') }}</div>
                    <div class="tabular mt-1 text-[22px] font-semibold" :class="party.balances.sales > 0 ? 'text-fg' : 'text-ok'">{{ money(Math.abs(party.balances.sales)) }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <AppButton v-if="can('accounting.sell')" size="sm" :icon="FilePlus" :to="{ name: 'accounting-document-new', query: { type: 'invoice', party_id: party.id } }">{{ t('accounting.parties.new_invoice') }}</AppButton>
                        <AppButton v-if="can('accounting.sell')" size="sm" :icon="Banknote" :to="{ name: 'accounting-settlement-new', query: { type: 'receipt', party_id: party.id } }">{{ t('accounting.parties.receive') }}</AppButton>
                    </div>
                </div>
                <div v-if="party.is_vendor" class="card p-5">
                    <div class="text-[12.5px] text-muted">{{ t('accounting.parties.owed') }}</div>
                    <div class="tabular mt-1 text-[22px] font-semibold">{{ money(party.balances.purchases) }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <AppButton v-if="can('accounting.buy')" size="sm" :icon="FilePlus" :to="{ name: 'accounting-document-new', query: { type: 'bill', party_id: party.id } }">{{ t('accounting.parties.new_bill') }}</AppButton>
                        <AppButton v-if="can('accounting.buy')" size="sm" :icon="Banknote" :to="{ name: 'accounting-settlement-new', query: { type: 'payment', party_id: party.id } }">{{ t('accounting.parties.pay') }}</AppButton>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-end justify-between gap-3 print:hidden">
                <AppTabs v-model="tab" :tabs="tabs" :label="party.name" />
                <select v-if="party.is_customer && party.is_vendor" v-model="side" class="field-input w-auto" :aria-label="t('accounting.aging.side')">
                    <option value="sales">{{ t('accounting.parties.is_customer') }}</option>
                    <option value="purchases">{{ t('accounting.parties.is_vendor') }}</option>
                </select>
            </div>

            <section v-if="tab === 'documents'" class="card">
                <SkeletonRows v-if="documents.loading.value && !documents.data.value" :rows="5" />
                <ErrorState v-else-if="documents.error.value" compact :error="documents.error.value" @retry="documents.reload()" />
                <p v-else-if="!documents.data.value?.data.length" class="px-5 py-6 text-center text-[13px] text-muted">{{ t('accounting.documents.empty_title') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="document in documents.data.value.data" :key="document.id">
                        <RouterLink :to="{ name: 'accounting-document', params: { id: document.id } }" class="flex items-center gap-3 px-5 py-3 hover:bg-subtle/60">
                            <span class="min-w-0 flex-1">
                                <span class="block text-[13.5px] font-medium">{{ t(`accounting.kinds.${document.type}`) }} <span class="font-mono" dir="ltr">{{ document.number ?? '' }}</span></span>
                                <span class="block text-[12px] text-muted">{{ formatDate(document.issue_date) }}</span>
                            </span>
                            <span class="tabular text-[13.5px]">{{ money(isCredit(document.type) ? -document.total_minor : document.total_minor) }}</span>
                            <AppBadge :tone="documentTone(document.status)" dot>{{ t(`accounting.doc_statuses.${document.status}`) }}</AppBadge>
                        </RouterLink>
                    </li>
                </ul>
                <div class="flex flex-wrap gap-2 border-t border-line px-5 py-3">
                    <template v-for="kind in kinds" :key="kind">
                        <AppButton v-if="can(side === 'sales' ? 'accounting.sell' : 'accounting.buy')" size="sm" variant="ghost" :icon="FilePlus" :to="{ name: 'accounting-document-new', query: { type: kind, party_id: party.id } }">
                            {{ t(`accounting.documents.new_${kind}`) }}
                        </AppButton>
                    </template>
                </div>
            </section>

            <section v-else class="card overflow-x-auto">
                <form class="flex flex-wrap items-end gap-3 border-b border-line px-5 py-4 print:hidden" @submit.prevent="statement.reload()">
                    <AppField v-slot="{ id }" :label="t('accounting.reports.from')"><input :id="id" v-model="range.from" type="date" class="field-input" /></AppField>
                    <AppField v-slot="{ id }" :label="t('accounting.reports.to')"><input :id="id" v-model="range.to" type="date" class="field-input" :min="range.from" /></AppField>
                    <AppButton variant="primary" type="submit" :loading="statement.loading.value">{{ t('accounting.reports.show') }}</AppButton>
                    <AppButton :icon="Printer" :disabled="!statement.data.value" @click="printPage">{{ t('accounting.parties.print') }}</AppButton>
                </form>
                <ErrorState v-if="statement.error.value" compact :error="statement.error.value" @retry="statement.reload()" />
                <template v-else-if="statement.data.value">
                    <header class="px-5 pt-4">
                        <h2 class="text-[15px] font-semibold">{{ t('accounting.parties.statement') }} · {{ party.name }}</h2>
                        <p class="text-[12.5px] text-muted">{{ t('accounting.reports.period', { from: formatDate(statement.data.value.data.from), to: formatDate(statement.data.value.data.to) }) }}</p>
                    </header>
                    <table class="mt-2 w-full min-w-[34rem] text-[13.5px]">
                        <thead class="border-b border-line text-[12px] text-muted">
                            <tr>
                                <th class="px-5 py-2 text-start font-medium">{{ t('accounting.reports.date') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('accounting.reports.narration') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ t('accounting.reports.amount') }}</th>
                                <th class="px-5 py-2 text-end font-medium">{{ t('accounting.reports.balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr class="bg-subtle/50">
                                <td class="px-5 py-2 font-medium" colspan="3">{{ t('accounting.reports.opening') }}</td>
                                <td class="tabular px-5 py-2 text-end font-medium">{{ money(statement.data.value.data.opening_minor) }}</td>
                            </tr>
                            <tr v-for="row in statement.data.value.data.rows" :key="row.id">
                                <td class="whitespace-nowrap px-5 py-2">{{ formatDate(row.date) }}</td>
                                <td class="px-3 py-2">{{ t(`accounting.kinds.${row.kind}`) }} <span class="font-mono" dir="ltr">{{ row.number }}</span><span v-if="row.reference" class="text-muted"> · {{ row.reference }}</span></td>
                                <td class="tabular px-3 py-2 text-end">{{ money(row.amount_minor) }}</td>
                                <td class="tabular px-5 py-2 text-end">{{ money(row.balance_minor) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-line font-semibold">
                            <tr>
                                <td class="px-5 py-2.5" colspan="3">{{ t('accounting.reports.closing') }}</td>
                                <td class="tabular px-5 py-2.5 text-end">{{ money(statement.data.value.data.closing_minor) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </template>
                <SkeletonRows v-else :rows="4" />
            </section>
        </div>

        <PartyDialog :open="editing" :party="party" :role="party?.is_customer ? 'customers' : 'vendors'" @close="editing = false" @saved="entry.reload()" />
    </div>
</template>
