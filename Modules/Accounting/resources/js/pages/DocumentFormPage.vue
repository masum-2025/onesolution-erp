<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Plus, Send, Trash2 } from 'lucide-vue-next';
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
import { accountTree, documentFormLines, documentPayload, documentTaxTotals, postableAccounts, sideOf, todayIn } from '../lib';

/**
 * Write an invoice, credit note, bill or vendor credit, or change a draft or
 * rejected one. Amounts update as people type (quantity x price, the
 * server's own rounding); "Save and post" numbers it and puts it in the
 * books (or sends it for approval above the company's amount).
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();
const router = useRouter();
const editingId = computed(() => route.params.id ?? null);

const type = ref(['invoice', 'credit_note', 'bill', 'vendor_credit'].includes(route.query.type) ? route.query.type : 'invoice');
const side = computed(() => sideOf(type.value));
const kind = computed(() => t(`accounting.kinds.${type.value}`));

const loading = ref(true);
const loadError = ref(null);
const saving = ref(null);
const errors = ref({});
const currency = ref('');
const version = ref(null);
const parties = ref([]);
const accounts = ref([]);
const units = ref([]);

const blankLine = () => ({ description: '', quantity: '1', price: '', account_id: '', cost_centre_id: '', tax_code_id: '' });
const taxCodes = ref([]);
const inclusive = ref(false);
const form = reactive({
    party_id: route.query.party_id ?? '',
    issue_date: todayIn(session.me?.context?.settings?.timezone),
    due_date: '',
    reference: '',
    notes: '',
    lines: [blankLine()],
});

// Sales lines go to income; purchase lines to expenses or assets (equipment, stock).
const lineAccounts = computed(() => {
    const allowed = side.value === 'sales' ? ['income'] : ['expense', 'asset'];
    const usable = new Set(postableAccounts(accounts.value).filter((account) => allowed.includes(account.type)).map((account) => account.id));
    return accountTree(accounts.value).flat.filter((account) => usable.has(account.id));
});
// Tax codes of this side; VAT on top of prices or inside them (the company rule, or the saved document's).
const lineTaxCodes = computed(() => taxCodes.value.filter((code) => code.is_active && [side.value, 'both'].includes(code.applies_to)));
const totals = computed(() => {
    const result = documentTaxTotals(form.lines, currency.value, taxCodes.value, inclusive.value);
    // A line shows quantity x price as typed: net, or with tax when prices include it.
    return { ...result, amounts: result.rows.map((row) => (row.net === null ? null : row.net + (inclusive.value ? row.tax : 0))) };
});
const money = (amount) => formatMoney({ amount, currency: currency.value });

onMounted(load);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const existing = editingId.value ? (await books.document(editingId.value)).data : null;
        if (existing) type.value = existing.type;
        const [setup, list, chart, visible, codes] = await Promise.all([
            books.setup(),
            books.parties({ role: side.value === 'sales' ? 'customers' : 'vendors', per_page: 100 }),
            books.accounts(),
            visibleOrganizations().catch(() => []),
            books.taxCodes(),
        ]);
        currency.value = setup.data.currency;
        inclusive.value = existing ? existing.prices_include_tax : setup.data.prices_include_tax;
        taxCodes.value = codes.data;
        parties.value = list.data;
        accounts.value = chart.data;
        const inCompany = subtreeIds(visible, org.id);
        units.value = visible.filter((unit) => unit.id !== org.id && inCompany.has(unit.id));

        if (existing) {
            if (!existing.can.edit) {
                toast.error(t('accounting.form.not_editable'));
                router.replace({ name: 'accounting-document', params: { id: existing.id } });
                return;
            }
            Object.assign(form, {
                party_id: existing.party_id,
                issue_date: existing.issue_date,
                due_date: existing.due_date,
                reference: existing.reference ?? '',
                notes: existing.notes ?? '',
                lines: documentFormLines(existing, currency.value, org.id),
            });
            version.value = existing.version;
        }
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}

function removeLine(index) {
    form.lines.splice(index, 1);
    if (!form.lines.length) form.lines.push(blankLine());
}

async function save(send) {
    saving.value = send ? 'send' : 'draft';
    errors.value = {};
    const body = documentPayload(form, currency.value);
    try {
        let document;
        if (editingId.value) {
            document = (await books.updateDocument(editingId.value, { ...body, base_version: version.value })).data;
            if (send) document = (await books.documentStep(document.id, 'submit', { base_version: document.version })).data;
        } else {
            document = (await books.createDocument({ ...body, type: type.value, submit: send })).data;
        }
        if (document.status === 'pending_approval') toast.success(t('accounting.documents.pending'));
        else if (document.number) toast.success(t('accounting.documents.posted', { kind: kind.value, number: document.number }));
        else toast.success(t('accounting.documents.saved_draft'));
        router.push({ name: 'accounting-document', params: { id: document.id } });
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
const ready = computed(() => form.party_id && !totals.value.invalid && totals.value.total > 0 && form.lines.every((line) => line.description.trim() && line.account_id));
</script>

<template>
    <div>
        <PageHeader :title="t(editingId ? 'accounting.documents.title_edit' : 'accounting.documents.title_new', { kind })" :description="t('accounting.documents.form_text')">
            <template #actions>
                <AppButton variant="ghost" :to="editingId ? { name: 'accounting-document', params: { id: editingId } } : { name: side === 'sales' ? 'accounting-sales' : 'accounting-purchases' }" :icon="ArrowLeft">
                    {{ t('accounting.common.back') }}
                </AppButton>
            </template>
        </PageHeader>

        <section v-if="loading" class="card"><SkeletonRows :rows="6" /></section>
        <section v-else-if="loadError" class="card"><ErrorState compact :error="loadError" @retry="load" /></section>

        <form v-else class="grid gap-5" novalidate @submit.prevent="save(true)">
            <section class="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                <AppField v-slot="{ id }" :label="t(side === 'sales' ? 'accounting.documents.party_customer' : 'accounting.documents.party_vendor')" :error="fieldError('party_id')" class="sm:col-span-2">
                    <select :id="id" v-model="form.party_id" class="field-input">
                        <option value="">{{ t('accounting.documents.choose_party') }}</option>
                        <option v-for="party in parties" :key="party.id" :value="party.id">{{ party.name }}{{ party.code ? ` (${party.code})` : '' }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.documents.issue_date')" :error="fieldError('issue_date')">
                    <input :id="id" v-model="form.issue_date" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.documents.due_date')" :hint="t('accounting.documents.due_hint')" :error="fieldError('due_date')" optional>
                    <input :id="id" v-model="form.due_date" type="date" class="field-input" :min="form.issue_date" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.documents.reference')" :error="fieldError('reference')" optional class="sm:col-span-2">
                    <input :id="id" v-model="form.reference" class="field-input" maxlength="100" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.documents.notes')" :error="fieldError('notes')" optional class="sm:col-span-2">
                    <input :id="id" v-model="form.notes" class="field-input" maxlength="1000" />
                </AppField>
            </section>

            <section class="card">
                <div class="hidden gap-3 border-b border-line px-5 py-2 text-[12px] font-medium text-muted lg:grid lg:grid-cols-[minmax(0,1.5fr)_4.5rem_7rem_7rem_minmax(0,1.2fr)_8.5rem_minmax(0,1fr)]" aria-hidden="true">
                    <span>{{ t('accounting.documents.description') }}</span>
                    <span class="pe-3 text-end">{{ t('accounting.documents.quantity') }}</span>
                    <span class="pe-3 text-end">{{ t('accounting.documents.price') }}</span>
                    <span class="text-end">{{ t('accounting.documents.amount') }}</span>
                    <span>{{ t('accounting.documents.account') }}</span>
                    <span>{{ t('accounting.tax.vat') }}</span>
                    <span>{{ t('accounting.form.cost_centre') }}</span>
                </div>
                <ol class="divide-y divide-line">
                    <li v-for="(line, index) in form.lines" :key="index" class="grid grid-cols-2 gap-3 px-4 py-4 sm:px-5 lg:grid-cols-[minmax(0,1.5fr)_4.5rem_7rem_7rem_minmax(0,1.2fr)_8.5rem_minmax(0,1fr)] lg:items-start">
                        <AppField v-slot="{ id }" :label="t('accounting.documents.description')" :error="fieldError(`lines.${index}.description`)" class="col-span-2 lg:col-span-1 lg:[&_label]:sr-only">
                            <input :id="id" v-model="line.description" class="field-input" maxlength="255" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('accounting.documents.quantity')" :error="fieldError(`lines.${index}.quantity`)" class="lg:[&_label]:sr-only">
                            <input :id="id" v-model="line.quantity" inputmode="decimal" class="field-input tabular text-end" dir="ltr" autocomplete="off" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('accounting.documents.price')" :error="fieldError(`lines.${index}.unit_price_minor`)" class="lg:[&_label]:sr-only">
                            <input :id="id" v-model="line.price" inputmode="decimal" class="field-input tabular text-end" dir="ltr" autocomplete="off" />
                        </AppField>
                        <div class="tabular col-span-2 flex items-center justify-between text-[13.5px] font-medium lg:col-span-1 lg:justify-end lg:pt-2.5">
                            <span class="text-muted lg:hidden">{{ t('accounting.documents.amount') }}</span>
                            <span :class="totals.amounts[index] === null ? 'text-bad' : ''">{{ totals.amounts[index] === null ? '—' : money(totals.amounts[index]) }}</span>
                        </div>
                        <AppField v-slot="{ id }" :label="t('accounting.documents.account')" :error="fieldError(`lines.${index}.account_id`)" class="col-span-2 lg:col-span-1 lg:[&_label]:sr-only">
                            <select :id="id" v-model="line.account_id" class="field-input">
                                <option value="">{{ t('accounting.form.choose_account') }}</option>
                                <option v-for="account in lineAccounts" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('accounting.tax.vat')" :error="fieldError(`lines.${index}.tax_code_id`)" class="col-span-2 lg:col-span-1 lg:[&_label]:sr-only">
                            <select :id="id" v-model="line.tax_code_id" class="field-input" :disabled="!lineTaxCodes.length">
                                <option value="">{{ t('accounting.tax.none') }}</option>
                                <option v-for="code in lineTaxCodes" :key="code.id" :value="code.id">{{ code.name }}</option>
                            </select>
                        </AppField>
                        <div class="col-span-2 flex items-center justify-between gap-2 lg:col-span-1">
                            <select v-if="units.length" v-model="line.cost_centre_id" class="field-input min-w-0 flex-1" :aria-label="t('accounting.form.cost_centre')">
                                <option value="">{{ t('accounting.form.no_cost_centre') }}</option>
                                <option v-for="unit in units" :key="unit.id" :value="unit.id">{{ unit.display_name }}</option>
                            </select>
                            <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('accounting.documents.remove_line', { number: formatNumber(index + 1) })" @click="removeLine(index)" />
                        </div>
                        <p v-if="totals.amounts[index] === null && (line.price || line.quantity !== '1')" class="col-span-2 text-[12.5px] text-bad lg:col-span-6">{{ t('accounting.documents.invalid_line') }}</p>
                    </li>
                </ol>
                <div class="flex items-center justify-between border-t border-line px-4 py-3 sm:px-5">
                    <AppButton size="sm" variant="ghost" :icon="Plus" @click="form.lines.push(blankLine())">{{ t('accounting.documents.add_line') }}</AppButton>
                    <span class="flex flex-wrap items-baseline justify-end gap-x-4 gap-y-1 text-[13px]" aria-live="polite">
                        <span v-if="totals.tax"><span class="text-muted">{{ t('accounting.tax.net') }}:</span> <span class="tabular">{{ money(totals.net) }}</span></span>
                        <span v-if="totals.tax"><span class="text-muted">{{ t('accounting.tax.vat') }}:</span> <span class="tabular">{{ money(totals.tax) }}</span></span>
                        <span class="text-[14px]"><span class="text-muted">{{ t('accounting.documents.total') }}:</span> <span class="tabular font-semibold">{{ money(totals.total) }}</span></span>
                    </span>
                </div>
            </section>

            <div class="flex flex-wrap justify-end gap-2">
                <AppButton :loading="saving === 'draft'" :disabled="saving !== null || !form.party_id" @click="save(false)">{{ t('accounting.documents.save_draft') }}</AppButton>
                <AppButton variant="primary" type="submit" :icon="Send" :loading="saving === 'send'" :disabled="saving !== null || !ready">{{ t('accounting.documents.send') }}</AppButton>
            </div>
        </form>
    </div>
</template>
