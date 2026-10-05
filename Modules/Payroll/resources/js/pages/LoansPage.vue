<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { HandCoins, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { amountToMinor, firstRecoveryMonth, installmentOf, loanTone } from '../lib';

/**
 * Loans and advances: waiting for approval first, running ones with what
 * is left, and asking for a new one (the instalment is shown as it is typed;
 * the server checks the limits again).
 */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const router = useRouter();
const status = ref('');
const list = useResource(() => payroll.loans(status.value ? { status: status.value } : {}));
watch(status, () => list.reload());
const loans = computed(() => list.data.value?.data ?? []);
const currency = computed(() => list.data.value?.meta?.currency);
const money = (amount, code = currency.value) => formatMoney({ amount, currency: code });
const filters = computed(() => ['', 'pending_approval', 'active', 'closed'].map((value) => ({ value, label: value ? t(`payroll.loan_status.${value}`) : t('payroll.loans.all') })));

// Asking for one.
const asking = ref(false);
const employees = useResource(() => payroll.employees(''), { immediate: false });
const people = computed(() => (employees.data.value?.data ?? []).filter((person) => person.status !== 'exited'));
const today = new Date().toISOString().slice(0, 10);
const form = reactive({ employee_id: '', kind: 'loan', amount: '', installments: 6, paid_out_on: today, start_period: firstRecoveryMonth(today), reason: '' });
const errors = ref({});
const saving = ref(false);
const preview = computed(() => {
    const amount = amountToMinor(form.amount, currency.value);
    return amount ? installmentOf(amount, form.installments) : null;
});

function ask() {
    Object.assign(form, { employee_id: '', kind: 'loan', amount: '', installments: 6, paid_out_on: today, start_period: firstRecoveryMonth(today), reason: '' });
    errors.value = {};
    asking.value = true;
    if (!employees.data.value) employees.reload();
}
watch(() => form.kind, (kind) => {
    if (kind === 'advance') form.installments = 1;
});
watch(() => form.paid_out_on, (day) => {
    form.start_period = firstRecoveryMonth(day);
});

async function save() {
    const principal = amountToMinor(form.amount, currency.value);
    if (!principal) {
        errors.value = { principal_minor: [t('payroll.run.bad_amount')] };
        return;
    }
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await payroll.requestLoan({
            employee_id: form.employee_id, kind: form.kind, principal_minor: principal, installments: Number(form.installments) || 1,
            start_period: form.start_period, paid_out_on: form.paid_out_on, reason: form.reason.trim() || null,
        });
        toast.success(t('payroll.loans.asked'));
        router.push({ name: 'payroll-loan', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const month = (period) => formatDate(`${period}-01T00:00:00Z`, { month: 'short', year: 'numeric', timeZone: 'UTC' });
</script>

<template>
    <div>
        <PageHeader :title="t('payroll.loans.title')" :description="t('payroll.loans.text')">
            <template #actions>
                <AppButton v-if="can('payroll.run')" variant="primary" :icon="Plus" @click="ask">{{ t('payroll.loans.ask') }}</AppButton>
            </template>
        </PageHeader>

        <AppSegmented v-model="status" :options="filters" class="mb-4" :label="t('payroll.loans.filter')" />

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!loans.length" :icon="HandCoins" :title="t('payroll.loans.empty')" :text="t('payroll.loans.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="loan in loans" :key="loan.id">
                    <RouterLink :to="{ name: 'payroll-loan', params: { id: loan.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ loan.employee_name }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ loan.employee_code }}</span></span>
                            <span class="block text-[12.5px] text-muted">
                                {{ t(`payroll.loan_kinds.${loan.kind}`) }} · {{ money(loan.principal_minor, loan.currency) }}
                                · {{ t('payroll.loans.per_month', { amount: money(loan.installment_minor, loan.currency), from: month(loan.start_period) }) }}
                            </span>
                        </span>
                        <AppBadge :tone="loanTone(loan.status)" dot>{{ t(`payroll.loan_status.${loan.status}`) }}</AppBadge>
                        <span v-if="loan.status === 'active'" class="tabular w-36 text-end text-[13px]">
                            <span class="block font-semibold">{{ money(loan.balance_minor, loan.currency) }}</span>
                            <span class="block text-[11.5px] text-muted">{{ t('payroll.loans.left') }}</span>
                        </span>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <AppDialog :open="asking" :title="t('payroll.loans.ask')" :description="t('payroll.loans.ask_text')" :icon="HandCoins" @close="asking = false">
            <form id="payroll-loan" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('payroll.run.employee')" :error="errors.employee_id?.[0]" class="sm:col-span-2">
                    <select :id="id" v-model="form.employee_id" class="field-input" :disabled="employees.loading.value">
                        <option value="" disabled>{{ employees.loading.value ? t('payroll.loans.loading_people') : t('payroll.loans.choose_person') }}</option>
                        <option v-for="person in people" :key="person.id" :value="person.id">{{ person.name }} · {{ person.code }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.run.kind')">
                    <select :id="id" v-model="form.kind" class="field-input">
                        <option value="loan">{{ t('payroll.loan_kinds.loan') }}</option>
                        <option value="advance">{{ t('payroll.loan_kinds.advance') }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.run.amount')" :error="errors.principal_minor?.[0]">
                    <input :id="id" v-model="form.amount" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.loans.installments')" :error="errors.installments?.[0]" :hint="preview ? t('payroll.loans.each', { amount: money(preview) }) : ''">
                    <input :id="id" v-model.number="form.installments" type="number" min="1" max="120" class="field-input tabular text-end" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.loans.paid_out_on')" :error="errors.paid_out_on?.[0]">
                    <input :id="id" v-model="form.paid_out_on" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.loans.start_period')" :error="errors.start_period?.[0]">
                    <input :id="id" v-model="form.start_period" type="month" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.pay.reason')" :error="errors.reason?.[0]" optional>
                    <input :id="id" v-model="form.reason" class="field-input" maxlength="300" :placeholder="t('payroll.loans.reason_hint')" />
                </AppField>
            </form>
            <p class="mt-3 text-[12.5px] text-muted">{{ t('payroll.loans.needs_approval') }}</p>
            <template #footer>
                <AppButton variant="ghost" @click="asking = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-loan" :loading="saving" :disabled="!form.employee_id || !form.amount || !form.paid_out_on || !form.start_period">{{ t('payroll.loans.send') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
