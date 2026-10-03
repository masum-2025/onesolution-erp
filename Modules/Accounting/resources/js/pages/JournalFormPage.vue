<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, CircleCheck, CircleAlert, Plus, Send, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization, session } from '@/lib/session';
import { subtreeIds, visibleOrganizations } from '@/lib/organizations';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { accountTree, amountText, formLines, journalPayload, lineTotals, parseAmount, postableAccounts, todayIn } from '../lib';

/**
 * Write a journal entry or change a draft / rejected one. Totals update as
 * people type; "Save and send" posts it (or sends it for approval above the
 * company's amount). Amounts are typed as text and kept exact.
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();
const router = useRouter();
const editingId = computed(() => route.params.id ?? null);

const loading = ref(true);
const loadError = ref(null);
const saving = ref(null); // null | 'draft' | 'send'
const errors = ref({});
const currency = ref('');
const version = ref(null);
const accounts = ref([]);
const units = ref([]);

const blankLine = () => ({ account_id: '', debit: '', credit: '', cost_centre_id: '', memo: '' });
const form = reactive({
    entry_date: todayIn(session.me?.context?.settings?.timezone),
    narration: '',
    lines: [blankLine(), blankLine()],
});

const postable = computed(() => {
    const allowed = new Set(postableAccounts(accounts.value).map((account) => account.id));
    return accountTree(accounts.value).flat.filter((account) => allowed.has(account.id));
});
const totals = computed(() => lineTotals(form.lines, currency.value));
const money = (amount) => formatMoney({ amount, currency: currency.value });

onMounted(load);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [setup, list, visible] = await Promise.all([books.setup(), books.accounts(), visibleOrganizations().catch(() => [])]);
        currency.value = setup.data.currency;
        accounts.value = list.data;
        const inCompany = subtreeIds(visible, org.id);
        units.value = visible.filter((unit) => unit.id !== org.id && inCompany.has(unit.id));

        if (editingId.value) {
            const { data } = await books.journal(editingId.value);
            if (!data.can.edit) {
                toast.error(t('accounting.form.not_editable'));
                router.replace({ name: 'accounting-journal', params: { id: data.id } });
                return;
            }
            Object.assign(form, { entry_date: data.entry_date, narration: data.narration, lines: formLines(data, currency.value, org.id) });
            if (form.lines.length < 2) form.lines.push(blankLine());
            version.value = data.version;
        }
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}

function addLine() {
    // A new line offers the amount that would balance the entry.
    const line = blankLine();
    const difference = totals.value.difference;
    if (difference > 0) line.credit = amountText(difference, currency.value);
    if (difference < 0) line.debit = amountText(-difference, currency.value);
    form.lines.push(line);
}

function removeLine(index) {
    form.lines.splice(index, 1);
    if (form.lines.length < 2) form.lines.push(blankLine());
}

function lineInvalid(line) {
    const debit = parseAmount(line.debit, currency.value);
    const credit = parseAmount(line.credit, currency.value);
    return debit === null || credit === null || (debit > 0 && credit > 0);
}

async function save(send) {
    saving.value = send ? 'send' : 'draft';
    errors.value = {};
    const body = journalPayload(form, currency.value);
    try {
        let journal;
        if (editingId.value) {
            journal = (await books.updateJournal(editingId.value, { ...body, base_version: version.value })).data;
            if (send) journal = (await books.step(journal.id, 'submit', { base_version: journal.version })).data;
        } else {
            journal = (await books.createJournal({ ...body, submit: send })).data;
        }
        if (journal.status === 'posted') toast.success(t('accounting.form.posted', { number: journal.number }));
        else if (journal.status === 'pending_approval') toast.success(t('accounting.form.pending'));
        else toast.success(t('accounting.form.saved_draft'));
        router.push({ name: 'accounting-journal', params: { id: journal.id } });
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('accounting.common.conflict'));
            load();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = null;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
const lineError = (index, field) => fieldError(`lines.${index}.${field}`) ?? (field === 'account_id' ? fieldError(`lines.${index}`) : null);
</script>

<template>
    <div>
        <PageHeader :title="editingId ? t('accounting.form.title_edit') : t('accounting.form.title_new')" :description="t('accounting.form.text')">
            <template #actions>
                <AppButton variant="ghost" :to="editingId ? { name: 'accounting-journal', params: { id: editingId } } : { name: 'accounting' }" :icon="ArrowLeft">{{ t('accounting.common.back') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="loading" class="card"><SkeletonRows :rows="6" /></section>
        <section v-else-if="loadError" class="card"><ErrorState compact :error="loadError" @retry="load" /></section>

        <form v-else class="grid gap-5" novalidate @submit.prevent="save(true)">
            <section class="card grid gap-4 p-5 sm:grid-cols-[12rem_1fr]">
                <AppField v-slot="{ id }" :label="t('accounting.form.date')" :error="fieldError('entry_date')">
                    <input :id="id" v-model="form.entry_date" type="date" class="field-input" required />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.form.narration')" :hint="t('accounting.form.narration_hint')" :error="fieldError('narration')">
                    <input :id="id" v-model="form.narration" class="field-input" maxlength="500" required />
                </AppField>
            </section>

            <section class="card">
                <header class="flex items-center justify-between border-b border-line px-5 py-3">
                    <h2 class="text-[14px] font-semibold text-fg">{{ t('accounting.form.lines') }}</h2>
                    <span v-if="fieldError('lines')" class="text-[12.5px] text-bad">{{ fieldError('lines') }}</span>
                </header>

                <div class="hidden gap-3 border-b border-line px-5 py-2 text-[12px] font-medium text-muted lg:grid lg:grid-cols-[minmax(0,2fr)_8rem_8rem_minmax(0,1fr)_2rem]" aria-hidden="true">
                    <span>{{ t('accounting.form.account') }}</span>
                    <span class="pe-3 text-end">{{ t('accounting.form.debit') }}</span>
                    <span class="pe-3 text-end">{{ t('accounting.form.credit') }}</span>
                    <span>{{ t('accounting.form.cost_centre') }}</span>
                </div>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.lines" :key="index" class="grid grid-cols-2 gap-3 px-4 py-4 sm:px-5 lg:grid-cols-[minmax(0,2fr)_8rem_8rem_minmax(0,1fr)_auto] lg:items-start">
                        <AppField v-slot="{ id }" :label="t('accounting.form.account')" :error="lineError(index, 'account_id')" class="col-span-2 lg:col-span-1 lg:[&_label]:sr-only">
                            <select :id="id" v-model="line.account_id" class="field-input" :aria-label="`${t('accounting.form.line', { number: formatNumber(index + 1) })}: ${t('accounting.form.account')}`">
                                <option value="">{{ t('accounting.form.choose_account') }}</option>
                                <option v-for="account in postable" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('accounting.form.debit')" :error="lineError(index, 'debit_minor')" class="lg:[&_label]:sr-only">
                            <input :id="id" v-model="line.debit" inputmode="decimal" class="field-input tabular text-end" dir="ltr" autocomplete="off" :aria-invalid="lineInvalid(line)" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('accounting.form.credit')" :error="lineError(index, 'credit_minor')" class="lg:[&_label]:sr-only">
                            <input :id="id" v-model="line.credit" inputmode="decimal" class="field-input tabular text-end" dir="ltr" autocomplete="off" :aria-invalid="lineInvalid(line)" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('accounting.form.cost_centre')" :error="lineError(index, 'cost_centre_id')" class="col-span-2 lg:col-span-1 lg:[&_label]:sr-only">
                            <select :id="id" v-model="line.cost_centre_id" class="field-input">
                                <option value="">{{ t('accounting.form.no_cost_centre') }}</option>
                                <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.display_name }}</option>
                            </select>
                        </AppField>
                        <div class="col-span-2 flex justify-end lg:col-span-1 lg:pt-0.5">
                            <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('accounting.form.remove_line', { number: formatNumber(index + 1) })" @click="removeLine(index)" />
                        </div>
                        <p v-if="lineInvalid(line)" class="col-span-2 text-[12.5px] text-bad lg:col-span-5">{{ t('accounting.form.invalid_amount') }}</p>
                    </li>
                </ol>

                <div class="border-t border-line px-4 py-3 sm:px-5">
                    <AppButton size="sm" variant="ghost" :icon="Plus" @click="addLine">{{ t('accounting.form.add_line') }}</AppButton>
                </div>

                <footer class="grid gap-2 border-t border-line bg-subtle/50 px-5 py-4 sm:grid-cols-3 sm:items-center" aria-live="polite">
                    <div class="text-[13px]"><span class="text-muted">{{ t('accounting.form.debit') }}:</span> <span class="tabular font-semibold">{{ money(totals.debit) }}</span></div>
                    <div class="text-[13px]"><span class="text-muted">{{ t('accounting.form.credit') }}:</span> <span class="tabular font-semibold">{{ money(totals.credit) }}</span></div>
                    <div class="flex items-center gap-1.5 text-[13px] sm:justify-end" :class="totals.balanced ? 'text-ok' : 'text-warn'">
                        <component :is="totals.balanced ? CircleCheck : CircleAlert" class="size-4" aria-hidden="true" />
                        <span>{{ totals.balanced ? t('accounting.form.balanced') : t('accounting.form.unbalanced', { amount: money(Math.abs(totals.difference)) }) }}</span>
                    </div>
                </footer>
            </section>

            <div class="flex flex-wrap justify-end gap-2">
                <AppButton variant="ghost" :to="{ name: 'accounting' }">{{ t('accounting.form.cancel') }}</AppButton>
                <AppButton :loading="saving === 'draft'" :disabled="saving !== null || !form.narration.trim()" @click="save(false)">{{ t('accounting.form.save_draft') }}</AppButton>
                <AppButton variant="primary" type="submit" :icon="Send" :loading="saving === 'send'" :disabled="saving !== null || !totals.balanced || !form.narration.trim()">{{ t('accounting.form.send') }}</AppButton>
            </div>
        </form>
    </div>
</template>
