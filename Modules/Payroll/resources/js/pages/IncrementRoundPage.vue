<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Ban, Check, PenLine, RefreshCw, Send, TrendingUp, Undo2, X } from 'lucide-vue-next';
import { MoneyInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { textIn } from '@/lib/texts';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { payrollApi } from '../api';
import { bpToPercent, incrementTone, percentText, percentToBp, raiseBy } from '../lib';

/**
 * One increment round: each employee's band, basic now, rate (from the rule,
 * by hand or none) and new basic; the steps the reader may take. Salaries
 * are shown to payroll people only (the server checks payroll.view).
 */
const org = currentOrganization();
const payroll = payrollApi(org.id);
const route = useRoute();
const record = useResource(() => payroll.increment(route.params.id));
const round = computed(() => record.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: round.value?.currency });
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const busy = ref(null);

const bands = computed(() => round.value?.bands ?? []);
const bandName = (key) => (key ? textIn(bands.value.find((band) => band.key === key)?.name) || key : t('payroll.increments.no_band'));
const band = ref('');
const lines = computed(() => (round.value?.lines ?? []).filter((line) => !band.value || (band.value === '-' ? !line.band : line.band === band.value)));
const raised = computed(() => (round.value?.lines ?? []).filter((line) => !line.excluded && line.new_minor > line.current_minor));
const sourceText = (line) => (line.excluded ? t('payroll.increments.left_out') : t(`payroll.increments.${{ rule: 'from_rule', hand: 'by_hand', none: 'none' }[line.rate_source]}`));

// Export and print (payroll.export): the lines shown.
const exported = computed(() => (round.value ? {
    module: 'payroll',
    page: 'payroll.increment',
    columns: [
        { key: 'employee_code', label: t('payroll.export.code') },
        { key: 'employee_name', label: t('payroll.increments.columns.employee') },
        { key: 'band', label: t('payroll.increments.columns.band'), value: (line) => bandName(line.band) },
        { key: 'current_minor', label: t('payroll.increments.columns.now'), type: 'money', currency: round.value.currency },
        { key: 'rate_bp', label: t('payroll.increments.rate'), type: 'number', value: (line) => line.rate_bp / 100 },
        { key: 'new_minor', label: t('payroll.increments.columns.new'), type: 'money', currency: round.value.currency },
        { key: 'source', label: t('payroll.export.status'), value: sourceText },
        { key: 'reason', label: t('payroll.increments.reason') },
    ],
    rows: lines.value,
} : null));

async function step(name, body = {}) {
    busy.value = name;
    try {
        await payroll.incrementStep(round.value.id, name, { base_version: round.value.version, ...body });
        toast.success(t(`payroll.increments.done.${name}`));
        record.reload();
    } catch (error) {
        if (error.code === 'version_conflict') record.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function confirmStep(name) {
    const confirmed = await confirmAction({
        title: t(`payroll.increments.confirm.${name}_title`, { title: round.value.title }),
        message: name === 'apply' ? t('payroll.increments.confirm.apply_text', { count: formatNumber(round.value.employees), date: day(round.value.effective_from) })
            : name === 'cancel' ? t('payroll.increments.confirm.cancel_text') : '',
        confirmLabel: t(`payroll.increments.steps.${name}`),
        reason: name === 'reject' ? 'required' : 'none',
        danger: name !== 'apply',
    });
    if (confirmed) await step(name, name === 'reject' ? { reason: confirmed.reason } : {});
}

// One line changed by hand: a rate or a new basic, with a reason; or left out; or back on the rule.
const editing = ref(null);
const form = reactive({ excluded: false, how: 'rate', percent: '', new_minor: null, reason: '' });
const errors = ref({});
const saving = ref(false);
const previewRate = computed(() => percentToBp(form.percent));
const preview = computed(() => (form.how === 'rate' ? (previewRate.value === null ? null : raiseBy(editing.value?.current_minor, previewRate.value)) : form.new_minor));

function edit(line) {
    Object.assign(form, { excluded: line.excluded, how: 'rate', percent: line.rate_source === 'hand' ? bpToPercent(line.rate_bp) : '', new_minor: null, reason: line.reason ?? '' });
    errors.value = {};
    editing.value = line;
}

async function saveLine(back = false) {
    const body = { excluded: form.excluded };
    if (back) Object.assign(body, { rate_bp: null, new_minor: null });
    else if (form.how === 'rate' && form.percent.trim() !== '') {
        if (previewRate.value === null) {
            errors.value = { rate_bp: [t('payroll.structures.bad_value')] };
            return;
        }
        Object.assign(body, { rate_bp: previewRate.value, reason: form.reason.trim() || null });
    } else if (form.how === 'amount' && form.new_minor !== null) {
        Object.assign(body, { new_minor: form.new_minor, reason: form.reason.trim() || null });
    }
    saving.value = true;
    errors.value = {};
    try {
        await payroll.changeIncrementLine(round.value.id, editing.value.id, body);
        toast.success(t('payroll.increments.saved'));
        editing.value = null;
        record.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageFrame :title="round ? round.title : ''" :description="round ? day(round.effective_from) : ''" :resource="record" :skeleton-rows="6" :export="exported" width="full">
        <template #before>
            <AppButton variant="ghost" size="sm" :to="{ name: 'payroll-increments' }" :icon="ArrowLeft" class="mb-3">{{ t('payroll.increments.title') }}</AppButton>
        </template>
        <template #eyebrow>
            <span class="mb-2 flex flex-wrap items-center gap-2">
                <AppBadge :tone="incrementTone(round.status)" dot>{{ t(`payroll.increments.statuses.${round.status}`) }}</AppBadge>
                <span v-if="round.status === 'pending_approval'" class="text-[12.5px] text-muted">{{ t('payroll.increments.levels', { done: formatNumber(round.approvals), needed: formatNumber(round.approvals_needed) }) }}</span>
            </span>
        </template>
        <template #actions>
            <AppButton v-if="round.can?.fill" :icon="RefreshCw" :loading="busy === 'fill'" @click="step('fill')">{{ t('payroll.increments.steps.fill') }}</AppButton>
            <AppButton v-if="round.can?.submit" variant="primary" :icon="Send" :loading="busy === 'submit'" @click="step('submit')">{{ t('payroll.increments.steps.submit') }}</AppButton>
            <AppButton v-if="round.can?.approve" variant="primary" :icon="Check" :loading="busy === 'approve'" @click="step('approve')">{{ t('payroll.increments.steps.approve') }}</AppButton>
            <AppButton v-if="round.can?.reject" :icon="Undo2" @click="confirmStep('reject')">{{ t('payroll.increments.steps.reject') }}</AppButton>
            <AppButton v-if="round.can?.apply" variant="primary" :icon="TrendingUp" :loading="busy === 'apply'" @click="confirmStep('apply')">{{ t('payroll.increments.steps.apply') }}</AppButton>
            <AppButton v-if="round.can?.cancel" variant="ghost" :icon="Ban" @click="confirmStep('cancel')">{{ t('payroll.increments.steps.cancel') }}</AppButton>
        </template>

        <template v-if="round">
            <p v-if="round.reject_reason && round.status === 'draft'" class="mb-4 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn" role="status">{{ t('payroll.increments.reject_reason', { reason: round.reject_reason }) }}</p>

            <section class="card mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 px-5 py-4">
                <p class="text-[14px] font-semibold">{{ t('payroll.increments.people', { count: formatNumber(raised.length) }) }}</p>
                <p class="tabular text-[13.5px] text-fg-2">{{ t('payroll.increments.totals', { from: money(round.current_minor), to: money(round.new_minor), raise: money(round.new_minor - round.current_minor) }) }}</p>
                <AppField v-if="bands.length" v-slot="{ id }" :label="t('payroll.increments.filter_band')" sr-only-label class="ms-auto w-full print:hidden sm:w-52">
                    <select :id="id" v-model="band" class="field-input">
                        <option value="">{{ t('payroll.increments.all_bands') }}</option>
                        <option v-for="item in bands" :key="item.key" :value="item.key">{{ textIn(item.name) }}</option>
                        <option value="-">{{ t('payroll.increments.no_band') }}</option>
                    </select>
                </AppField>
            </section>

            <section v-if="!(round.lines ?? []).length" class="card">
                <EmptyState :icon="TrendingUp" :title="t('payroll.increments.no_lines')" :text="t('payroll.increments.no_lines_text')" compact>
                    <AppButton :to="{ name: 'payroll-employees' }">{{ t('payroll.employees.title') }}</AppButton>
                </EmptyState>
            </section>

            <!-- Wide screens: a table. -->
            <section v-else class="card hidden overflow-x-auto md:block print:block">
                <table class="w-full text-[13.5px]">
                    <thead class="border-b border-line text-[12px] text-muted">
                        <tr>
                            <th class="px-5 py-2.5 text-start font-medium">{{ t('payroll.increments.columns.employee') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('payroll.increments.columns.band') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('payroll.increments.columns.now') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('payroll.increments.columns.rate') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('payroll.increments.columns.new') }}</th>
                            <th class="px-3 py-2.5 text-end font-medium">{{ t('payroll.increments.columns.raise') }}</th>
                            <th class="px-5 py-2.5 print:hidden"><span class="sr-only">{{ t('payroll.increments.change') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="line in lines" :key="line.id" :class="line.excluded ? 'opacity-60' : ''">
                            <td class="px-5 py-2.5">
                                <span class="block font-medium">{{ line.employee_name }}</span>
                                <span class="block font-mono text-[11.5px] text-muted" dir="ltr">{{ line.employee_code }}</span>
                                <span v-if="line.problem" class="mt-0.5 block text-[12px] text-bad">{{ t(`payroll.increments.problems.${line.problem}`) }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-fg-2">{{ bandName(line.band) }}</td>
                            <td class="tabular px-3 py-2.5 text-end">{{ money(line.current_minor) }}</td>
                            <td class="px-3 py-2.5 text-end">
                                <span class="tabular block">{{ percentText(line.rate_bp) }}%</span>
                                <span class="block text-[11.5px] text-muted">{{ sourceText(line) }}</span>
                            </td>
                            <td class="tabular px-3 py-2.5 text-end font-semibold">{{ money(line.new_minor) }}</td>
                            <td class="tabular px-3 py-2.5 text-end text-ok">{{ line.new_minor > line.current_minor ? `+${money(line.new_minor - line.current_minor)}` : '' }}</td>
                            <td class="px-5 py-2.5 text-end print:hidden">
                                <AppButton v-if="round.can?.change" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('payroll.increments.change_title', { name: line.employee_name })" @click="edit(line)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Phones: a list. -->
            <ul v-if="(round.lines ?? []).length" class="card divide-y divide-line md:hidden print:hidden">
                <li v-for="line in lines" :key="line.id" class="flex items-center gap-3 px-4 py-3" :class="line.excluded ? 'opacity-60' : ''">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ line.employee_name }}</span>
                        <span class="block text-[12.5px] text-muted">{{ bandName(line.band) }} · {{ percentText(line.rate_bp) }}% · {{ sourceText(line) }}</span>
                        <span class="tabular block text-[12.5px]">{{ money(line.current_minor) }} → <b>{{ money(line.new_minor) }}</b></span>
                        <span v-if="line.problem" class="block text-[12px] text-bad">{{ t(`payroll.increments.problems.${line.problem}`) }}</span>
                    </span>
                    <AppButton v-if="round.can?.change" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('payroll.increments.change_title', { name: line.employee_name })" @click="edit(line)" />
                </li>
            </ul>
        </template>

        <AppDialog :open="editing !== null" :title="editing ? t('payroll.increments.change_title', { name: editing.employee_name }) : ''" :icon="TrendingUp" @close="editing = null">
            <form id="payroll-increment-line" class="grid gap-4" novalidate @submit.prevent="saveLine(false)">
                <AppSwitch v-model="form.excluded" :label="t('payroll.increments.leave_out')" show-label />
                <template v-if="!form.excluded">
                    <AppSegmented v-model="form.how" size="sm" :label="t('payroll.increments.how')"
                        :options="[{ value: 'rate', label: t('payroll.increments.how_rate') }, { value: 'amount', label: t('payroll.increments.how_amount') }]" />
                    <AppField v-if="form.how === 'rate'" v-slot="{ id }" :label="t('payroll.increments.rate')" :error="errors.rate_bp?.[0]">
                        <input :id="id" v-model="form.percent" inputmode="decimal" class="field-input tabular text-end sm:w-40" dir="ltr" placeholder="%" />
                    </AppField>
                    <AppField v-else v-slot="{ id }" :label="t('payroll.increments.new_basic')" :error="errors.new_minor?.[0]">
                        <MoneyInput :id="id" v-model="form.new_minor" :currency="round?.currency" class="sm:w-56" />
                    </AppField>
                    <p v-if="preview !== null" class="tabular text-[13px] text-fg-2" aria-live="polite">{{ t('payroll.increments.preview', { amount: money(preview) }) }}</p>
                    <AppField v-slot="{ id }" :label="t('payroll.increments.reason')" :hint="t('payroll.increments.reason_hint')" :error="errors.reason?.[0]">
                        <input :id="id" v-model="form.reason" class="field-input" maxlength="300" />
                    </AppField>
                </template>
            </form>
            <template #footer>
                <AppButton v-if="editing?.rate_source === 'hand'" variant="ghost" :icon="X" class="me-auto" :loading="saving" @click="saveLine(true)">{{ t('payroll.increments.back_to_rule') }}</AppButton>
                <AppButton variant="ghost" @click="editing = null">{{ t('payroll.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="payroll-increment-line" :loading="saving">{{ t('payroll.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
