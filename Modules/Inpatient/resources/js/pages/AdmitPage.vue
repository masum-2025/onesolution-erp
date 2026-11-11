<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { BedDouble, ReceiptText, Search, UserPlus } from 'lucide-vue-next';
import { AppButton, AppField, PageFrame, newOpId, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { ipdApi } from '../api';

/**
 * Admitting: find the patient, pick the attending doctor and a free bed, see
 * the advance on their bill (and what the rule asks for), then admit. The
 * first bed day goes on the bill; one tap admits once (op id).
 */
const ipd = ipdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const board = useResource(() => ipd.board());
const options = useResource(() => ipd.options(patient.value?.id));
const freeBeds = computed(() => (board.data.value?.data ?? []).flatMap((ward) => ward.beds.filter((bed) => bed.status === 'free').map((bed) => ({ ...bed, ward: ward.name }))));
const info = computed(() => options.data.value?.data ?? {});
const money = (amount) => formatMoney({ amount, currency: info.value.currency });

const patient = ref(null);
const term = ref('');
const found = ref([]);
let timer = null;
watch(term, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        found.value = [];
        return;
    }
    timer = setTimeout(async () => {
        found.value = (await ipd.patients(value.trim()).catch((error) => {
            toast.error(error.message);
            return { data: [] };
        })).data;
    }, 300);
});
watch(patient, () => options.reload());

const form = reactive({ bed_id: typeof route.query.bed === 'string' ? route.query.bed : '', doctor_user_id: '', reason: '', guardian_name: '', guardian_mobile: '' });
const errors = ref({});
const short = computed(() => info.value.min_advance_minor && (info.value.advance_minor ?? 0) < info.value.min_advance_minor);
const saving = ref(false);
let opId = newOpId();
async function admit() {
    saving.value = true;
    errors.value = {};
    try {
        const admission = (await ipd.admit({
            patient_id: patient.value.id, bed_id: form.bed_id, doctor_user_id: form.doctor_user_id, reason: form.reason.trim() || null,
            guardian_name: form.guardian_name.trim() || null, guardian_mobile: form.guardian_mobile.trim() || null, op_id: opId,
        })).data;
        opId = newOpId();
        toast.success(t('inpatient.admit.done', { number: admission.number, bed: admission.bed?.code ?? '' }));
        router.push({ name: 'inpatient-admission', params: { id: admission.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
        board.reload();
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageFrame :title="t('inpatient.admit.title')" :description="t('inpatient.admit.text')" width="lg" guide="inpatient.guide.admit"
               :guide-settings="{ name: 'module-settings', params: { module: 'inpatient' } }">
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="card p-4">
                <h2 class="mb-3 text-[14px] font-semibold">{{ t('inpatient.admit.step_patient') }}</h2>
                <div v-if="patient" class="flex items-center justify-between rounded-lg bg-subtle px-3 py-2 text-[13.5px]">
                    <span><span class="font-medium">{{ patient.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ patient.mrn }}</span></span>
                    <button type="button" class="text-[12.5px] font-medium text-accent" @click="patient = null">{{ t('inpatient.admit.change') }}</button>
                </div>
                <div v-else class="grid gap-2">
                    <div class="relative">
                        <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                        <input v-model="term" type="search" class="field-input ps-9" :placeholder="t('inpatient.admit.find')" :aria-label="t('inpatient.admit.find')" />
                    </div>
                    <ul v-if="found.length" class="max-h-56 divide-y divide-line overflow-auto rounded-lg ring-1 ring-line">
                        <li v-for="item in found" :key="item.id">
                            <button type="button" class="w-full px-3 py-2 text-start text-[13.5px] hover:bg-subtle" @click="patient = item">
                                <span class="font-medium">{{ item.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ item.mrn }}</span><template v-if="item.mobile"> · <span dir="ltr">{{ item.mobile }}</span></template>
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="term.trim().length >= 2" class="text-[12.5px] text-muted">{{ t('inpatient.admit.no_patient') }}</p>
                    <AppButton variant="ghost" size="sm" :icon="UserPlus" class="justify-self-start" @click="router.push({ name: 'patients-find' })">{{ t('inpatient.admit.register') }}</AppButton>
                </div>
                <div v-if="patient && info.currency" class="mt-3 rounded-lg px-3 py-2 text-[13px]" :class="short ? 'bg-warn-soft' : 'bg-subtle'" role="status">
                    <p>{{ t('inpatient.admit.advance', { amount: money(info.advance_minor ?? 0) }) }}</p>
                    <p v-if="info.min_advance_minor">{{ t('inpatient.admit.advance_needed', { amount: money(info.min_advance_minor) }) }}</p>
                    <AppButton v-if="short" size="sm" variant="ghost" :icon="ReceiptText" class="mt-1" @click="router.push({ name: 'patient-billing-bills' })">{{ t('inpatient.admit.take_advance') }}</AppButton>
                </div>
            </section>

            <section class="card grid content-start gap-3 p-4">
                <h2 class="text-[14px] font-semibold">{{ t('inpatient.admit.step_bed') }}</h2>
                <AppField v-slot="{ id }" :label="t('inpatient.admit.bed')" :error="errors.bed_id?.[0]">
                    <select :id="id" v-model="form.bed_id" class="field-input">
                        <option value="">{{ freeBeds.length ? t('inpatient.admit.pick_bed') : t('inpatient.admit.no_free_bed') }}</option>
                        <option v-for="bed in freeBeds" :key="bed.id" :value="bed.id">{{ bed.ward }} · {{ bed.code }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('inpatient.admit.doctor')" :error="errors.doctor_user_id?.[0]">
                    <select :id="id" v-model="form.doctor_user_id" class="field-input">
                        <option value="">{{ t('inpatient.admit.pick_doctor') }}</option>
                        <option v-for="doctor in info.doctors ?? []" :key="doctor.id" :value="doctor.id">{{ doctor.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('inpatient.admit.reason')" :error="errors.reason?.[0]"><input :id="id" v-model="form.reason" class="field-input" maxlength="500" /></AppField>
                <div class="grid gap-3 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('inpatient.admit.guardian')"><input :id="id" v-model="form.guardian_name" class="field-input" maxlength="100" /></AppField>
                    <AppField v-slot="{ id }" :label="t('inpatient.admit.guardian_mobile')" :error="errors.guardian_mobile?.[0]"><input :id="id" v-model="form.guardian_mobile" class="field-input" inputmode="tel" dir="ltr" maxlength="20" /></AppField>
                </div>
                <AppButton variant="primary" :icon="BedDouble" :loading="saving" :disabled="!patient || !form.bed_id || !form.doctor_user_id" class="justify-self-end" @click="admit">{{ t('inpatient.admit.admit') }}</AppButton>
            </section>
        </div>
    </PageFrame>
</template>
