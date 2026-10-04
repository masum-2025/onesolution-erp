<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Banknote, History, Landmark, PenLine } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { amountToMinor, minorToText } from '../lib';

/**
 * One employee's pay: salary history (a new salary from a day closes the
 * one before it) and where they are paid. The account number is shown
 * masked; typing a new one replaces it.
 */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const route = useRoute();
const record = useResource(() => payroll.employee(route.params.id));
const structureList = useResource(() => payroll.structures());
const data = computed(() => record.data.value?.data ?? null);
const currency = computed(() => record.data.value?.meta?.currency);
const structures = computed(() => (structureList.data.value?.data ?? []).filter((structure) => structure.is_active));
const structureName = (id) => (structureList.data.value?.data ?? []).find((structure) => structure.id === id)?.name ?? '—';
const money = (amount) => formatMoney({ amount, currency: currency.value });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const today = new Date().toISOString().slice(0, 10);
const current = computed(() => (data.value?.salaries ?? []).find((salary) => salary.effective_from <= today && (!salary.effective_to || salary.effective_to >= today)));

// A new salary.
const salaryOpen = ref(false);
const salary = reactive({ structure_id: '', basic: '', effective_from: today, reason: '' });
const salaryErrors = ref({});
const saving = ref(false);
function editSalary() {
    Object.assign(salary, { structure_id: current.value?.structure_id ?? structures.value[0]?.id ?? '', basic: minorToText(current.value?.basic_minor ?? null, currency.value), effective_from: today, reason: '' });
    salaryErrors.value = {};
    salaryOpen.value = true;
}
async function saveSalary() {
    const basic = amountToMinor(salary.basic, currency.value);
    if (basic === null) {
        salaryErrors.value = { basic_minor: [t('payroll.run.bad_amount')] };
        return;
    }
    saving.value = true;
    salaryErrors.value = {};
    try {
        await payroll.setSalary(route.params.id, { structure_id: salary.structure_id, basic_minor: basic, effective_from: salary.effective_from, reason: salary.reason.trim() || null });
        toast.success(t('payroll.pay.salary_saved'));
        salaryOpen.value = false;
        record.reload();
    } catch (error) {
        salaryErrors.value = error.errors ?? {};
        if (!Object.keys(salaryErrors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

// Where they are paid.
const paymentOpen = ref(false);
const payment = reactive({ method: 'bank', provider: '', account_name: '', account_number: '', branch: '' });
const paymentErrors = ref({});
function editPayment() {
    const found = data.value.payment;
    Object.assign(payment, { method: found?.method ?? 'bank', provider: found?.provider ?? '', account_name: found?.account_name ?? data.value.employee.name, account_number: '', branch: found?.branch ?? '' });
    paymentErrors.value = {};
    paymentOpen.value = true;
}
async function savePayment() {
    const cash = payment.method === 'cash';
    const body = { method: payment.method };
    if (!cash) {
        Object.assign(body, { provider: payment.provider.trim() || null, account_name: payment.account_name.trim() || null, branch: payment.branch.trim() || null });
        // Empty keeps the number already on file.
        if (payment.account_number.trim()) body.account_number = payment.account_number.trim();
    }
    saving.value = true;
    paymentErrors.value = {};
    try {
        await payroll.setPayment(route.params.id, body);
        toast.success(t('payroll.pay.payment_saved'));
        paymentOpen.value = false;
        record.reload();
    } catch (error) {
        paymentErrors.value = error.errors ?? {};
        if (!Object.keys(paymentErrors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'payroll-employees' }" :icon="ArrowLeft" class="mb-3">{{ t('payroll.employees.title') }}</AppButton>
        <SkeletonRows v-if="record.loading.value && !data" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="data">
            <PageHeader :title="data.employee.name" :description="t('payroll.pay.text')">
                <template #eyebrow><span class="mb-1 block font-mono text-[12.5px] text-muted" dir="ltr">{{ data.employee.code }}</span></template>
            </PageHeader>

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <section class="card">
                    <header class="flex items-center justify-between border-b border-line px-5 py-3">
                        <h2 class="flex items-center gap-2 text-[14.5px] font-semibold"><Banknote class="size-4 text-muted" aria-hidden="true" />{{ t('payroll.pay.salary') }}</h2>
                        <AppButton v-if="can('payroll.run')" size="sm" variant="ghost" :icon="PenLine" :disabled="!structures.length" @click="editSalary">{{ t('payroll.pay.change_salary') }}</AppButton>
                    </header>
                    <div class="p-5">
                        <template v-if="current">
                            <p class="tabular text-[26px] font-semibold">{{ money(current.basic_minor) }}</p>
                            <p class="text-[13px] text-muted">{{ t('payroll.pay.basic_since', { structure: structureName(current.structure_id), date: day(current.effective_from) }) }}</p>
                        </template>
                        <p v-else class="text-[13.5px] text-warn">{{ t('payroll.pay.no_salary') }}</p>
                        <p v-if="!structures.length && can('payroll.run')" class="mt-2 text-[12.5px] text-muted">
                            {{ t('payroll.pay.need_structure') }}
                            <RouterLink :to="{ name: 'payroll-structures' }" class="font-medium text-brand-text hover:underline">{{ t('payroll.structures.title') }}</RouterLink>
                        </p>
                    </div>
                </section>

                <section class="card">
                    <header class="flex items-center justify-between border-b border-line px-5 py-3">
                        <h2 class="flex items-center gap-2 text-[14.5px] font-semibold"><Landmark class="size-4 text-muted" aria-hidden="true" />{{ t('payroll.pay.payment') }}</h2>
                        <AppButton v-if="can('payroll.run')" size="sm" variant="ghost" :icon="PenLine" @click="editPayment">{{ t('payroll.pay.change_payment') }}</AppButton>
                    </header>
                    <dl v-if="data.payment" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 p-5 text-[13.5px]">
                        <dt class="text-muted">{{ t('payroll.pay.method') }}</dt><dd>{{ t(`payroll.methods.${data.payment.method}`) }}</dd>
                        <template v-if="data.payment.method !== 'cash'">
                            <dt class="text-muted">{{ t('payroll.pay.provider') }}</dt><dd>{{ data.payment.provider ?? '—' }}</dd>
                            <dt class="text-muted">{{ t('payroll.pay.account_name') }}</dt><dd>{{ data.payment.account_name ?? '—' }}</dd>
                            <dt class="text-muted">{{ t('payroll.pay.account_number') }}</dt><dd class="font-mono" dir="ltr">{{ data.payment.account_number ?? '—' }}</dd>
                            <template v-if="data.payment.branch"><dt class="text-muted">{{ t('payroll.pay.branch') }}</dt><dd>{{ data.payment.branch }}</dd></template>
                        </template>
                    </dl>
                    <p v-else class="p-5 text-[13.5px] text-muted">{{ t('payroll.pay.no_payment') }}</p>
                </section>
            </div>

            <section class="card mt-5">
                <h2 class="flex items-center gap-2 border-b border-line px-5 py-3 text-[14.5px] font-semibold"><History class="size-4 text-muted" aria-hidden="true" />{{ t('payroll.pay.history') }}</h2>
                <p v-if="!data.salaries.length" class="px-5 py-4 text-[13px] text-muted">{{ t('payroll.pay.no_history') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in data.salaries" :key="item.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-[13.5px]">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ structureName(item.structure_id) }}</span>
                            <span class="block text-[12.5px] text-muted">
                                {{ item.effective_to ? t('payroll.pay.from_to', { from: day(item.effective_from), to: day(item.effective_to) }) : t('payroll.pay.from', { from: day(item.effective_from) }) }}
                                <template v-if="item.reason"> · {{ item.reason }}</template>
                            </span>
                        </span>
                        <AppBadge v-if="item === current" tone="ok" dot>{{ t('payroll.pay.current') }}</AppBadge>
                        <span class="tabular w-32 text-end font-semibold">{{ money(item.basic_minor) }}</span>
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="salaryOpen" :title="t('payroll.pay.change_salary')" :description="t('payroll.pay.change_salary_text')" :icon="Banknote" @close="salaryOpen = false">
            <form id="payroll-salary" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveSalary">
                <AppField v-slot="{ id }" :label="t('payroll.pay.structure')" :error="salaryErrors.structure_id?.[0]" class="sm:col-span-2">
                    <select :id="id" v-model="salary.structure_id" class="field-input">
                        <option v-for="structure in structures" :key="structure.id" :value="structure.id">{{ structure.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.pay.basic')" :error="salaryErrors.basic_minor?.[0]">
                    <input :id="id" v-model="salary.basic" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.pay.effective_from')" :error="salaryErrors.effective_from?.[0]">
                    <input :id="id" v-model="salary.effective_from" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('payroll.pay.reason')" :error="salaryErrors.reason?.[0]" optional class="sm:col-span-2">
                    <input :id="id" v-model="salary.reason" class="field-input" maxlength="300" :placeholder="t('payroll.pay.reason_hint')" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="salaryOpen = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-salary" :loading="saving" :disabled="!salary.structure_id || !salary.basic || !salary.effective_from">{{ t('payroll.common.save') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="paymentOpen" :title="t('payroll.pay.change_payment')" :icon="Landmark" @close="paymentOpen = false">
            <form id="payroll-payment" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="savePayment">
                <AppField v-slot="{ id }" :label="t('payroll.pay.method')" :error="paymentErrors.method?.[0]" class="sm:col-span-2">
                    <select :id="id" v-model="payment.method" class="field-input">
                        <option v-for="method in ['bank', 'mobile', 'cash']" :key="method" :value="method">{{ t(`payroll.methods.${method}`) }}</option>
                    </select>
                </AppField>
                <template v-if="payment.method !== 'cash'">
                    <AppField v-slot="{ id }" :label="t(payment.method === 'bank' ? 'payroll.pay.bank' : 'payroll.pay.wallet')" :error="paymentErrors.provider?.[0]">
                        <input :id="id" v-model="payment.provider" class="field-input" maxlength="100" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('payroll.pay.account_name')" :error="paymentErrors.account_name?.[0]">
                        <input :id="id" v-model="payment.account_name" class="field-input" maxlength="150" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('payroll.pay.account_number')" :hint="data?.payment?.account_number ? t('payroll.pay.number_kept', { masked: data.payment.account_number }) : ''" :error="paymentErrors.account_number?.[0]">
                        <input :id="id" v-model="payment.account_number" class="field-input font-mono" dir="ltr" maxlength="40" autocomplete="off" />
                    </AppField>
                    <AppField v-if="payment.method === 'bank'" v-slot="{ id }" :label="t('payroll.pay.branch')" :error="paymentErrors.branch?.[0]" optional>
                        <input :id="id" v-model="payment.branch" class="field-input" maxlength="150" />
                    </AppField>
                </template>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="paymentOpen = false">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-payment" :loading="saving">{{ t('payroll.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
