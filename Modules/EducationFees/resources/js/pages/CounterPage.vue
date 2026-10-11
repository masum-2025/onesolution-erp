<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowRight, Banknote, CircleCheck, HandCoins, Landmark, Printer, RotateCcw, Smartphone, Wallet } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { amountText, billState, newOpId, parseAmount, plan } from '../lib';
import { billTitle, day } from '../labels';
import StudentFinder from '../components/StudentFinder.vue';

/**
 * The fee counter: find a student, see their unpaid bills and advance, type
 * what they give and how; before saving, the screen shows which bills it
 * meets (oldest due first, as the server does) and what waits as an
 * advance. Then the receipt is one tap away.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();
const route = useRoute();
const router = useRouter();

const studentId = ref(route.query.student ?? null);
const statement = ref(null);
const loading = ref(false);
const loadError = ref(null);
async function load() {
    if (!studentId.value) {
        statement.value = null;
        return;
    }
    loading.value = true;
    loadError.value = null;
    try {
        statement.value = (await api.student(studentId.value)).data;
        form.amount = amountText(statement.value.owed_minor, setup.currency.value);
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}
watch(studentId, (id) => {
    router.replace({ query: id ? { student: id } : {} });
    done.value = null;
    load();
}, { immediate: true });

const bills = computed(() => (statement.value?.bills ?? []).filter((bill) => bill.status === 'open'));
const methods = computed(() => (setup.rules.value.payment_methods ?? ['cash']).map((value) => ({ value, label: t(`education_fees.methods.${value}`), icon: { cash: Banknote, bank: Landmark, mobile: Smartphone }[value] })));

const form = reactive({ amount: '', method: 'cash', reference: '', received_on: '', note: '' });
watch(() => setup.data.value, (data) => {
    if (!data) return;
    form.received_on ||= data.today;
    if (!data.rules.payment_methods?.includes(form.method)) form.method = data.rules.payment_methods?.[0] ?? 'cash';
}, { immediate: true });
const amount = computed(() => parseAmount(form.amount, setup.currency.value));
const preview = computed(() => (amount.value ? plan(bills.value, amount.value, setup.rules.value.allow_partial_payment !== false) : null));
const needsReference = computed(() => form.method !== 'cash');
const ready = computed(() => amount.value && !preview.value?.blocked && (!needsReference.value || form.reference.trim()) && form.received_on && setup.currency.value);

const saving = ref(false);
const errors = ref({});
const done = ref(null);
let opId = newOpId();
async function take() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.collect({
            student_id: studentId.value, amount_minor: amount.value, method: form.method, reference: needsReference.value ? form.reference.trim() : null,
            received_on: form.received_on, note: form.note.trim() || null, op_id: opId,
        });
        done.value = data;
        opId = newOpId();
        toast.success(t('education_fees.counter.taken', { number: data.number }));
        Object.assign(form, { reference: '', note: '' });
        load();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
function next() {
    studentId.value = null;
    done.value = null;
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_fees.counter.title')" :description="t('education_fees.counter.text')" />

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="5" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <template v-else-if="setup.data.value">
            <p v-if="!setup.currency.value" class="mb-4 rounded-xl border border-warn/30 bg-warn-soft px-4 py-3 text-[13px] text-fg">{{ t('education_fees.no_currency') }}</p>

            <!-- Who pays. -->
            <section v-if="!studentId" class="card mx-auto max-w-2xl p-5 sm:p-6">
                <StudentFinder @choose="studentId = $event.id" />
            </section>

            <template v-else>
                <SkeletonRows v-if="loading && !statement" :rows="5" />
                <ErrorState v-else-if="loadError" :error="loadError" @retry="load()" />
                <div v-else-if="statement" class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                    <div class="min-w-0 space-y-5">
                        <!-- The student and what they owe. -->
                        <section class="card overflow-hidden">
                            <div class="flex flex-wrap items-center gap-4 bg-gradient-to-br from-brand-soft to-transparent p-5">
                                <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand text-[18px] font-semibold text-brand-fg" aria-hidden="true">{{ statement.student.name.slice(0, 1) }}</span>
                                <div class="min-w-0 flex-1">
                                    <h2 class="truncate text-[17px] font-semibold text-fg">{{ statement.student.name }}</h2>
                                    <p class="font-mono text-[12.5px] text-muted" dir="ltr">{{ statement.student.code }}</p>
                                </div>
                                <AppButton size="sm" variant="ghost" :icon="RotateCcw" @click="next">{{ t('education_fees.counter.change') }}</AppButton>
                            </div>
                            <dl class="grid grid-cols-2 divide-x divide-line border-t border-line rtl:divide-x-reverse">
                                <div class="p-4">
                                    <dt class="text-[12px] text-muted">{{ t('education_fees.counter.owes') }}</dt>
                                    <dd class="tabular text-[22px] font-semibold" :class="statement.owed_minor ? 'text-fg' : 'text-ok'">{{ setup.money(statement.owed_minor) }}</dd>
                                </div>
                                <div class="p-4">
                                    <dt class="text-[12px] text-muted">{{ t('education_fees.counter.advance') }}</dt>
                                    <dd class="tabular text-[22px] font-semibold text-fg">{{ setup.money(statement.advance_minor) }}</dd>
                                </div>
                            </dl>
                        </section>

                        <section class="card">
                            <header class="flex items-center justify-between gap-2 border-b border-line px-5 py-3">
                                <h2 class="text-[13.5px] font-semibold text-fg">{{ t('education_fees.counter.bills_title') }}</h2>
                                <RouterLink :to="{ name: 'fees-student', params: { id: studentId } }" class="inline-flex items-center gap-1 text-[12.5px] font-medium text-brand-text hover:underline">
                                    {{ t('education_fees.counter.open_student') }} <ArrowRight class="size-3.5 rtl:-scale-x-100" aria-hidden="true" />
                                </RouterLink>
                            </header>
                            <p v-if="!bills.length" class="px-5 py-5 text-[13px] text-muted">{{ t('education_fees.counter.no_bills') }}</p>
                            <ul v-else class="divide-y divide-line">
                                <li v-for="bill in bills" :key="bill.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3">
                                    <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                                        <span class="block text-[14px] font-medium text-fg">{{ billTitle(bill) }}</span>
                                        <span class="block text-[12.5px] text-muted"><span class="whitespace-nowrap font-mono" dir="ltr">{{ bill.number }}</span> · {{ t('education_fees.counter.due', { date: day(bill.due_date) }) }}</span>
                                    </span>
                                    <AppBadge :tone="billState(bill).tone" dot>{{ t(`education_fees.bill_states.${billState(bill).key}`) }}</AppBadge>
                                    <span class="tabular ms-auto w-28 text-end text-[14px] font-semibold text-fg">{{ setup.money(bill.balance_minor) }}</span>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <!-- Taking the money. -->
                    <section class="card h-fit p-5 lg:sticky lg:top-20">
                        <div v-if="done" class="text-center">
                            <CircleCheck class="mx-auto size-12 text-ok" aria-hidden="true" />
                            <h2 class="mt-2 text-[16px] font-semibold text-fg">{{ t('education_fees.counter.taken_title') }}</h2>
                            <p class="tabular mt-1 text-[24px] font-semibold text-fg">{{ setup.money(done.amount_minor) }}</p>
                            <p class="font-mono text-[12.5px] text-muted" dir="ltr">{{ done.number }}</p>
                            <div class="mt-5 grid gap-2">
                                <AppButton variant="primary" :icon="Printer" :to="{ name: 'fees-receipt', params: { id: done.id }, query: { print: 1 } }">{{ t('education_fees.counter.print') }}</AppButton>
                                <AppButton :icon="RotateCcw" @click="next">{{ t('education_fees.counter.next') }}</AppButton>
                            </div>
                        </div>
                        <form v-else-if="setup.can('collect')" class="space-y-4" novalidate @submit.prevent="ready && take()">
                            <h2 class="flex items-center gap-2 text-[14px] font-semibold text-fg"><HandCoins class="size-4 text-brand-text" aria-hidden="true" />{{ t('education_fees.counter.take_title') }}</h2>
                            <AppField v-slot="{ id }" :label="t('education_fees.counter.amount')" :error="(form.amount && !amount ? t('education_fees.counter.amount_invalid') : null) ?? errors.amount_minor?.[0] ?? null">
                                <input :id="id" v-model="form.amount" inputmode="decimal" class="field-input tabular h-12 text-[20px] font-semibold" autocomplete="off" />
                            </AppField>
                            <AppField :label="t('education_fees.counter.method')" :error="errors.method?.[0] ?? null">
                                <AppSegmented v-model="form.method" :options="methods" />
                            </AppField>
                            <AppField v-if="needsReference" v-slot="{ id }" :label="t('education_fees.counter.reference')" :hint="t('education_fees.counter.reference_hint')" :error="errors.reference?.[0] ?? null">
                                <input :id="id" v-model="form.reference" class="field-input font-mono" maxlength="100" dir="ltr" />
                            </AppField>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <AppField v-slot="{ id }" :label="t('education_fees.counter.date')" :error="errors.received_on?.[0] ?? null">
                                    <input :id="id" v-model="form.received_on" type="date" class="field-input" :max="setup.today.value" />
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('education_fees.counter.note')">
                                    <input :id="id" v-model="form.note" class="field-input" maxlength="300" />
                                </AppField>
                            </div>

                            <!-- Where it goes, before saving. -->
                            <div v-if="preview" class="rounded-xl bg-subtle px-4 py-3 text-[13px]">
                                <p class="mb-1 font-medium text-fg">{{ t('education_fees.counter.goes_to') }}</p>
                                <p v-if="preview.blocked" class="text-bad">{{ t('education_fees.counter.blocked', { bill: preview.blocked }) }}</p>
                                <ul v-else class="space-y-0.5">
                                    <li v-for="part in preview.bills" :key="part.id" class="flex justify-between gap-2">
                                        <span><span class="font-mono" dir="ltr">{{ part.number }}</span> · {{ part.full ? t('education_fees.counter.in_full') : t('education_fees.counter.part') }}</span>
                                        <span class="tabular">{{ setup.money(part.amount) }}</span>
                                    </li>
                                    <li v-if="preview.advance" class="flex justify-between gap-2 text-brand-text">
                                        <span class="inline-flex items-center gap-1"><Wallet class="size-3.5" aria-hidden="true" />{{ t('education_fees.counter.to_advance') }}</span>
                                        <span class="tabular">{{ setup.money(preview.advance) }}</span>
                                    </li>
                                </ul>
                            </div>
                            <AppButton type="submit" variant="primary" class="h-12 w-full text-[15px]" :icon="HandCoins" :loading="saving" :disabled="!ready">
                                {{ t('education_fees.counter.take', { amount: setup.money(amount ?? 0) }) }}
                            </AppButton>
                        </form>
                    </section>
                </div>
            </template>
        </template>
    </div>
</template>
