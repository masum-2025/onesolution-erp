<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Banknote, ChevronLeft, ChevronRight, Scale, SlidersHorizontal } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import EmployeePicker from '../components/EmployeePicker.vue';
import { leaveApi } from '../api';
import { daysText, daysToHalves, halvesToDays } from '../lib';

/**
 * One person's leave in a leave year: given (accrued, carried, changed by
 * hand), taken, waiting and left, per kind. HR (leave.manage) changes a
 * balance by hand with a reason: a ledger line of its own, audited.
 */
const org = currentOrganization();
const leave = leaveApi(org.id);

const person = ref(null);
const year = ref(null);
const rows = ref(null);
const meta = ref({});
const loading = ref(false);
const failure = ref(null);

async function load() {
    if (!person.value) {
        rows.value = null;
        return;
    }
    loading.value = true;
    failure.value = null;
    try {
        const response = await leave.balances(person.value.id, year.value ?? undefined);
        rows.value = response.data;
        meta.value = response.meta ?? {};
        year.value = response.meta?.year ?? year.value;
    } catch (error) {
        failure.value = error;
    } finally {
        loading.value = false;
    }
}
watch(person, () => {
    year.value = null;
    load();
});

function changeYear(by) {
    year.value += by;
    load();
}

const adjusting = ref(null);
const form = reactive({ sign: 1, days: 1, note: '' });
const errors = ref({});
const saving = ref(false);

function adjust(row) {
    Object.assign(form, { sign: 1, days: 1, note: '' });
    errors.value = {};
    adjusting.value = row;
}

const halves = computed(() => form.sign * daysToHalves(form.days));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await leave.adjust({ employee_id: person.value.id, type_id: adjusting.value.type_id, leave_year: year.value, halves: halves.value, note: form.note.trim() });
        toast.success(t('leave.balances.adjusted'));
        adjusting.value = null;
        load();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;

// Paying unused leave out: HR asks, someone else approves on the "Paid out" page.
const paying = ref(null);
const pay = reactive({ days: 1, reason: '' });

function payOut(row) {
    Object.assign(pay, { days: halvesToDays(row.available_halves), reason: '' });
    errors.value = {};
    paying.value = row;
}

async function sendPayOut() {
    saving.value = true;
    errors.value = {};
    try {
        await leave.encash({ employee_id: person.value.id, type_id: paying.value.type_id, leave_year: year.value, halves: daysToHalves(pay.days), reason: pay.reason.trim() });
        toast.success(t('leave.balances.pay_out_sent'));
        paying.value = null;
        load();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('leave.balances.title')" :description="t('leave.balances.text')" />

        <section class="card p-5 sm:p-6">
            <EmployeePicker v-model="person" :search="leave.people" :label="t('leave.balances.person')" />
        </section>

        <section v-if="person" class="card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
                <h2 class="text-[15px] font-semibold">{{ person.name }}</h2>
                <div v-if="year" class="flex items-center gap-1">
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronLeft" :aria-label="t('leave.balances.previous')" @click="changeYear(-1)" />
                    <span class="min-w-24 text-center text-[13.5px] font-medium tabular" aria-live="polite">{{ t('leave.balances.year', { year: formatNumber(year, { useGrouping: false }) }) }}</span>
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronRight" :aria-label="t('leave.balances.next')" @click="changeYear(1)" />
                </div>
            </div>
            <SkeletonRows v-if="loading" :rows="4" />
            <ErrorState v-else-if="failure" compact :error="failure" @retry="load()" />
            <EmptyState v-else-if="!rows?.length" :icon="Scale" :title="t('leave.balances.empty')" compact />
            <div v-else class="overflow-x-auto">
                <table class="w-full text-[13.5px]">
                    <thead class="text-[12px] text-muted">
                        <tr class="border-b border-line">
                            <th scope="col" class="px-5 py-2 text-start font-medium">{{ t('leave.balances.type') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('leave.balances.given') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('leave.balances.taken') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('leave.balances.paid_out') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('leave.balances.waiting') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ t('leave.balances.left') }}</th>
                            <th v-if="meta.can_adjust" scope="col"><span class="sr-only">{{ t('leave.balances.adjust') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in rows" :key="row.type_id">
                            <th scope="row" class="px-5 py-2.5 text-start font-medium">
                                {{ row.name }}
                                <span v-if="!row.paid" class="ms-1 text-[12px] font-normal text-muted">· {{ t('leave.common.unpaid') }}</span>
                            </th>
                            <td class="px-3 py-2.5 text-end tabular">{{ daysText(row.given_halves) }}</td>
                            <td class="px-3 py-2.5 text-end tabular">{{ daysText(row.taken_halves) }}</td>
                            <td class="px-3 py-2.5 text-end tabular">{{ daysText(row.encashed_halves) }}</td>
                            <td class="px-3 py-2.5 text-end tabular">{{ daysText(row.waiting_halves) }}</td>
                            <td class="px-3 py-2.5 text-end font-semibold tabular" :class="row.available_halves < 0 ? 'text-bad' : ''">{{ daysText(row.available_halves) }}</td>
                            <td v-if="meta.can_adjust" class="px-3 py-2.5 text-end">
                                <span class="inline-flex flex-wrap justify-end gap-1">
                                    <AppButton v-if="row.encashable && row.paid && row.available_halves > 0" size="sm" variant="ghost" :icon="Banknote" @click="payOut(row)">{{ t('leave.balances.pay_out') }}</AppButton>
                                    <AppButton size="sm" variant="ghost" :icon="SlidersHorizontal" @click="adjust(row)">{{ t('leave.balances.adjust') }}</AppButton>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="border-t border-line px-5 py-3 text-[12.5px] text-muted">{{ t('leave.balances.days_note') }}</p>
            </div>
        </section>

        <AppDialog :open="adjusting !== null" :title="t('leave.balances.adjust_title')" :description="adjusting ? t('leave.balances.adjust_text', { type: adjusting.name, name: person?.name }) : ''" @close="adjusting = null">
            <form id="leave-adjust" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('leave.balances.direction')">
                    <select :id="id" v-model.number="form.sign" class="field-input">
                        <option :value="1">{{ t('leave.balances.add') }}</option>
                        <option :value="-1">{{ t('leave.balances.remove') }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('leave.balances.days')" :hint="t('leave.balances.days_hint')" :error="fieldError('halves')">
                    <input :id="id" v-model.number="form.days" type="number" min="0.5" max="365" step="0.5" class="field-input tabular text-end" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('leave.balances.reason')" :error="fieldError('note')" class="sm:col-span-2">
                    <textarea :id="id" v-model="form.note" rows="3" class="field-input" maxlength="500"></textarea>
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="adjusting = null">{{ t('leave.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="leave-adjust" :loading="saving" :disabled="halves === 0 || form.note.trim().length < 3">{{ t('leave.common.save') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="paying !== null" :title="t('leave.balances.pay_out_title')" :description="paying ? t('leave.balances.pay_out_text', { type: paying.name, name: person?.name, days: daysText(paying.available_halves) }) : ''" :icon="Banknote" @close="paying = null">
            <form id="leave-pay-out" class="grid gap-4" novalidate @submit.prevent="sendPayOut">
                <AppField v-slot="{ id }" :label="t('leave.balances.days')" :hint="t('leave.balances.days_hint')" :error="fieldError('halves')">
                    <input :id="id" v-model.number="pay.days" type="number" min="0.5" :max="paying ? halvesToDays(paying.available_halves) : 365" step="0.5" class="field-input tabular text-end" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('leave.balances.reason')" :error="fieldError('reason')">
                    <textarea :id="id" v-model="pay.reason" rows="3" class="field-input" maxlength="500"></textarea>
                </AppField>
                <p class="text-[12.5px] text-muted">{{ t('leave.balances.pay_out_note') }}</p>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="paying = null">{{ t('leave.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="leave-pay-out" :loading="saving" :disabled="daysToHalves(pay.days) <= 0 || pay.reason.trim().length < 3">{{ t('leave.balances.pay_out_send') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
