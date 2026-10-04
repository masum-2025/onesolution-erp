<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { ArrowLeft, Check, FileText, Plus, Save, Send, Trash2, Undo2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { accountTree, openingForm, openingPayload, openingTotals, postableAccounts, statusTone } from '../lib';
import BooksGate from '../components/BooksGate.vue';

/**
 * The balances a company brings from its old books, entered once. Accounts
 * carry a debit or a credit; what customers still owe and what vendors are
 * still owed goes in per old invoice or bill (so aging and receipts work).
 * Whatever does not balance goes to the opening balance account, shown
 * before posting. Above the approval amount a second person approves.
 */
const org = currentOrganization();
const books = accountingApi(org.id);

const loading = ref(true);
const loadError = ref(null);
const busy = ref(null);
const errors = ref({});
const opening = ref(null);
const meta = ref({});
const accounts = ref([]);
const customers = ref([]);
const vendors = ref([]);
const worked = ref(new Set());

const blankAccount = () => ({ account_id: '', debit: '', credit: '' });
const blankParty = () => ({ party_id: '', reference: '', issue_date: '', due_date: '', amount: '' });
const form = reactive({ opening_date: '', accounts: [blankAccount()], customers: [], vendors: [] });

const currency = computed(() => meta.value.currency ?? '');
const money = (amount) => formatMoney({ amount, currency: currency.value });
const writable = computed(() => meta.value.can_write && (opening.value === null || opening.value.can.edit));
const totals = computed(() => (writable.value ? openingTotals(form, currency.value) : {
    debit: opening.value?.debit_minor ?? 0, credit: opening.value?.credit_minor ?? 0, difference: opening.value?.difference_minor ?? 0, invalid: false,
}));
// Receivable, payable and opening balance accounts are worked out from the other lines.
const accountChoices = computed(() => {
    const usable = new Set(postableAccounts(accounts.value).filter((account) => !worked.value.has(account.id)).map((account) => account.id));
    return accountTree(accounts.value).flat.filter((account) => usable.has(account.id));
});
const hasLines = computed(() => openingPayload(form, currency.value).lines.length > 0);

onMounted(load);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [current, chart, postings, customerList, vendorList] = await Promise.all([
            books.opening(),
            books.accounts(),
            books.postingAccounts(),
            books.parties({ role: 'customers', per_page: 100 }),
            books.parties({ role: 'vendors', per_page: 100 }),
        ]);
        opening.value = current.data;
        meta.value = current.meta;
        accounts.value = chart.data;
        customers.value = customerList.data;
        vendors.value = vendorList.data;
        worked.value = new Set(postings.data
            .filter((posting) => ['accounting.receivable', 'accounting.payable', 'accounting.opening_balance'].includes(posting.key))
            .map((posting) => posting.account_id)
            .filter(Boolean));

        const rows = current.data ? openingForm(current.data, current.meta.currency) : null;
        Object.assign(form, {
            opening_date: rows?.opening_date || current.meta.suggested_date || '',
            accounts: rows?.accounts.length ? rows.accounts : [blankAccount()],
            customers: rows?.customers ?? [],
            vendors: rows?.vendors ?? [],
        });
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}

function removeRow(list, index, blank) {
    list.splice(index, 1);
    if (!list.length && blank) list.push(blank());
}

// Empty rows go before saving, so the server's line numbers match the rows on screen.
function compact() {
    form.accounts = form.accounts.filter((row) => row.account_id || row.debit.trim() || row.credit.trim());
    form.customers = form.customers.filter((row) => row.party_id || row.amount.trim());
    form.vendors = form.vendors.filter((row) => row.party_id || row.amount.trim());
}

async function save(send) {
    compact();
    busy.value = send ? 'send' : 'draft';
    errors.value = {};
    try {
        let saved = (await books.saveOpening({ ...openingPayload(form, currency.value), ...(opening.value ? { base_version: opening.value.version } : {}) })).data;
        if (send) saved = (await books.openingStep('submit', { base_version: saved.version })).data;
        opening.value = saved;
        toast.success(t(saved.status === 'posted' ? 'accounting.opening.posted_done' : (saved.status === 'pending_approval' ? 'accounting.opening.pending_done' : 'accounting.opening.saved')));
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('accounting.common.conflict'));
            load();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
        else toast.error(t('accounting.opening.check_lines'));
    } finally {
        busy.value = null;
        if (!form.accounts.length) form.accounts.push(blankAccount());
    }
}

async function remove() {
    const confirmed = await confirmAction({ title: t('accounting.opening.delete_title'), message: t('accounting.opening.delete_text'), confirmLabel: t('accounting.opening.delete'), danger: true });
    if (!confirmed) return;
    busy.value = 'delete';
    try {
        await books.deleteOpening(opening.value.version);
        toast.success(t('accounting.opening.deleted'));
        load();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function step(name) {
    const confirmed = await confirmAction({
        title: t(`accounting.opening.${name}_title`),
        message: name === 'approve' ? t('accounting.opening.approve_text') : '',
        confirmLabel: t(`accounting.opening.${name}`),
        reason: name === 'reject' ? 'required' : 'none',
        danger: name === 'reject',
    });
    if (!confirmed) return;
    busy.value = name;
    try {
        opening.value = (await books.openingStep(name, { base_version: opening.value.version, ...(name === 'reject' ? { reason: confirmed.reason } : {}) })).data;
        toast.success(t(`accounting.opening.${name}_done`));
        if (name !== 'approve') load();
    } catch (error) {
        if (error.code === 'version_conflict') load();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
// The first message about a row (the server numbers lines: accounts, then customers, then vendors).
function rowError(section, index) {
    const offset = { accounts: 0, customers: form.accounts.length, vendors: form.accounts.length + form.customers.length }[section];
    const prefix = `lines.${offset + index}.`;
    const key = Object.keys(errors.value).find((name) => name.startsWith(prefix));
    return key ? errors.value[key][0] : null;
}

const partyLabel = (line) => line.party_name ?? '—';
const kindLines = (kind) => (opening.value?.lines ?? []).filter((line) => line.kind === kind);
</script>

<template>
    <div>
        <PageHeader :title="t('accounting.opening.title')" :description="t('accounting.opening.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'accounting-fiscal-years' }" :icon="ArrowLeft">{{ t('accounting.common.back') }}</AppButton>
                <AppButton v-if="opening?.journal_id" variant="secondary" :icon="FileText" :to="{ name: 'accounting-journal', params: { id: opening.journal_id } }">{{ t('accounting.opening.open_journal') }}</AppButton>
            </template>
        </PageHeader>

        <BooksGate>
            <section v-if="loading" class="card"><SkeletonRows :rows="6" /></section>
            <section v-else-if="loadError" class="card"><ErrorState compact :error="loadError" @retry="load" /></section>

            <div v-else class="grid gap-5">
                <!-- Where things stand -->
                <section v-if="opening" class="card flex flex-wrap items-center gap-3 px-5 py-3" role="status">
                    <AppBadge :tone="statusTone(opening.status)" dot>{{ t(`accounting.statuses.${opening.status}`) }}</AppBadge>
                    <p class="min-w-0 flex-1 text-[13px] text-muted">
                        <template v-if="opening.status === 'posted'">{{ t('accounting.opening.posted_text', { date: formatDate(opening.opening_date) }) }}</template>
                        <template v-else-if="opening.status === 'pending_approval'">{{ t('accounting.opening.pending_text') }}</template>
                        <template v-else-if="opening.status === 'rejected'">{{ t('accounting.opening.rejected_text', { reason: opening.reject_reason }) }}</template>
                        <template v-else>{{ t('accounting.opening.draft_text') }}</template>
                    </p>
                    <AppButton v-if="opening.can.approve" size="sm" variant="primary" :icon="Check" :loading="busy === 'approve'" @click="step('approve')">{{ t('accounting.opening.approve') }}</AppButton>
                    <AppButton v-if="opening.can.reject" size="sm" variant="ghost" :icon="X" :loading="busy === 'reject'" @click="step('reject')">{{ t('accounting.opening.reject') }}</AppButton>
                    <AppButton v-if="opening.can.withdraw" size="sm" variant="ghost" :icon="Undo2" :loading="busy === 'withdraw'" @click="step('withdraw')">{{ t('accounting.opening.withdraw') }}</AppButton>
                </section>

                <!-- Writing (a draft, a rejected one, or the first time) -->
                <form v-if="writable" class="grid gap-5" novalidate @submit.prevent="save(true)">
                    <section class="card grid gap-4 p-5 sm:grid-cols-2">
                        <AppField v-slot="{ id }" :label="t('accounting.opening.date')" :hint="t('accounting.opening.date_hint')" :error="fieldError('opening_date')">
                            <input :id="id" v-model="form.opening_date" type="date" class="field-input" required />
                        </AppField>
                    </section>

                    <!-- Accounts -->
                    <section class="card">
                        <header class="border-b border-line px-5 py-3">
                            <h2 class="text-[14.5px] font-semibold">{{ t('accounting.opening.accounts') }}</h2>
                            <p class="text-[12.5px] text-muted">{{ t('accounting.opening.accounts_text') }}</p>
                        </header>
                        <div class="hidden gap-3 border-b border-line px-5 py-2 text-[12px] font-medium text-muted sm:grid sm:grid-cols-[minmax(0,1fr)_9rem_9rem_2rem]" aria-hidden="true">
                            <span>{{ t('accounting.documents.account') }}</span>
                            <span class="text-end">{{ t('accounting.form.debit') }}</span>
                            <span class="text-end">{{ t('accounting.form.credit') }}</span>
                            <span></span>
                        </div>
                        <ol class="divide-y divide-line">
                            <li v-for="(row, index) in form.accounts" :key="`a${index}`" class="grid grid-cols-2 gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_9rem_9rem_2rem] sm:items-start sm:px-5">
                                <AppField v-slot="{ id }" :label="t('accounting.documents.account')" class="col-span-2 sm:col-span-1 sm:[&_label]:sr-only">
                                    <select :id="id" v-model="row.account_id" class="field-input">
                                        <option value="">{{ t('accounting.form.choose_account') }}</option>
                                        <option v-for="account in accountChoices" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option>
                                    </select>
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('accounting.form.debit')" class="sm:[&_label]:sr-only">
                                    <input :id="id" v-model="row.debit" inputmode="decimal" class="field-input tabular text-end" dir="ltr" autocomplete="off" />
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('accounting.form.credit')" class="sm:[&_label]:sr-only">
                                    <input :id="id" v-model="row.credit" inputmode="decimal" class="field-input tabular text-end" dir="ltr" autocomplete="off" />
                                </AppField>
                                <div class="col-span-2 flex justify-end sm:col-span-1 sm:pt-1">
                                    <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('accounting.opening.remove_line', { number: formatNumber(index + 1) })" @click="removeRow(form.accounts, index, blankAccount)" />
                                </div>
                                <p v-if="rowError('accounts', index)" class="col-span-2 text-[12.5px] text-bad sm:col-span-4" role="alert">{{ rowError('accounts', index) }}</p>
                            </li>
                        </ol>
                        <div class="border-t border-line px-5 py-3">
                            <AppButton size="sm" variant="ghost" :icon="Plus" @click="form.accounts.push(blankAccount())">{{ t('accounting.form.add_line') }}</AppButton>
                        </div>
                    </section>

                    <!-- Customers who still owe, vendors still owed -->
                    <section v-for="section in ['customers', 'vendors']" :key="section" class="card">
                        <header class="border-b border-line px-5 py-3">
                            <h2 class="text-[14.5px] font-semibold">{{ t(`accounting.opening.${section}`) }}</h2>
                            <p class="text-[12.5px] text-muted">{{ t(`accounting.opening.${section}_text`) }}</p>
                        </header>
                        <p v-if="!form[section].length" class="px-5 py-4 text-[13px] text-muted">{{ t(`accounting.opening.${section}_empty`) }}</p>
                        <ol v-else class="divide-y divide-line">
                            <li v-for="(row, index) in form[section]" :key="`${section}${index}`" class="grid grid-cols-2 gap-3 px-4 py-3 sm:px-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_9.5rem_9.5rem_9rem_2rem] lg:items-end">
                                <AppField v-slot="{ id }" :label="t(section === 'customers' ? 'accounting.documents.party_customer' : 'accounting.documents.party_vendor')" class="col-span-2 lg:col-span-1">
                                    <select :id="id" v-model="row.party_id" class="field-input">
                                        <option value="">{{ t('accounting.documents.choose_party') }}</option>
                                        <option v-for="party in section === 'customers' ? customers : vendors" :key="party.id" :value="party.id">{{ party.name }}</option>
                                    </select>
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('accounting.opening.reference')" optional class="col-span-2 lg:col-span-1">
                                    <input :id="id" v-model="row.reference" class="field-input" maxlength="100" />
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('accounting.opening.issue_date')">
                                    <input :id="id" v-model="row.issue_date" type="date" class="field-input" :max="form.opening_date || undefined" />
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('accounting.opening.due_date')" optional>
                                    <input :id="id" v-model="row.due_date" type="date" class="field-input" :min="row.issue_date || undefined" />
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('accounting.opening.amount')">
                                    <input :id="id" v-model="row.amount" inputmode="decimal" class="field-input tabular text-end" dir="ltr" autocomplete="off" />
                                </AppField>
                                <div class="flex items-end justify-end pb-1">
                                    <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('accounting.opening.remove_line', { number: formatNumber(index + 1) })" @click="removeRow(form[section], index)" />
                                </div>
                                <p v-if="rowError(section, index)" class="col-span-2 text-[12.5px] text-bad lg:col-span-6" role="alert">{{ rowError(section, index) }}</p>
                            </li>
                        </ol>
                        <div class="border-t border-line px-5 py-3">
                            <AppButton size="sm" variant="ghost" :icon="Plus" @click="form[section].push(blankParty())">{{ t(`accounting.opening.add_${section}`) }}</AppButton>
                        </div>
                    </section>

                    <!-- What it comes to -->
                    <section class="card grid gap-3 p-5 sm:grid-cols-[1fr_auto] sm:items-center" aria-live="polite">
                        <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-[13.5px] sm:max-w-md">
                            <dt class="text-muted">{{ t('accounting.opening.total_debit') }}</dt><dd class="tabular text-end">{{ money(totals.debit) }}</dd>
                            <dt class="text-muted">{{ t('accounting.opening.total_credit') }}</dt><dd class="tabular text-end">{{ money(totals.credit) }}</dd>
                            <dt class="font-medium">{{ t('accounting.opening.difference') }}</dt>
                            <dd class="tabular text-end font-semibold" :class="totals.difference === 0 ? 'text-ok' : ''">{{ money(Math.abs(totals.difference)) }}</dd>
                        </dl>
                        <p class="text-[12.5px] text-muted sm:max-w-sm">
                            {{ totals.invalid ? t('accounting.opening.invalid') : (totals.difference === 0 ? t('accounting.opening.balanced') : t(totals.difference > 0 ? 'accounting.opening.difference_credit' : 'accounting.opening.difference_debit', { amount: money(Math.abs(totals.difference)) })) }}
                        </p>
                    </section>

                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <AppButton v-if="opening?.can.delete" variant="danger-soft" :icon="Trash2" :loading="busy === 'delete'" @click="remove">{{ t('accounting.opening.delete') }}</AppButton>
                        <span class="flex-1"></span>
                        <AppButton variant="secondary" :icon="Save" :loading="busy === 'draft'" :disabled="!hasLines || totals.invalid || !form.opening_date" @click="save(false)">{{ t('accounting.opening.save_draft') }}</AppButton>
                        <AppButton type="submit" variant="primary" :icon="Send" :loading="busy === 'send'" :disabled="!hasLines || totals.invalid || !form.opening_date">{{ t('accounting.opening.save_post') }}</AppButton>
                    </div>
                </form>

                <!-- Reading (posted, waiting, or no right to write) -->
                <template v-else-if="opening">
                    <!-- One line per balance, stacked so it reads on a phone too. -->
                    <section class="card">
                        <ul class="divide-y divide-line">
                            <li v-for="line in [...kindLines('account'), ...kindLines('customer'), ...kindLines('vendor')]" :key="line.line_no" class="flex items-start gap-3 px-5 py-3">
                                <span class="min-w-0 flex-1 text-[13.5px]">
                                    <template v-if="line.kind === 'account'">{{ line.account_code }} · {{ line.account_name }}</template>
                                    <template v-else>{{ partyLabel(line) }}</template>
                                    <span v-if="line.kind !== 'account'" class="block text-[12px] text-muted">
                                        {{ t(`accounting.opening.kind_${line.kind}`) }}<template v-if="line.reference"> · {{ line.reference }}</template>
                                        · {{ formatDate(line.issue_date) }} → {{ formatDate(line.due_date) }}
                                    </span>
                                </span>
                                <span class="shrink-0 text-end">
                                    <span class="tabular block text-[13.5px] font-medium">{{ money(line.debit_minor || line.credit_minor) }}</span>
                                    <span class="block text-[11.5px] text-muted">{{ t(line.debit_minor ? 'accounting.form.debit' : 'accounting.form.credit') }}</span>
                                </span>
                            </li>
                        </ul>
                        <dl class="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1 border-t border-line px-5 py-3 text-[13.5px]">
                            <dt class="text-muted">{{ t('accounting.opening.total_debit') }}</dt><dd class="tabular text-end">{{ money(totals.debit) }}</dd>
                            <dt class="text-muted">{{ t('accounting.opening.total_credit') }}</dt><dd class="tabular text-end">{{ money(totals.credit) }}</dd>
                        </dl>
                        <p v-if="totals.difference !== 0" class="border-t border-line px-5 py-3 text-[12.5px] text-muted">
                            {{ t(totals.difference > 0 ? 'accounting.opening.difference_credit' : 'accounting.opening.difference_debit', { amount: money(Math.abs(totals.difference)) }) }}
                        </p>
                    </section>
                </template>

                <section v-else class="card px-5 py-8 text-center text-[13.5px] text-muted">{{ t('accounting.opening.none_yet') }}</section>
            </div>
        </BooksGate>
    </div>
</template>
