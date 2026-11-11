<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { DoorOpen, FilePen, Printer, ReceiptText, Save } from 'lucide-vue-next';
import { AppBadge, AppButton, AppDialog, AppField, DateInput, confirmAction, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The discharge of a stay: what the bill comes to if the patient leaves now
 * (bed days counted as at discharge), the summary as a draft, then
 * "Discharge" (refused while money is due when the rule says so). After
 * discharge: the signed summary, its revisions, print and amend with a reason.
 */
const props = defineProps({
    api: { type: Object, required: true },
    admissionId: { type: String, required: true },
});
const emit = defineEmits(['changed']);
const router = useRouter();
const view = useResource(() => props.api.discharge(props.admissionId));
const data = computed(() => view.data.value?.data ?? null);
const admitted = computed(() => data.value?.admission.status === 'admitted');
const signed = computed(() => data.value?.summary?.status === 'signed');
const money = (amount) => formatMoney({ amount, currency: data.value?.currency });
const fields = ['diagnosis', 'treatment', 'advice'];

const blank = () => ({ diagnosis: '', treatment: '', advice: '', follow_up_on: '', outcome: '' });
const form = reactive(blank());
watch(data, (value) => {
    const summary = value?.summary;
    Object.assign(form, blank(), Object.fromEntries(Object.entries(summary ?? {}).filter(([key]) => key in form).map(([key, item]) => [key, item ?? ''])));
}, { immediate: true });
const errors = ref({});
const busy = ref(false);
const payload = () => Object.fromEntries(Object.entries(form).map(([key, value]) => [key, String(value).trim() || null]));

async function run(action) {
    busy.value = true;
    errors.value = {};
    try {
        await action();
        view.reload();
        emit('changed');
        return true;
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.data?.due_minor !== undefined ? `${error.message} (${money(error.data.due_minor)})` : error.message);
        return false;
    } finally {
        busy.value = false;
    }
}
const save = () => run(async () => {
    const summary = data.value.summary;
    await props.api.saveDischarge(props.admissionId, { ...payload(), ...(summary ? { base_version: summary.version } : {}) });
    toast.success(t('inpatient.discharge.saved'));
});
async function discharge() {
    const yes = await confirmAction({ title: t('inpatient.discharge.confirm_title', { number: data.value.admission.number }), message: t('inpatient.discharge.confirm_text'), confirmLabel: t('inpatient.discharge.discharge') });
    if (!yes) return;
    await run(async () => {
        await props.api.finishDischarge(props.admissionId, { base_version: data.value.admission.version });
        toast.success(t('inpatient.discharge.done'));
    });
}

const amending = ref(false);
const reason = ref('');
function startAmend() {
    reason.value = '';
    errors.value = {};
    amending.value = true;
}
async function amend() {
    const done = await run(async () => {
        await props.api.amendDischarge(props.admissionId, { ...payload(), reason: reason.value.trim() });
        toast.success(t('inpatient.discharge.amended'));
    });
    if (done) amending.value = false;
}
const ready = computed(() => form.diagnosis.trim().length > 0);
</script>

<template>
    <div v-if="data" class="grid gap-4">
        <section v-if="admitted && data.preview" class="card grid gap-3 p-4 sm:grid-cols-3">
            <div><p class="text-[12px] text-muted">{{ t('inpatient.discharge.bed_days') }}</p><p class="text-[15px] font-semibold tabular">{{ data.preview.bed_days }}</p></div>
            <div>
                <p class="text-[12px] text-muted">{{ t('inpatient.discharge.final_due') }}</p>
                <p class="text-[15px] font-semibold tabular" :class="data.preview.due_minor > 0 && 'text-bad'">{{ money(data.preview.due_minor) }}</p>
                <p v-if="data.preview.advance_minor > 0" class="text-[12px] text-muted">{{ t('inpatient.discharge.advance', { amount: money(data.preview.advance_minor) }) }}</p>
            </div>
            <div class="flex flex-col items-start gap-1">
                <AppBadge :tone="data.preview.due_minor > 0 && data.preview.clearance_required ? 'warn' : 'ok'" dot>
                    {{ data.preview.due_minor > 0 && data.preview.clearance_required ? t('inpatient.discharge.blocked') : t('inpatient.discharge.clear') }}
                </AppBadge>
                <AppButton v-if="data.admission.bill_id" size="sm" variant="ghost" :icon="ReceiptText" @click="router.push({ name: 'patient-billing-bill', params: { id: data.admission.bill_id } })">{{ t('inpatient.admission.open_bill') }}</AppButton>
            </div>
        </section>

        <section class="card grid gap-3 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-[13.5px] font-semibold">{{ t('inpatient.discharge.summary') }}</h2>
                <AppBadge v-if="data.summary" :tone="signed ? 'ok' : 'neutral'">{{ signed ? t('inpatient.discharge.signed', { at: formatDateTime(data.summary.signed_at) }) : t('inpatient.discharge.draft') }}</AppBadge>
            </div>
            <template v-if="admitted && data.can.discharge">
                <AppField v-for="field in fields" :key="field" v-slot="{ id }" :label="t(`inpatient.discharge.${field}`)" :error="errors[field]?.[0]">
                    <textarea :id="id" v-model="form[field]" rows="3" class="field-input" maxlength="4000" />
                </AppField>
                <div class="grid gap-3 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('inpatient.discharge.follow_up_on')" :error="errors.follow_up_on?.[0]"><DateInput :id="id" v-model="form.follow_up_on" not-past /></AppField>
                    <AppField v-slot="{ id }" :label="t('inpatient.discharge.outcome')" :error="errors.outcome?.[0]">
                        <select :id="id" v-model="form.outcome" class="field-input">
                            <option value="">—</option>
                            <option v-for="value in data.outcomes" :key="value" :value="value">{{ t(`inpatient.outcome.${value}`) }}</option>
                        </select>
                    </AppField>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <AppButton :icon="Save" :loading="busy" @click="save">{{ t('inpatient.discharge.save') }}</AppButton>
                    <AppButton variant="primary" :icon="DoorOpen" :loading="busy" :disabled="!data.summary || !ready" @click="discharge">{{ t('inpatient.discharge.discharge') }}</AppButton>
                </div>
            </template>
            <dl v-else-if="data.summary" class="grid gap-2 text-[13.5px]">
                <div v-for="field in fields" :key="field" v-show="data.summary[field]"><dt class="text-[12px] text-muted">{{ t(`inpatient.discharge.${field}`) }}</dt><dd class="whitespace-pre-line">{{ data.summary[field] }}</dd></div>
                <div v-if="data.summary.outcome"><dt class="text-[12px] text-muted">{{ t('inpatient.discharge.outcome') }}</dt><dd>{{ t(`inpatient.outcome.${data.summary.outcome}`) }}</dd></div>
            </dl>
            <p v-else class="text-[13.5px] text-muted">{{ t('inpatient.discharge.none') }}</p>
            <div v-if="signed" class="flex flex-wrap justify-end gap-2">
                <AppButton v-if="data.can.discharge" :icon="FilePen" @click="startAmend">{{ t('inpatient.discharge.amend') }}</AppButton>
                <AppButton variant="primary" :icon="Printer" @click="router.push({ name: 'inpatient-discharge-paper', params: { id: admissionId } })">{{ t('inpatient.discharge.print') }}</AppButton>
            </div>
        </section>

        <section v-if="data.revisions.length > 1" class="card">
            <h2 class="border-b border-line px-4 py-2.5 text-[13.5px] font-semibold">{{ t('inpatient.discharge.revisions') }}</h2>
            <ul class="divide-y divide-line text-[13px]">
                <li v-for="item in data.revisions" :key="item.revision" class="px-4 py-2">
                    <p>{{ t('inpatient.discharge.revision', { n: item.revision }) }} · {{ formatDateTime(item.signed_at) }}<template v-if="item.superseded"> · {{ t('inpatient.discharge.superseded') }}</template></p>
                    <p v-if="item.amend_reason" class="text-muted">{{ item.amend_reason }}</p>
                </li>
            </ul>
        </section>

        <AppDialog :open="amending" :title="t('inpatient.discharge.amend')" :description="t('inpatient.discharge.amend_text')" :icon="FilePen" @close="amending = false">
            <div class="grid gap-3">
                <AppField v-for="field in fields" :key="field" v-slot="{ id }" :label="t(`inpatient.discharge.${field}`)" :error="errors[field]?.[0]">
                    <textarea :id="id" v-model="form[field]" rows="3" class="field-input" maxlength="4000" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('inpatient.discharge.follow_up_on')"><DateInput :id="id" v-model="form.follow_up_on" /></AppField>
                <AppField v-slot="{ id }" :label="t('inpatient.discharge.outcome')">
                    <select :id="id" v-model="form.outcome" class="field-input">
                        <option value="">—</option>
                        <option v-for="value in data.outcomes" :key="value" :value="value">{{ t(`inpatient.outcome.${value}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('inpatient.discharge.amend_reason')" :error="errors.reason?.[0]"><input :id="id" v-model="reason" class="field-input" maxlength="300" /></AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="amending = false">{{ t('inpatient.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!ready || reason.trim().length < 3" @click="amend">{{ t('inpatient.discharge.amend') }}</AppButton>
            </template>
        </AppDialog>
    </div>
    <p v-else-if="view.error.value" class="card p-4 text-[13.5px] text-bad">{{ view.error.value.message }}</p>
    <p v-else class="card p-4 text-[13.5px] text-muted">{{ t('inpatient.care.loading') }}</p>
</template>
