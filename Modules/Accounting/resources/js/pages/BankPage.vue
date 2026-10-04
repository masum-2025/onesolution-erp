<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Check, CheckCheck, FilePlus, FileUp, Landmark, Link2, Scale, Trash2, Unlink } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { accountTree, matchTotal, parseAmount, postableAccounts, todayIn } from '../lib';
import BooksGate from '../components/BooksGate.vue';
import BankImportDialog from '../components/BankImportDialog.vue';

/**
 * Matching a cash, bank or wallet account with its statement: bring the
 * statement in, take the proposed pairs or match by hand, write what the
 * books are missing, and reconcile up to a day once the statement agrees.
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();
const router = useRouter();

const accounts = ref([]);
const chart = ref([]);
const accountId = ref(route.query.account ?? '');
const desk = ref(null);
const meta = ref({});
const loading = ref(true);
const loadError = ref(null);
const busy = ref(null);
const showImport = ref(false);

const currency = computed(() => desk.value?.currency ?? '');
const money = (amount) => formatMoney({ amount, currency: currency.value });
const signed = (amount) => `${amount > 0 ? '+' : '−'}${money(Math.abs(amount))}`;
const lines = computed(() => desk.value?.lines ?? []);
const unmatched = computed(() => lines.value.filter((line) => line.status === 'unmatched'));
const suggested = computed(() => unmatched.value.filter((line) => line.suggestion));
const latest = computed(() => (desk.value?.reconciliations ?? []).find((item) => item.status === 'finished') ?? null);

onMounted(start);
watch(accountId, (id) => {
    router.replace({ query: id ? { account: id } : {} });
    if (id) loadDesk();
});

async function start() {
    loading.value = true;
    loadError.value = null;
    try {
        const [list, all] = await Promise.all([books.bankAccounts(), books.accounts()]);
        accounts.value = list.data;
        chart.value = all.data;
        if (!accountId.value && list.data.length) accountId.value = list.data[0].id;
        else if (accountId.value) await loadDesk();
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}

async function loadDesk() {
    try {
        const response = await books.bankDesk(accountId.value);
        desk.value = response.data;
        meta.value = response.meta;
        loadError.value = null;
    } catch (error) {
        loadError.value = error;
    }
}

async function act(key, call, done) {
    busy.value = key;
    try {
        const result = await call();
        if (done) toast.success(typeof done === 'function' ? done(result) : done);
        await loadDesk();
        return result;
    } catch (error) {
        toast.error(error.message);
        return null;
    } finally {
        busy.value = null;
    }
}

const autoMatch = () => act('auto', () => books.bankAutoMatch(accountId.value), (result) => t('accounting.bank.auto_done', { count: result.data.matched }));
const accept = (line) => act(line.id, () => books.bankLineStep(line.id, 'match', { journal_line_ids: [line.suggestion.id] }), t('accounting.bank.matched_done'));
const unmatch = (line) => act(line.id, () => books.bankLineStep(line.id, 'unmatch'), t('accounting.bank.unmatched_done'));

async function remove(line) {
    const confirmed = await confirmAction({ title: t('accounting.bank.delete_title'), message: t('accounting.bank.delete_text', { description: line.description }), confirmLabel: t('accounting.bank.delete'), danger: true });
    if (confirmed) await act(line.id, () => books.deleteBankLine(line.id), t('accounting.bank.deleted'));
}

// Match by hand: book lines of the same direction, ticked until they add up.
const matching = ref(null);
const chosen = ref([]);
const candidates = computed(() => (desk.value?.outstanding ?? []).filter((entry) => matching.value && Math.sign(entry.amount_minor) === Math.sign(matching.value.amount_minor)));
const picked = computed(() => matchTotal(candidates.value, chosen.value, matching.value?.amount_minor));
function openMatch(line) {
    matching.value = line;
    chosen.value = line.suggestion ? [line.suggestion.id] : [];
}
async function saveMatch() {
    const line = matching.value;
    if (await act('match', () => books.bankLineStep(line.id, 'match', { journal_line_ids: chosen.value }), t('accounting.bank.matched_done'))) matching.value = null;
}

// Write the entry the books are missing.
const entryFor = ref(null);
const entry = reactive({ account_id: '', narration: '' });
const entryErrors = ref({});
const otherAccounts = computed(() => {
    const usable = new Set(postableAccounts(chart.value).map((account) => account.id));
    return accountTree(chart.value).flat.filter((account) => usable.has(account.id) && account.id !== accountId.value);
});
function openEntry(line) {
    entryFor.value = line;
    Object.assign(entry, { account_id: '', narration: line.description });
    entryErrors.value = {};
}
async function saveEntry() {
    busy.value = 'entry';
    entryErrors.value = {};
    try {
        const { data } = await books.bankLineStep(entryFor.value.id, 'entry', { ...entry });
        toast.success(t(data.status === 'posted' ? 'accounting.bank.entry_posted' : 'accounting.bank.entry_pending'));
        entryFor.value = null;
        await loadDesk();
    } catch (error) {
        entryErrors.value = error.errors ?? {};
        if (!Object.keys(entryErrors.value).length) toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

// Reconcile up to a day.
const reconciling = ref(false);
const statement = reactive({ date: '', balance: '', opening: '' });
const check = ref(null);
const checkErrors = ref({});
function openReconcile() {
    Object.assign(statement, { date: todayIn(session.me?.context?.settings?.timezone), balance: '', opening: '' });
    check.value = null;
    checkErrors.value = {};
    reconciling.value = true;
}
const statementBody = () => ({
    statement_date: statement.date,
    statement_balance_minor: signedMinor(statement.balance),
    ...(latest.value ? {} : { opening_balance_minor: signedMinor(statement.opening) }),
});
// Balances may be below zero (an overdraft): "-1,250.00".
function signedMinor(text) {
    const clean = String(text ?? '').trim();
    const negative = clean.startsWith('-') || clean.startsWith('−');
    const minor = parseAmount(negative ? clean.slice(1) : clean, currency.value);
    return minor === null ? null : (negative ? -minor : minor);
}
async function runCheck() {
    busy.value = 'check';
    checkErrors.value = {};
    try {
        check.value = (await books.bankReconciliationPreview(accountId.value, statementBody())).data;
    } catch (error) {
        check.value = null;
        checkErrors.value = error.errors ?? {};
        if (!Object.keys(checkErrors.value).length) toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
async function finish() {
    if (await act('finish', () => books.finishReconciliation(accountId.value, statementBody()), t('accounting.bank.finished_done'))) reconciling.value = false;
}
async function reopen(item) {
    const confirmed = await confirmAction({ title: t('accounting.bank.reopen_title', { date: formatDate(item.statement_date) }), message: t('accounting.bank.reopen_text'), confirmLabel: t('accounting.bank.reopen'), reason: 'required', danger: true });
    if (confirmed) await act(item.id, () => books.reopenReconciliation(item.id, { reason: confirmed.reason }), t('accounting.bank.reopened_done'));
}
const checkError = (name) => checkErrors.value[name]?.[0] ?? null;
const tone = (status) => ({ matched: 'ok', reconciled: 'neutral', unmatched: 'warn' })[status];
</script>

<template>
    <div>
        <PageHeader :title="t('accounting.bank.title')" :description="t('accounting.bank.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'accounting' }" :icon="ArrowLeft">{{ t('accounting.journal.back') }}</AppButton>
                <AppButton v-if="desk && meta.can_reconcile" variant="secondary" :icon="FileUp" @click="showImport = true">{{ t('accounting.bank.import') }}</AppButton>
                <AppButton v-if="desk && meta.can_reconcile" variant="primary" :icon="Scale" @click="openReconcile">{{ t('accounting.bank.reconcile') }}</AppButton>
            </template>
        </PageHeader>

        <BooksGate>
            <section v-if="loading" class="card"><SkeletonRows :rows="6" /></section>
            <section v-else-if="loadError" class="card"><ErrorState compact :error="loadError" @retry="start" /></section>
            <section v-else-if="!accounts.length" class="card"><EmptyState :icon="Landmark" :title="t('accounting.bank.no_accounts')" compact /></section>

            <div v-else class="grid grid-cols-1 gap-5">
                <!-- Which account, and where it stands -->
                <section class="card flex flex-wrap items-end gap-4 p-5">
                    <AppField v-slot="{ id }" :label="t('accounting.bank.account')" class="min-w-[16rem] flex-1 sm:max-w-sm">
                        <select :id="id" v-model="accountId" class="field-input">
                            <option v-for="account in accounts" :key="account.id" :value="account.id">
                                {{ account.code }} · {{ account.name }}{{ account.unmatched_lines ? ` (${account.unmatched_lines})` : '' }}
                            </option>
                        </select>
                    </AppField>
                    <dl v-if="desk" class="flex flex-wrap gap-x-6 gap-y-1 text-[13px]">
                        <div><dt class="text-muted">{{ t('accounting.bank.to_match') }}</dt><dd class="tabular font-semibold">{{ unmatched.length }}</dd></div>
                        <div><dt class="text-muted">{{ t('accounting.bank.not_in_bank') }}</dt><dd class="tabular font-semibold">{{ desk.outstanding.length }}</dd></div>
                        <div><dt class="text-muted">{{ t('accounting.bank.reconciled_to') }}</dt><dd class="font-semibold">{{ latest ? `${formatDate(latest.statement_date)} · ${money(latest.statement_balance_minor)}` : t('accounting.bank.never') }}</dd></div>
                    </dl>
                    <AppButton v-if="meta.can_reconcile && suggested.length" variant="secondary" :icon="CheckCheck" :loading="busy === 'auto'" @click="autoMatch">
                        {{ t('accounting.bank.auto', { count: suggested.length }) }}
                    </AppButton>
                </section>

                <div v-if="desk" class="grid min-w-0 gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <!-- The statement -->
                    <section class="card">
                        <header class="border-b border-line px-5 py-3">
                            <h2 class="text-[14.5px] font-semibold">{{ t('accounting.bank.statement') }}</h2>
                            <p class="text-[12.5px] text-muted">{{ t('accounting.bank.statement_text') }}</p>
                        </header>
                        <EmptyState v-if="!lines.length" :icon="FileUp" :title="t('accounting.bank.no_lines')" :text="t('accounting.bank.no_lines_text')" compact />
                        <ul v-else class="divide-y divide-line">
                            <li v-for="line in lines" :key="line.id" class="grid gap-2 px-5 py-3">
                                <div class="flex items-start gap-3">
                                    <span class="w-24 shrink-0 text-[12.5px] text-muted">{{ formatDate(line.line_date) }}</span>
                                    <span class="min-w-0 flex-1 text-[13.5px]">
                                        <span class="block truncate">{{ line.description }}</span>
                                        <span v-if="line.reference" class="block text-[12px] text-muted" dir="ltr">{{ line.reference }}</span>
                                    </span>
                                    <span class="tabular shrink-0 text-end text-[13.5px] font-semibold" :class="line.amount_minor > 0 ? 'text-ok' : ''">{{ signed(line.amount_minor) }}</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 ps-0 sm:ps-27">
                                    <AppBadge :tone="tone(line.status)" dot>{{ t(`accounting.bank.status.${line.status}`) }}</AppBadge>
                                    <span v-for="match in line.matches" :key="match.id" class="text-[12px] text-muted">
                                        <RouterLink :to="{ name: 'accounting-journal', params: { id: match.journal_id } }" class="underline-offset-2 hover:underline">{{ match.number }}</RouterLink> · {{ formatDate(match.entry_date) }}
                                    </span>
                                    <template v-if="line.suggestion && meta.can_reconcile">
                                        <span class="text-[12px] text-muted">{{ t('accounting.bank.suggested', { number: line.suggestion.number, date: formatDate(line.suggestion.entry_date) }) }}</span>
                                        <AppButton size="sm" variant="secondary" :icon="Check" :loading="busy === line.id" @click="accept(line)">{{ t('accounting.bank.accept') }}</AppButton>
                                    </template>
                                    <span class="flex-1"></span>
                                    <template v-if="meta.can_reconcile && line.status === 'unmatched'">
                                        <AppButton size="sm" variant="ghost" :icon="Link2" @click="openMatch(line)">{{ t('accounting.bank.match') }}</AppButton>
                                        <AppButton v-if="meta.can_post" size="sm" variant="ghost" :icon="FilePlus" @click="openEntry(line)">{{ t('accounting.bank.write_entry') }}</AppButton>
                                        <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('accounting.bank.delete')" @click="remove(line)" />
                                    </template>
                                    <AppButton v-if="meta.can_reconcile && line.status === 'matched'" size="sm" variant="ghost" :icon="Unlink" :loading="busy === line.id" @click="unmatch(line)">{{ t('accounting.bank.undo') }}</AppButton>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <div class="grid min-w-0 grid-cols-1 content-start gap-5">
                        <!-- In the books, not yet in the bank -->
                        <section class="card">
                            <header class="border-b border-line px-5 py-3">
                                <h2 class="text-[14.5px] font-semibold">{{ t('accounting.bank.outstanding') }}</h2>
                                <p class="text-[12.5px] text-muted">{{ t('accounting.bank.outstanding_text') }}</p>
                            </header>
                            <p v-if="!desk.outstanding.length" class="px-5 py-4 text-[13px] text-muted">{{ t('accounting.bank.outstanding_none') }}</p>
                            <ul v-else class="divide-y divide-line">
                                <li v-for="entryLine in desk.outstanding" :key="entryLine.id" class="flex items-start gap-3 px-5 py-2.5 text-[13px]">
                                    <span class="w-24 shrink-0 text-[12.5px] text-muted">{{ formatDate(entryLine.entry_date) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <RouterLink :to="{ name: 'accounting-journal', params: { id: entryLine.journal_id } }" class="block truncate hover:underline">{{ entryLine.narration }}</RouterLink>
                                        <span class="block text-[12px] text-muted">{{ entryLine.number }}</span>
                                    </span>
                                    <span class="tabular shrink-0 font-medium" :class="entryLine.amount_minor > 0 ? 'text-ok' : ''">{{ signed(entryLine.amount_minor) }}</span>
                                </li>
                            </ul>
                        </section>

                        <!-- Past reconciliations -->
                        <section v-if="desk.reconciliations.length" class="card">
                            <header class="border-b border-line px-5 py-3"><h2 class="text-[14.5px] font-semibold">{{ t('accounting.bank.history') }}</h2></header>
                            <ul class="divide-y divide-line">
                                <li v-for="item in desk.reconciliations" :key="item.id" class="flex items-center gap-3 px-5 py-2.5 text-[13px]">
                                    <span class="min-w-0 flex-1">
                                        {{ formatDate(item.statement_date) }} · <span class="tabular">{{ money(item.statement_balance_minor) }}</span>
                                        <span v-if="item.status === 'reopened'" class="block text-[12px] text-muted">{{ t('accounting.bank.reopened_because', { reason: item.reopen_reason }) }}</span>
                                    </span>
                                    <AppBadge :tone="item.status === 'finished' ? 'ok' : 'neutral'">{{ t(`accounting.bank.rec_status.${item.status}`) }}</AppBadge>
                                    <AppButton v-if="meta.can_reconcile && latest && item.id === latest.id" size="sm" variant="ghost" :loading="busy === item.id" @click="reopen(item)">{{ t('accounting.bank.reopen') }}</AppButton>
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>
            </div>
        </BooksGate>

        <BankImportDialog v-if="desk" :open="showImport" :books="books" :account-id="accountId" :format="desk.format" @close="showImport = false" @imported="showImport = false; loadDesk()" />

        <!-- Match by hand -->
        <AppDialog :open="matching !== null" :title="t('accounting.bank.match_title')" size="lg" @close="matching = null">
            <template v-if="matching">
                <p class="mb-3 text-[13px]">{{ formatDate(matching.line_date) }} · {{ matching.description }} · <span class="tabular font-semibold">{{ signed(matching.amount_minor) }}</span></p>
                <p v-if="!candidates.length" class="text-[13px] text-muted">{{ t('accounting.bank.no_candidates') }}</p>
                <ul v-else class="max-h-80 divide-y divide-line overflow-y-auto rounded-lg border border-line">
                    <li v-for="entryLine in candidates" :key="entryLine.id">
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-[13px] hover:bg-surface-2">
                            <input v-model="chosen" type="checkbox" :value="entryLine.id" />
                            <span class="w-24 shrink-0 text-muted">{{ formatDate(entryLine.entry_date) }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ entryLine.number }} · {{ entryLine.narration }}</span>
                            <span class="tabular shrink-0">{{ signed(entryLine.amount_minor) }}</span>
                        </label>
                    </li>
                </ul>
                <p class="mt-3 text-[13px]" :class="picked.exact ? 'text-ok' : 'text-muted'" aria-live="polite">
                    {{ t('accounting.bank.chosen_total', { total: money(Math.abs(picked.total)), needed: money(Math.abs(matching.amount_minor)) }) }}
                </p>
            </template>
            <template #footer>
                <AppButton variant="ghost" @click="matching = null">{{ t('accounting.common.cancel') }}</AppButton>
                <AppButton variant="primary" :icon="Link2" :loading="busy === 'match'" :disabled="!picked.exact" @click="saveMatch">{{ t('accounting.bank.match') }}</AppButton>
            </template>
        </AppDialog>

        <!-- Write the missing entry -->
        <AppDialog :open="entryFor !== null" :title="t('accounting.bank.entry_title')" :description="t('accounting.bank.entry_text')" @close="entryFor = null">
            <form v-if="entryFor" id="accounting-bank-entry" class="grid gap-4" novalidate @submit.prevent="saveEntry">
                <p class="text-[13px]">{{ formatDate(entryFor.line_date) }} · <span class="tabular font-semibold">{{ signed(entryFor.amount_minor) }}</span></p>
                <AppField v-slot="{ id }" :label="t('accounting.bank.other_account')" :hint="t(entryFor.amount_minor > 0 ? 'accounting.bank.other_in' : 'accounting.bank.other_out')" :error="entryErrors.account_id?.[0]">
                    <select :id="id" v-model="entry.account_id" class="field-input">
                        <option value="">{{ t('accounting.form.choose_account') }}</option>
                        <option v-for="account in otherAccounts" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.form.narration')" :error="entryErrors.narration?.[0]">
                    <input :id="id" v-model="entry.narration" class="field-input" maxlength="500" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="entryFor = null">{{ t('accounting.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="accounting-bank-entry" :loading="busy === 'entry'" :disabled="!entry.account_id || entry.narration.trim().length < 3">{{ t('accounting.bank.write_entry') }}</AppButton>
            </template>
        </AppDialog>

        <!-- Reconcile up to a day -->
        <AppDialog :open="reconciling" :title="t('accounting.bank.reconcile_title')" :description="t('accounting.bank.reconcile_text')" size="lg" :icon="Scale" @close="reconciling = false">
            <form id="accounting-bank-check" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="runCheck">
                <AppField v-slot="{ id }" :label="t('accounting.bank.statement_date')" :error="checkError('statement_date')">
                    <input :id="id" v-model="statement.date" type="date" class="field-input" @change="check = null" />
                </AppField>
                <AppField v-if="!latest" v-slot="{ id }" :label="t('accounting.bank.opening_balance')" :hint="t('accounting.bank.opening_hint')" :error="checkError('opening_balance_minor')">
                    <input :id="id" v-model="statement.opening" inputmode="decimal" class="field-input tabular text-end" dir="ltr" @input="check = null" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.bank.closing_balance')" :hint="latest ? t('accounting.bank.starts_from', { amount: money(latest.statement_balance_minor) }) : ''" :error="checkError('statement_balance_minor')">
                    <input :id="id" v-model="statement.balance" inputmode="decimal" class="field-input tabular text-end" dir="ltr" @input="check = null" />
                </AppField>
            </form>

            <dl v-if="check" class="mt-4 grid grid-cols-[1fr_auto] gap-x-6 gap-y-1.5 rounded-lg border border-line p-4 text-[13.5px]" aria-live="polite">
                <dt class="text-muted">{{ t('accounting.bank.opening_balance') }}</dt><dd class="tabular text-end">{{ money(check.opening_balance_minor) }}</dd>
                <dt class="text-muted">{{ t('accounting.bank.money_in', { count: check.lines }) }}</dt><dd class="tabular text-end">{{ signed(check.money_in_minor) }}</dd>
                <dt class="text-muted">{{ t('accounting.bank.money_out') }}</dt><dd class="tabular text-end">−{{ money(check.money_out_minor) }}</dd>
                <dt class="font-medium">{{ t('accounting.bank.cleared') }}</dt><dd class="tabular text-end font-medium">{{ money(check.cleared_balance_minor) }}</dd>
                <dt class="font-semibold">{{ t('accounting.bank.difference') }}</dt>
                <dd class="tabular text-end font-semibold" :class="check.difference_minor === 0 ? 'text-ok' : 'text-bad'">{{ money(check.difference_minor) }}</dd>
                <dt class="mt-2 border-t border-line pt-2 text-muted">{{ t('accounting.bank.book_balance') }}</dt><dd class="tabular mt-2 border-t border-line pt-2 text-end">{{ money(check.book_balance_minor) }}</dd>
                <dt class="text-muted">{{ t('accounting.bank.outstanding_count', { count: check.outstanding }) }}</dt><dd class="tabular text-end">{{ signed(check.outstanding_minor || 0) }}</dd>
            </dl>
            <p v-if="check && !check.can_finish" class="mt-3 text-[13px] text-bad" role="alert">
                {{ check.unmatched ? t('accounting.bank.still_unmatched', { count: check.unmatched }) : t('accounting.bank.not_agreeing') }}
            </p>

            <template #footer>
                <AppButton variant="ghost" @click="reconciling = false">{{ t('accounting.common.cancel') }}</AppButton>
                <AppButton variant="secondary" type="submit" form="accounting-bank-check" :loading="busy === 'check'" :disabled="!statement.date || statement.balance === ''">{{ t('accounting.bank.check') }}</AppButton>
                <AppButton variant="primary" :icon="Scale" :loading="busy === 'finish'" :disabled="!check?.can_finish" @click="finish">{{ t('accounting.bank.finish') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
