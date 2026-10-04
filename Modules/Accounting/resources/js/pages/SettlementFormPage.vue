<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Send } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization, session } from '@/lib/session';
import { newOpId } from '@/lib/billing';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { accountTree, amountText, parseAmount, postableAccounts, todayIn } from '../lib';
import AllocationPicker from '../components/AllocationPicker.vue';

/**
 * Record money received from a customer (or paid to a vendor): when, into
 * or out of which account, how much, and which open documents it pays.
 * Coming from an invoice, that invoice is filled in. One op id per form, so
 * pressing twice records it once.
 */
const books = accountingApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const type = ref(route.query.type === 'payment' ? 'payment' : 'receipt');
const opId = newOpId();

const loading = ref(true);
const loadError = ref(null);
const saving = ref(false);
const errors = ref({});
const currency = ref('');
const parties = ref([]);
const accounts = ref([]);
const openDocuments = ref([]);
const shares = ref({});
const picker = ref(null);

const form = reactive({ party_id: route.query.party_id ?? '', settled_on: todayIn(session.me?.context?.settings?.timezone), account_id: '', amount: '', reference: '', memo: '' });
const amount = computed(() => parseAmount(form.amount, currency.value));
const partyName = computed(() => parties.value.find((party) => party.id === form.party_id)?.name ?? '');
// Money goes into or out of an asset account: cash, bank, wallet.
const moneyAccounts = computed(() => {
    const usable = new Set(postableAccounts(accounts.value).filter((account) => account.type === 'asset').map((account) => account.id));
    return accountTree(accounts.value).flat.filter((account) => usable.has(account.id));
});

onMounted(async () => {
    try {
        const [setup, list, chart] = await Promise.all([books.setup(), books.parties({ role: type.value === 'receipt' ? 'customers' : 'vendors', per_page: 100 }), books.accounts()]);
        currency.value = setup.data.currency;
        parties.value = list.data;
        accounts.value = chart.data;
        await loadOpen();
        // From an invoice: its balance, set against it.
        const from = openDocuments.value.find((document) => document.id === route.query.document_id);
        if (from) {
            form.amount = amountText(from.balance_minor, currency.value);
            shares.value = { [from.id]: form.amount };
        }
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
});

watch(() => form.party_id, () => loadOpen());

async function loadOpen() {
    openDocuments.value = form.party_id
        ? (await books.documents({ party_id: form.party_id, type: type.value === 'receipt' ? 'invoice' : 'bill', status: 'open', per_page: 100 })).data
        : [];
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await books.createSettlement({
            type: type.value,
            party_id: form.party_id,
            settled_on: form.settled_on,
            account_id: form.account_id,
            amount_minor: amount.value,
            reference: form.reference.trim() || null,
            memo: form.memo.trim() || null,
            op_id: opId,
            allocations: picker.value?.payload() ?? [],
        });
        toast.success(data.status === 'pending_approval' ? t('accounting.settlements.pending') : t('accounting.settlements.saved', { number: data.number }));
        router.push({ name: 'accounting-settlement', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t(`accounting.settlements.new_${type}`)" :description="t(`accounting.settlements.${type === 'receipt' ? 'receipts' : 'payments'}_text`)">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: type === 'receipt' ? 'accounting-receipts' : 'accounting-payments' }" :icon="ArrowLeft">{{ t('accounting.common.back') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="loading" class="card"><SkeletonRows :rows="6" /></section>
        <section v-else-if="loadError" class="card"><ErrorState compact :error="loadError" /></section>

        <form v-else class="grid gap-5" novalidate @submit.prevent="save">
            <section class="card grid gap-4 p-5 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t(type === 'receipt' ? 'accounting.documents.party_customer' : 'accounting.documents.party_vendor')" :error="fieldError('party_id')" class="sm:col-span-2">
                    <select :id="id" v-model="form.party_id" class="field-input">
                        <option value="">{{ t('accounting.documents.choose_party') }}</option>
                        <option v-for="party in parties" :key="party.id" :value="party.id">{{ party.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.settlements.date')" :error="fieldError('settled_on')">
                    <input :id="id" v-model="form.settled_on" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.settlements.amount')" :error="fieldError('amount_minor') ?? (form.amount && amount === null ? t('accounting.form.invalid_amount') : null)">
                    <input :id="id" v-model="form.amount" inputmode="decimal" class="field-input tabular text-end" dir="ltr" autocomplete="off" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.settlements.account')" :error="fieldError('account_id')">
                    <select :id="id" v-model="form.account_id" class="field-input">
                        <option value="">{{ t('accounting.form.choose_account') }}</option>
                        <option v-for="account in moneyAccounts" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.settlements.reference')" :error="fieldError('reference')" optional>
                    <input :id="id" v-model="form.reference" class="field-input" maxlength="100" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.settlements.memo')" :error="fieldError('memo')" optional class="sm:col-span-2">
                    <input :id="id" v-model="form.memo" class="field-input" maxlength="500" />
                </AppField>
            </section>

            <section v-if="form.party_id" class="card p-5">
                <h2 class="mb-3 text-[14px] font-semibold">{{ t('accounting.settlements.open_documents', { party: partyName }) }}</h2>
                <p v-if="!openDocuments.length" class="text-[13px] text-muted">{{ t('accounting.settlements.no_open') }}</p>
                <AllocationPicker v-else ref="picker" v-model="shares" :documents="openDocuments" :available="amount ?? 0" :currency="currency" :errors="errors" />
                <p v-if="fieldError('allocations')" class="mt-2 text-[12.5px] text-bad">{{ fieldError('allocations') }}</p>
            </section>

            <div class="flex justify-end">
                <AppButton variant="primary" type="submit" :icon="Send" :loading="saving" :disabled="!form.party_id || !form.account_id || !amount">{{ t(`accounting.settlements.new_${type}`) }}</AppButton>
            </div>
        </form>
    </div>
</template>
