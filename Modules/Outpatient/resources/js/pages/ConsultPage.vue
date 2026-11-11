<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, CheckCircle2, FilePenLine, FlaskConical, LayoutTemplate, Printer, Save, Stethoscope } from 'lucide-vue-next';
import { AppBadge, AppButton, AppField, DateInput, PageFrame, confirmAction, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { opdApi } from '../api';
import LinesEditor from '../components/LinesEditor.vue';

/**
 * Seeing a patient: the patient and earlier visits on one side, what the
 * doctor writes on the other (complaints, findings, diagnosis, medicines,
 * tests, advice, follow-up). Saved as a draft with its version; "Finish and
 * print" makes it final. A finished one is read only: "Amend" (with a reason)
 * starts the next revision; lab tests go to the lab once.
 */
const opd = opdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => opd.consultation(route.params.id));
const data = computed(() => view.data.value?.data ?? null);
const options = computed(() => data.value?.options ?? { pharmacy: false, lab: false, tests: [], timings: [], templates: [] });
const findings = ['bp', 'pulse', 'temperature', 'weight', 'spo2'];
const empty = () => ({ complaints: '', findings: { bp: '', pulse: '', temperature: '', weight: '', spo2: '', other: '' }, diagnosis: '', advice: '', follow_up_on: '', medicines: [], tests: [] });
const form = reactive(empty());
const version = ref(null);
const errors = ref({});
const dirty = ref(false);

watch(data, (value) => {
    const current = value?.consultation;
    Object.assign(form, empty(), current ? {
        complaints: current.complaints ?? '', diagnosis: current.diagnosis ?? '', advice: current.advice ?? '', follow_up_on: current.follow_up_on ?? '',
        findings: { ...empty().findings, ...current.findings },
        medicines: current.medicines.map((row) => ({ ...row, dose: row.dose ?? '', duration: row.duration ?? '', timing: row.timing ?? '', note: row.note ?? '' })),
        tests: current.tests.map((row) => ({ ...row })),
    } : {});
    version.value = current?.version ?? null;
    dirty.value = false;
}, { immediate: true });
watch(form, () => { dirty.value = true; }, { deep: true });

const finished = computed(() => data.value?.consultation?.status === 'finished');
const serial = computed(() => data.value?.serial ?? null);
const blank = (value) => (value === '' ? null : value);
const body = () => ({
    base_version: version.value, complaints: blank(form.complaints.trim()), diagnosis: blank(form.diagnosis.trim()), advice: blank(form.advice.trim()), follow_up_on: blank(form.follow_up_on),
    findings: Object.fromEntries(Object.entries(form.findings).map(([key, value]) => [key, blank(String(value).trim())])),
    medicines: form.medicines.filter((row) => row.medicine_text.trim()).map((row) => ({ ...row, dose: blank(row.dose), duration: blank(row.duration), timing: blank(row.timing), note: blank(row.note) })),
    tests: form.tests.filter((row) => row.test_text.trim()),
});

const busy = ref('');
async function save(quiet = false) {
    busy.value = 'save';
    errors.value = {};
    try {
        const saved = (await opd.saveConsultation(route.params.id, body())).data;
        version.value = saved.version;
        dirty.value = false;
        if (!quiet) toast.success(t('outpatient.consult.saved'));
        return true;
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
        if (error.status === 409) view.reload();
        return false;
    } finally {
        busy.value = '';
    }
}
async function finish() {
    if (!(await save(true))) return;
    busy.value = 'finish';
    try {
        await opd.finish(route.params.id, { base_version: version.value });
        toast.success(t('outpatient.consult.finished'));
        router.push({ name: 'outpatient-prescription', params: { id: route.params.id } });
    } catch (error) {
        toast.error(error.message);
        view.reload();
    } finally {
        busy.value = '';
    }
}
async function amend() {
    const confirmed = await confirmAction({ title: t('outpatient.consult.amend_title'), message: t('outpatient.consult.amend_text'), confirmLabel: t('outpatient.consult.amend'), reason: 'required' });
    if (!confirmed) return;
    try {
        await opd.amend(route.params.id, { reason: confirmed.reason });
        view.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
async function sendTests() {
    busy.value = 'tests';
    try {
        await opd.sendTests(route.params.id);
        toast.success(t('outpatient.consult.tests_sent'));
        view.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = '';
    }
}
function useTemplate(template) {
    const lines = template.body ?? {};
    form.medicines.push(...(lines.medicines ?? []).map((row) => ({ medicine_id: row.medicine_id ?? null, medicine_text: row.medicine_text, dose: row.dose ?? '', duration: row.duration ?? '', timing: row.timing ?? '', note: row.note ?? '' })));
    form.tests.push(...(lines.tests ?? []).map((row) => ({ test_code: row.test_code ?? null, test_text: row.test_text })));
    if (lines.advice) form.advice = form.advice ? `${form.advice}\n${lines.advice}` : lines.advice;
    toast.success(t('outpatient.consult.template_used', { name: template.name }));
}
const labCodes = computed(() => (data.value?.consultation?.tests ?? []).filter((row) => row.test_code).length);
const search = async (q) => (await opd.medicines(q)).data;
</script>

<template>
    <PageFrame :title="serial ? t('outpatient.consult.title', { name: serial.patient?.name ?? '' }) : ''" width="xl" :resource="view"
               :not-found="{ icon: Stethoscope, title: t('outpatient.slip.not_found') }" guide="outpatient.guide.consult">
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" @click="router.push({ name: 'outpatient-mine' })">{{ t('outpatient.consult.back') }}</AppButton>
        </template>
        <template v-if="data" #eyebrow>
            <span>{{ t('outpatient.serials.number') }} {{ serial.serial_no }} · {{ formatDate(serial.date) }} · {{ serial.doctor?.name }}</span>
            <AppBadge v-if="data.consultation" class="ms-2" :tone="finished ? 'ok' : 'warn'">{{ t(`outpatient.consult.status.${data.consultation.status}`) }}<template v-if="data.consultation.revision > 1"> · {{ t('outpatient.consult.revision', { n: data.consultation.revision }) }}</template></AppBadge>
        </template>
        <template v-if="data" #actions>
            <template v-if="!finished">
                <AppButton variant="ghost" :icon="Save" :loading="busy === 'save'" :disabled="!!busy" @click="save()">{{ t('outpatient.consult.save') }}</AppButton>
                <AppButton variant="primary" :icon="CheckCircle2" :loading="busy === 'finish'" :disabled="!!busy" @click="finish">{{ t('outpatient.consult.finish') }}</AppButton>
            </template>
            <template v-else>
                <AppButton v-if="options.lab && labCodes && !data.consultation.lab_order_id" variant="ghost" :icon="FlaskConical" :loading="busy === 'tests'" @click="sendTests">{{ t('outpatient.consult.send_tests', { count: labCodes }) }}</AppButton>
                <AppButton variant="ghost" :icon="FilePenLine" @click="amend">{{ t('outpatient.consult.amend') }}</AppButton>
                <AppButton variant="primary" :icon="Printer" @click="router.push({ name: 'outpatient-prescription', params: { id: route.params.id } })">{{ t('outpatient.consult.print') }}</AppButton>
            </template>
        </template>

        <div v-if="data" class="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <aside class="grid content-start gap-4">
                <section class="card p-4 text-[13.5px]">
                    <p class="text-[15px] font-semibold">{{ serial.patient?.name }}</p>
                    <p class="text-muted"><span class="font-mono" dir="ltr">{{ serial.patient?.mrn }}</span> · {{ t('outpatient.common.years', { count: serial.patient?.age ?? '—' }) }} · {{ t(`outpatient.sex.${serial.patient?.sex ?? 'other'}`) }}</p>
                    <p class="mt-1 text-muted">{{ t(`outpatient.kind.${serial.kind}`) }}</p>
                    <AppButton size="sm" variant="ghost" class="mt-2" @click="router.push({ name: 'patients-patient', params: { id: serial.patient.id } })">{{ t('outpatient.consult.open_record') }}</AppButton>
                </section>
                <section class="card p-4">
                    <h2 class="mb-2 text-[13.5px] font-semibold">{{ t('outpatient.consult.history') }}</h2>
                    <p v-if="!data.history.length" class="text-[12.5px] text-muted">{{ t('outpatient.consult.no_history') }}</p>
                    <ul v-else class="grid gap-2 text-[13px]">
                        <li v-for="visit in data.history" :key="visit.serial_id">
                            <p class="font-medium">{{ formatDate(visit.date) }} · {{ visit.doctor }}</p>
                            <p class="text-muted">{{ visit.diagnosis || '—' }}</p>
                        </li>
                    </ul>
                </section>
                <section v-if="!finished && options.templates.length" class="card p-4">
                    <h2 class="mb-2 flex items-center gap-2 text-[13.5px] font-semibold"><LayoutTemplate class="size-4 text-muted" aria-hidden="true" />{{ t('outpatient.consult.templates') }}</h2>
                    <div class="flex flex-wrap gap-1.5">
                        <AppButton v-for="template in options.templates" :key="template.id" size="sm" variant="secondary" @click="useTemplate(template)">{{ template.name }}</AppButton>
                    </div>
                </section>
                <section v-if="data.revisions.length > 1" class="card p-4 text-[12.5px]">
                    <h2 class="mb-2 text-[13.5px] font-semibold">{{ t('outpatient.consult.revisions') }}</h2>
                    <ul class="grid gap-1">
                        <li v-for="item in data.revisions" :key="item.revision">{{ t('outpatient.consult.revision', { n: item.revision }) }} · {{ t(`outpatient.consult.status.${item.status}`) }}<template v-if="item.superseded"> · {{ t('outpatient.consult.superseded') }}</template><template v-if="item.amend_reason"> · {{ item.amend_reason }}</template></li>
                    </ul>
                </section>
            </aside>

            <section class="card grid content-start gap-4 p-4">
                <p v-if="data.consultation?.amend_reason && !finished" class="rounded-lg bg-warn-soft px-3 py-2 text-[13px]">{{ t('outpatient.consult.amending', { reason: data.consultation.amend_reason }) }}</p>
                <AppField v-slot="{ id }" :label="t('outpatient.consult.complaints')" :error="errors.complaints?.[0]">
                    <textarea :id="id" v-model="form.complaints" class="field-input min-h-16" maxlength="2000" :disabled="finished" />
                </AppField>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-[13px] font-medium">{{ t('outpatient.consult.findings') }}</legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                        <AppField v-for="key in findings" :key="key" v-slot="{ id }" :label="t(`outpatient.finding.${key}`)" :error="errors[`findings.${key}`]?.[0]">
                            <input :id="id" v-model="form.findings[key]" class="field-input" maxlength="30" dir="ltr" :disabled="finished" />
                        </AppField>
                    </div>
                    <AppField v-slot="{ id }" :label="t('outpatient.finding.other')"><input :id="id" v-model="form.findings.other" class="field-input" maxlength="500" :disabled="finished" /></AppField>
                </fieldset>
                <AppField v-slot="{ id }" :label="t('outpatient.consult.diagnosis')" :error="errors.diagnosis?.[0]">
                    <textarea :id="id" v-model="form.diagnosis" class="field-input min-h-12" maxlength="1000" :disabled="finished" />
                </AppField>
                <LinesEditor :medicines="form.medicines" :tests="form.tests" :options="options" :search="options.pharmacy ? search : null" :errors="errors" :disabled="finished" />
                <AppField v-slot="{ id }" :label="t('outpatient.consult.advice')" :error="errors.advice?.[0]">
                    <textarea :id="id" v-model="form.advice" class="field-input min-h-16" maxlength="2000" :disabled="finished" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('outpatient.consult.follow_up')" class="max-w-xs"><DateInput :id="id" v-model="form.follow_up_on" not-past :disabled="finished" /></AppField>
                <p v-if="!finished && dirty" class="text-[12px] text-muted" role="status">{{ t('outpatient.consult.unsaved') }}</p>
                <p v-if="finished && data.consultation.lab_order_id" class="text-[12.5px] text-muted">{{ t('outpatient.consult.tests_at_lab') }}</p>
            </section>
        </div>
    </PageFrame>
</template>
