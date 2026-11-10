<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Eye, FlaskConical, GitMerge, History, IdCard, Pencil, ReceiptText, TriangleAlert, UserX } from 'lucide-vue-next';
import { AppButton, AppField, EmptyState, PageFrame } from '@/ui';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { patientsApi } from '../api';
import PatientForm from '../components/PatientForm.vue';

/**
 * One patient: details (allergies first, in red), the history from every
 * department, and the actions the person may take: change, print the card,
 * ask to merge a duplicate, see who opened the record. Opening this page is
 * written down on the server (privacy).
 */
const pt = patientsApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useResource(() => pt.setup());
const info = computed(() => setup.data.value?.data ?? {});
const can = computed(() => info.value.can ?? {});
const record = useResource(() => pt.patient(route.params.id));
const history = useResource(() => pt.timeline(route.params.id));
const patient = computed(() => record.data.value?.data ?? null);
const entries = computed(() => history.data.value?.data?.entries ?? []);
watch(() => route.params.id, (id) => {
    if (!id) return;
    record.reload();
    history.reload();
});
// A record merged into another opens that one.
watch(patient, (value) => {
    if (value?.merged_into_id) router.replace({ name: 'patients-patient', params: { id: value.merged_into_id } });
});

const facts = computed(() => {
    const p = patient.value;
    if (!p) return [];
    return [
        [t('patients.list.mrn'), p.mrn, true],
        [t('patients.list.sex'), t(`patients.sex.${p.sex}`)],
        [t('patients.list.age'), p.dob_estimated ? t('patients.patient.age_estimated', { count: p.age }) : `${t('patients.common.years', { count: p.age })} · ${formatDate(p.date_of_birth)}`],
        [t('patients.list.mobile'), p.mobile, true],
        [t('patients.form.blood_group'), p.blood_group, true],
        [t('patients.form.guardian_name'), p.guardian_name ? `${p.guardian_name}${p.guardian_relation ? ` (${p.guardian_relation})` : ''}` : null],
        [t('patients.form.id_number'), p.id_number ? `${t(`patients.id_type.${p.id_type}`)} · ${p.id_number}` : null, true],
        [t('patients.form.address'), p.address],
    ].filter(([, value]) => value !== null && value !== undefined && value !== '');
});

// Changing
const editing = ref(false);
const editForm = ref(null);
const errors = ref({});
const saving = ref(false);
async function save(body) {
    if (!Object.keys(body).length) {
        editing.value = false;
        return;
    }
    saving.value = true;
    errors.value = {};
    try {
        await pt.update(patient.value.id, { ...body, base_version: patient.value.version });
        toast.success(t('patients.patient.saved'));
        editing.value = false;
        record.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
        if (error.code === 'version_conflict') record.reload();
    } finally {
        saving.value = false;
    }
}

// Merging into another record
const merging = ref(false);
const mergeTerm = ref('');
const mergeFound = ref([]);
const mergeInto = ref(null);
const mergeReason = ref('');
async function findTarget() {
    const found = await pt.search({ q: mergeTerm.value.trim() }).catch((error) => {
        toast.error(error.message);
        return { data: [] };
    });
    mergeFound.value = (found.data ?? []).filter((row) => row.id !== patient.value.id);
}
async function askMerge() {
    try {
        await pt.askMerge(patient.value.id, { into_id: mergeInto.value.id, reason: mergeReason.value.trim() });
        toast.success(t('patients.merge.asked'));
        merging.value = false;
        mergeFound.value = [];
        mergeInto.value = null;
        mergeReason.value = '';
    } catch (error) {
        toast.error(error.message);
    }
}

// Who looked
const looking = ref(false);
const looks = ref([]);
async function showViews() {
    looking.value = true;
    looks.value = (await pt.views(patient.value.id).catch((error) => {
        toast.error(error.message);
        return { data: [] };
    })).data;
}
</script>

<template>
    <PageFrame :title="patient ? patient.name : ''" :description="patient?.name_local ?? ''" width="lg" :resource="record" :not-found="{ icon: UserX, title: t('patients.patient.not_found') }" guide="patients.guide.history">
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'patients-find' })">{{ t('patients.patient.back') }}</AppButton>
        </template>
        <template v-if="patient" #actions>
            <AppButton :icon="IdCard" @click="router.push({ name: 'patients-card', params: { id: patient.id } })">{{ t('patients.patient.card') }}</AppButton>
            <AppButton v-if="router.hasRoute('patient-billing-bills')" :icon="ReceiptText" @click="router.push({ name: 'patient-billing-bills', query: { patient: patient.id } })">{{ t('patients.patient.bills') }}</AppButton>
            <AppButton v-if="router.hasRoute('diagnostics-orders')" :icon="FlaskConical" @click="router.push({ name: 'diagnostics-orders', query: { patient: patient.id } })">{{ t('patients.patient.lab') }}</AppButton>
            <AppButton v-if="can.manage" :icon="Pencil" @click="editing = true">{{ t('patients.patient.edit') }}</AppButton>
            <AppButton v-if="can.manage" variant="ghost" :icon="GitMerge" @click="merging = true">{{ t('patients.merge.ask') }}</AppButton>
            <AppButton v-if="can.audit" variant="ghost" :icon="Eye" @click="showViews">{{ t('patients.patient.views') }}</AppButton>
        </template>

        <template v-if="patient">
            <p v-if="patient.allergies" class="mb-4 flex items-start gap-2 rounded-xl bg-bad/10 px-4 py-3 text-[14px] text-bad" role="note">
                <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span><strong>{{ t('patients.form.allergies') }}:</strong> {{ patient.allergies }}</span>
            </p>
            <div class="grid gap-4 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]">
                <section class="card p-5">
                    <dl class="grid gap-3">
                        <div v-for="[label, value, ltr] in facts" :key="label">
                            <dt class="text-[12px] text-muted">{{ label }}</dt>
                            <dd class="text-[14px] font-medium" :dir="ltr ? 'ltr' : undefined" :class="ltr && 'text-start'">{{ value }}</dd>
                        </div>
                    </dl>
                </section>
                <section class="card">
                    <h2 class="flex items-center gap-2 border-b border-line px-5 py-3 text-[14px] font-semibold"><History class="size-4 text-muted" aria-hidden="true" />{{ t('patients.patient.history') }}</h2>
                    <div v-if="history.loading.value && !entries.length" class="p-5 text-[13px] text-muted">{{ t('patients.common.loading') }}</div>
                    <EmptyState v-else-if="!entries.length" :icon="History" :title="t('patients.patient.history_empty')" compact />
                    <ol v-else class="divide-y divide-line">
                        <li v-for="entry in entries" :key="entry.id" class="flex gap-3 px-5 py-3">
                            <span class="w-28 shrink-0 text-[12px] text-muted tabular">{{ formatDateTime(entry.happened_at) }}</span>
                            <span class="min-w-0 flex-1 text-[13.5px]">{{ entry.title }}<span class="block text-[11.5px] text-muted">{{ t(`patients.sources.${entry.source_module}`) }}</span></span>
                        </li>
                    </ol>
                </section>
            </div>
        </template>

        <AppDialog :open="editing" size="lg" :title="t('patients.patient.edit')" :icon="Pencil" @close="editing = false">
            <PatientForm id="patients-edit" ref="editForm" :patient="patient" :options="info.options ?? {}" :errors="errors" @submit="save" />
            <template #footer>
                <AppButton variant="ghost" @click="editing = false">{{ t('patients.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="patients-edit" :loading="saving" :disabled="!editForm?.ready">{{ t('patients.patient.save') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="merging" size="lg" :title="t('patients.merge.ask')" :description="t('patients.merge.ask_text')" :icon="GitMerge" @close="merging = false">
            <div class="grid gap-3">
                <form class="flex gap-2" @submit.prevent="findTarget">
                    <input v-model="mergeTerm" type="search" class="field-input flex-1" :placeholder="t('patients.find.search')" :aria-label="t('patients.find.search')" />
                    <AppButton type="submit">{{ t('patients.merge.find') }}</AppButton>
                </form>
                <ul v-if="mergeFound.length" class="max-h-56 divide-y divide-line overflow-auto rounded-lg ring-1 ring-line">
                    <li v-for="row in mergeFound" :key="row.id">
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-[13.5px]">
                            <input v-model="mergeInto" type="radio" name="merge-into" :value="row" />
                            <span><span class="font-medium">{{ row.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ row.mrn }}</span> · {{ t('patients.common.years', { count: row.age ?? '—' }) }}</span>
                        </label>
                    </li>
                </ul>
                <p v-if="mergeInto" class="text-[13px]">{{ t('patients.merge.summary', { from: patient.mrn, into: mergeInto.mrn }) }}</p>
                <AppField v-slot="{ id }" :label="t('patients.merge.reason')" :hint="t('patients.merge.reason_hint')">
                    <input :id="id" v-model="mergeReason" class="field-input" maxlength="300" />
                </AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="merging = false">{{ t('patients.common.cancel') }}</AppButton>
                <AppButton variant="primary" :disabled="!mergeInto || mergeReason.trim().length < 5" @click="askMerge">{{ t('patients.merge.send') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="looking" size="lg" :title="t('patients.patient.views')" :description="t('patients.patient.views_text')" :icon="Eye" @close="looking = false">
            <ul class="divide-y divide-line">
                <li v-for="look in looks" :key="look.id" class="flex justify-between gap-3 py-2 text-[13px]">
                    <span>{{ look.user_name ?? '—' }} · {{ t(`patients.purpose.${look.purpose}`) }}</span>
                    <span class="text-muted tabular">{{ formatDateTime(look.viewed_at) }}</span>
                </li>
            </ul>
        </AppDialog>
    </PageFrame>
</template>
